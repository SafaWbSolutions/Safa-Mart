<?php
/**
 * Web Push (VAPID) helpers.
 *
 * Notifications carry a real encrypted payload (RFC 8291, aes128gcm) so the
 * phone can show the deal even when it never gets a chance to fetch
 * /api/alerts.php. Empty "tickle" pushes are still supported as a fallback:
 * if encryption is unavailable the worker wakes up and fetches the newest
 * deal itself. Everything is pure PHP + OpenSSL (no Composer, no GMP).
 *
 * Private data (VAPID key, subscriptions) lives in /storage which is denied by
 * .htaccess and never committed.
 */

function push_storage_dir(): string
{
    $dir = SAFA_ROOT . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function push_storage_path(string $name): string
{
    return push_storage_dir() . '/' . $name;
}

function push_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function push_b64url_decode(string $data): string
{
    $data = strtr(trim($data), '-_', '+/');
    $padding = strlen($data) % 4;
    if ($padding > 0) {
        $data .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode($data, true);
    return $decoded === false ? '' : $decoded;
}

/**
 * Wrap a raw 65-byte uncompressed P-256 point in a PEM public key so OpenSSL
 * can import it for ECDH.
 */
function push_public_key_pem(string $rawPublicKey): string
{
    if (strlen($rawPublicKey) !== 65 || ($rawPublicKey[0] ?? '') !== "\x04") {
        return '';
    }
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $rawPublicKey;
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/** Create a P-256 key pair, retrying with a system openssl.cnf when needed. */
function push_openssl_key_new()
{
    $options = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ];
    $resource = @openssl_pkey_new($options);
    if ($resource !== false) {
        return $resource;
    }
    foreach ([
        SAFA_ROOT . '/openssl.cnf',
        '/etc/ssl/openssl.cnf',
        '/usr/local/ssl/openssl.cnf',
        '/etc/pki/tls/openssl.cnf',
    ] as $configFile) {
        if (!is_file($configFile)) {
            continue;
        }
        $resource = @openssl_pkey_new($options + ['config' => $configFile]);
        if ($resource !== false) {
            return $resource;
        }
    }
    return false;
}

/** Raw 65-byte public point for an OpenSSL EC key. */
function push_openssl_public_point($resource): string
{
    $details = @openssl_pkey_get_details($resource);
    if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
        return '';
    }
    $x = str_pad(substr((string) $details['ec']['x'], -32), 32, "\0", STR_PAD_LEFT);
    $y = str_pad(substr((string) $details['ec']['y'], -32), 32, "\0", STR_PAD_LEFT);
    return "\x04" . $x . $y;
}

/**
 * Encrypt a JSON payload for one browser subscription using RFC 8291
 * aes128gcm. Returns the raw request body, or '' when the keys are unusable.
 */
function push_encrypt_payload(string $p256dh, string $auth, string $plaintext): string
{
    if ($plaintext === '' || strlen($plaintext) > 3500) {
        return '';
    }
    if (!function_exists('openssl_pkey_derive') || !function_exists('hash_hkdf')) {
        return '';
    }

    $userPublic = push_b64url_decode($p256dh);
    $authSecret = push_b64url_decode($auth);
    if (strlen($userPublic) !== 65 || ($userPublic[0] ?? '') !== "\x04" || strlen($authSecret) !== 16) {
        return '';
    }

    $peer = @openssl_pkey_get_public(push_public_key_pem($userPublic));
    if ($peer === false) {
        return '';
    }
    $local = push_openssl_key_new();
    if ($local === false) {
        return '';
    }
    $applicationPublic = push_openssl_public_point($local);
    $shared = @openssl_pkey_derive($peer, $local, 32);
    if (!is_string($shared) || strlen($shared) !== 32 || $applicationPublic === '') {
        return '';
    }

    $salt = random_bytes(16);
    // RFC 8291: IKM = HKDF(auth_secret, ecdh_secret, "WebPush: info" | ua_pub | as_pub)
    $ikm = hash_hkdf(
        'sha256',
        $shared,
        32,
        "WebPush: info\x00" . $userPublic . $applicationPublic,
        $authSecret
    );
    $contentKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    $tag = '';
    $ciphertext = @openssl_encrypt(
        $plaintext . "\x02",
        'aes-128-gcm',
        $contentKey,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        '',
        16
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        return '';
    }

    // Header: salt (16) | record size (4, big endian) | key id length (1) | key id
    return $salt . pack('N', 4096) . chr(strlen($applicationPublic)) . $applicationPublic . $ciphertext . $tag;
}

/**
 * Load (or create once) the VAPID key pair.
 *
 * @return array{private_pem:string,public_key:string,created:int}
 */
function push_vapid_keys(): array
{
    static $keys = null;
    if (is_array($keys)) {
        return $keys;
    }

    $file = push_storage_path('vapid.json');
    if (is_file($file)) {
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (is_array($decoded) && !empty($decoded['private_pem']) && !empty($decoded['public_key'])) {
            $keys = [
                'private_pem' => (string) $decoded['private_pem'],
                'public_key'  => (string) $decoded['public_key'],
                'created'     => (int) ($decoded['created'] ?? time()),
            ];
            return $keys;
        }
    }

    if (!function_exists('openssl_pkey_new')) {
        return [];
    }

    $options = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ];
    $resource = @openssl_pkey_new($options);
    if ($resource === false) {
        // Some PHP builds ship without a default openssl.cnf. Try the usual
        // system locations (and a project-local one) before giving up.
        foreach ([
            SAFA_ROOT . '/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/local/ssl/openssl.cnf',
            '/etc/pki/tls/openssl.cnf',
        ] as $configFile) {
            if (!is_file($configFile)) {
                continue;
            }
            $resource = @openssl_pkey_new($options + ['config' => $configFile]);
            if ($resource !== false) {
                break;
            }
        }
    }
    if ($resource === false) {
        app_log('error', 'Push: VAPID key generation failed');
        return [];
    }

    $details = openssl_pkey_get_details($resource);
    $x = (string) ($details['ec']['x'] ?? '');
    $y = (string) ($details['ec']['y'] ?? '');
    if (strlen($x) === 0 || strlen($y) === 0) {
        app_log('error', 'Push: VAPID public key export failed');
        return [];
    }

    // openssl returns fixed-width coordinates, but be defensive.
    $x = str_pad(substr($x, -32), 32, "\0", STR_PAD_LEFT);
    $y = str_pad(substr($y, -32), 32, "\0", STR_PAD_LEFT);

    $pem = '';
    if (!@openssl_pkey_export($resource, $pem) || $pem === '') {
        app_log('error', 'Push: VAPID private key export failed');
        return [];
    }

    $keys = [
        'private_pem' => $pem,
        'public_key'  => push_b64url_encode("\x04" . $x . $y),
        'created'     => time(),
    ];

    $json = json_encode($keys, JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
    }

    return $keys;
}

function push_is_configured(): bool
{
    $keys = push_vapid_keys();
    return !empty($keys['private_pem']) && !empty($keys['public_key']);
}

function push_public_key(): string
{
    $keys = push_vapid_keys();
    return (string) ($keys['public_key'] ?? '');
}

function push_vapid_subject(): string
{
    $contact = trim((string) safa_config_value('PUSH_CONTACT', ''));
    if ($contact !== '' && (preg_match('#^mailto:#i', $contact) || preg_match('#^https://#i', $contact))) {
        return $contact;
    }
    return rtrim(site_url(), '/');
}

/**
 * Turn a DER ECDSA signature into the raw r||s pair required by JWT (ES256).
 */
function push_der_signature_to_raw(string $der): string
{
    $offset = 0;
    if (($der[0] ?? '') !== "\x30") {
        return '';
    }
    $offset = 2;
    if ((ord($der[1]) & 0x80) !== 0) {
        $offset = 2 + (ord($der[1]) & 0x7f);
    }

    $readInteger = static function (string $data, int &$position): ?string {
        if (($data[$position] ?? '') !== "\x02") {
            return null;
        }
        $length = ord($data[$position + 1] ?? "\x00");
        $value = substr($data, $position + 2, $length);
        $position += 2 + $length;
        return $value === false ? null : $value;
    };

    $r = $readInteger($der, $offset);
    $s = $readInteger($der, $offset);
    if ($r === null || $s === null) {
        return '';
    }

    $r = str_pad(substr(ltrim($r, "\x00"), -32), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(substr(ltrim($s, "\x00"), -32), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

function push_endpoint_audience(string $endpoint): string
{
    $parts = parse_url($endpoint);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $audience = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
    if (!empty($parts['port'])) {
        $audience .= ':' . (int) $parts['port'];
    }
    return $audience;
}

/**
 * Build (and cache for the request) an ES256 VAPID token for one push service.
 */
function push_vapid_token(string $endpoint): string
{
    static $cache = [];
    $audience = push_endpoint_audience($endpoint);
    $keys = push_vapid_keys();
    if ($audience === '' || empty($keys['private_pem'])) {
        return '';
    }
    if (isset($cache[$audience]) && $cache[$audience]['exp'] > time() + 120) {
        return $cache[$audience]['token'];
    }

    $header = push_b64url_encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $expires = time() + 12 * 3600;
    $payload = push_b64url_encode((string) json_encode([
        'aud' => $audience,
        'exp' => $expires,
        'sub' => push_vapid_subject(),
    ]));

    $privateKey = @openssl_pkey_get_private($keys['private_pem']);
    if ($privateKey === false) {
        return '';
    }

    $signature = '';
    if (!@openssl_sign($header . '.' . $payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        return '';
    }
    $raw = push_der_signature_to_raw($signature);
    if (strlen($raw) !== 64) {
        return '';
    }

    $token = $header . '.' . $payload . '.' . push_b64url_encode($raw);
    $cache[$audience] = ['token' => $token, 'exp' => $expires];
    return $token;
}

/**
 * Only real push services are accepted, so a forged subscription cannot make
 * the server POST to an internal address.
 */
function push_endpoint_allowed(string $endpoint): bool
{
    if (!preg_match('#^https://#i', $endpoint) || strlen($endpoint) > 1024) {
        return false;
    }
    $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    $allowed = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        'web.push.apple.com',
        'push.apple.com',
    ];
    if (in_array($host, $allowed, true)) {
        return true;
    }

    foreach (['.notify.windows.com', '.wns.windows.com', '.push.apple.com', '.push.services.mozilla.com'] as $suffix) {
        if (str_ends_with($host, $suffix)) {
            return true;
        }
    }

    return false;
}

function push_topics_allowed(): array
{
    return defined('PRODUCT_CATEGORIES') ? PRODUCT_CATEGORIES : [];
}

/** @param mixed $topics @return list<string> */
function push_normalize_topics($topics): array
{
    $allowed = push_topics_allowed();
    $clean = [];
    foreach (is_array($topics) ? $topics : [] as $topic) {
        $topic = clean_text((string) $topic);
        if ($topic !== '' && in_array($topic, $allowed, true) && !in_array($topic, $clean, true)) {
            $clean[] = $topic;
        }
    }
    return array_slice($clean, 0, 10);
}

/** @return array{ok:bool,error:string,subscription:array<string,mixed>|null} */
function push_validate_subscription($input)
{
    if (!is_array($input)) {
        return ['ok' => false, 'error' => 'Subscription missing.', 'subscription' => null];
    }

    $endpoint = trim((string) ($input['endpoint'] ?? ''));
    $keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
    $p256dh = trim((string) ($keys['p256dh'] ?? ''));
    $auth = trim((string) ($keys['auth'] ?? ''));

    if (!push_endpoint_allowed($endpoint)) {
        return ['ok' => false, 'error' => 'Unsupported push endpoint.', 'subscription' => null];
    }
    $publicKey = push_b64url_decode($p256dh);
    $authSecret = push_b64url_decode($auth);
    if (strlen($publicKey) !== 65 || ($publicKey[0] ?? '') !== "\x04") {
        return ['ok' => false, 'error' => 'Invalid subscription key.', 'subscription' => null];
    }
    if (strlen($authSecret) !== 16) {
        return ['ok' => false, 'error' => 'Invalid auth secret.', 'subscription' => null];
    }

    return [
        'ok' => true,
        'error' => '',
        'subscription' => [
            'endpoint' => $endpoint,
            'p256dh'   => $p256dh,
            'auth'     => $auth,
        ],
    ];
}

/** Run a callback while holding an exclusive lock on the subscription store. */
function push_with_lock(callable $callback)
{
    $handle = @fopen(push_storage_path('push.lock'), 'c');
    if ($handle === false) {
        return $callback();
    }
    @flock($handle, LOCK_EX);
    try {
        return $callback();
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/** @return array<string,array<string,mixed>> */
function push_subscriptions_load(): array
{
    $file = push_storage_path('push-subscriptions.json');
    if (!is_file($file)) {
        return [];
    }
    $decoded = json_decode((string) @file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,array<string,mixed>> $subscriptions */
function push_subscriptions_save(array $subscriptions): bool
{
    $file = push_storage_path('push-subscriptions.json');
    $json = json_encode($subscriptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    return @rename($tmp, $file);
}

/**
 * Add or refresh a subscription. Returns the row id.
 *
 * @param array<string,mixed> $subscription
 * @param list<string>        $topics
 * @param string              $userAgent
 * @param string              $digestMode 'instant' or 'daily'
 */
function push_subscribe(array $subscription, array $topics, string $userAgent = '', string $digestMode = 'instant'): string
{
    $endpoint = (string) ($subscription['endpoint'] ?? '');
    $id = substr(sha1($endpoint), 0, 20);

    push_with_lock(static function () use ($subscription, $topics, $id, $userAgent, $endpoint, $digestMode): void {
        $all = push_subscriptions_load();
        $existing = is_array($all[$id] ?? null) ? $all[$id] : [];
        $all[$id] = [
            'endpoint'    => $endpoint,
            'p256dh'      => (string) $subscription['p256dh'],
            'auth'        => (string) $subscription['auth'],
            'topics'      => push_normalize_topics($topics),
            'digest_mode' => in_array($digestMode, ['instant', 'daily'], true) ? $digestMode : 'instant',
            'ua'          => truncate_text(clean_text($userAgent), 140),
            'created'     => (int) ($existing['created'] ?? time()),
            'updated'     => time(),
            'ok'          => (int) ($existing['ok'] ?? 0),
            'fails'       => 0,
            'last_ok'     => (int) ($existing['last_ok'] ?? 0),
        ];
        // Keep the store bounded even if a bot floods it.
        if (count($all) > 5000) {
            uasort($all, static function (array $a, array $b): int {
                return ((int) ($a['updated'] ?? 0)) <=> ((int) ($b['updated'] ?? 0));
            });
            $all = array_slice($all, -4500, null, true);
        }
        push_subscriptions_save($all);
    });

    return $id;
}

function push_unsubscribe(string $endpoint): bool
{
    $id = substr(sha1($endpoint), 0, 20);
    $removed = false;
    push_with_lock(static function () use ($id, &$removed): void {
        $all = push_subscriptions_load();
        if (isset($all[$id])) {
            unset($all[$id]);
            push_subscriptions_save($all);
            $removed = true;
        }
    });
    return $removed;
}

/** @return array{total:int,active:int,failed:int,last_sent:int} */
function push_stats(): array
{
    $all = push_subscriptions_load();
    $active = 0;
    $failed = 0;
    $last = 0;
    foreach ($all as $row) {
        if ((int) ($row['fails'] ?? 0) >= 3) {
            $failed++;
        } else {
            $active++;
        }
        $last = max($last, (int) ($row['last_ok'] ?? 0));
    }
    return ['total' => count($all), 'active' => $active, 'failed' => $failed, 'last_sent' => $last];
}

/**
 * Build the notification object for a product. Only real values are used:
 * no invented urgency and no fake discounts.
 *
 * @param array<string,mixed> $product
 * @return array{title:string,body:string,url:string,image:string,tag:string,discount:int,price:float,mrp:float,platform:string}
 */
function push_payload_for_product(array $product): array
{
    $name = truncate_text(clean_text((string) ($product['title'] ?? '')), 80);
    if ($name === '') {
        $name = 'Fresh deal on Safa Mart';
    }
    $price = (float) ($product['price'] ?? 0);
    $mrp = (float) ($product['mrp'] ?? 0);
    $discount = (int) ($product['discount_percent'] ?? 0);
    if ($discount <= 0 && $price > 0 && $mrp > $price) {
        $discount = (int) round((($mrp - $price) / $mrp) * 100);
    }

    $pieces = [];
    if ($price > 0) {
        $pieces[] = 'Now ₹' . inr_plain($price);
    }
    if ($mrp > $price && $mrp > 0) {
        $pieces[] = 'MRP ₹' . inr_plain($mrp);
    }
    if ($discount > 0) {
        $pieces[] = $discount . '% off';
    }
    $body = $pieces
        ? implode(' · ', $pieces)
        : 'Tap to see the live price on the store page.';

    $image = trim((string) ($product['thumbnail_url'] ?? ''));
    if ($image !== '' && !preg_match('#^https?://#i', $image)) {
        $image = site_url($image);
    }

    $slug = trim((string) ($product['slug'] ?? ''));
    $url = $slug !== ''
        ? site_url('product.php?slug=' . rawurlencode($slug))
        : site_url('index.php');

    $title = $discount > 0
        ? $discount . '% OFF · ' . $name
        : 'New deal · ' . $name;

    return [
        'title'      => truncate_text($title, 120),
        'body'       => truncate_text($body, 180),
        'url'        => $url,
        'image'      => $image,
        'tag'        => 'safa-deal-' . ($slug !== '' ? substr(sha1($slug), 0, 10) : 'new'),
        'discount'   => $discount,
        'price'      => $price,
        'mrp'        => $mrp,
        'platform'   => trim((string) ($product['source_platform'] ?? '')),
    ];
}

/** Newest product from the catalogue, used for test pushes and broadcasts. */
function push_latest_product(): ?array
{
    if (!function_exists('fetch_storefront_products') || !supabase_is_configured()) {
        return null;
    }
    $products = fetch_storefront_products(['sort' => 'newest'], 1);
    $first = $products[0] ?? null;
    return is_array($first) ? $first : null;
}

/**
 * Fallback notification when no specific product is available.
 *
 * @param array<string,mixed>|null $product
 * @return array{title:string,body:string,url:string,image:string,tag:string}
 */
function push_default_payload(?array $product = null): array
{
    $product = $product ?? push_latest_product();
    if (is_array($product)) {
        return push_payload_for_product($product);
    }
    return [
        'title' => 'Safa Mart deals are live',
        'body'  => 'Tap to open today\'s best hand-picked offers.',
        'url'   => site_url('index.php'),
        'image' => site_url('assets/icons/icon-512.png'),
        'tag'   => 'safa-deal',
    ];
}

/**
 * Send one push. With a subscription key pair and a payload the message is
 * encrypted (RFC 8291); otherwise it falls back to a payload-less tickle.
 *
 * @param array<string,mixed> $payload
 * @return array{ok:bool,status:int,gone:bool,error:string,encrypted:bool}
 */
function push_send_one(string $endpoint, string $p256dh = '', string $auth = '', array $payload = []): array
{
    $token = push_vapid_token($endpoint);
    $publicKey = push_public_key();
    if ($token === '' || $publicKey === '') {
        return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => 'VAPID signing failed.', 'encrypted' => false];
    }

    $body = '';
    if ($payload && $p256dh !== '' && $auth !== '') {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && $json !== '') {
            $body = push_encrypt_payload($p256dh, $auth, $json);
        }
    }

    $headers = [
        'Authorization: vapid t=' . $token . ', k=' . $publicKey,
        'TTL: 86400',
        'Urgency: normal',
        'Accept: */*',
    ];
    if ($body !== '') {
        $headers[] = 'Content-Encoding: aes128gcm';
        $headers[] = 'Content-Type: application/octet-stream';
    }

    $response = http_request($endpoint, [
        'method'    => 'POST',
        'body'      => $body,
        'timeout'   => 8,
        'max_bytes' => 8192,
        'accept'    => '*/*',
        'headers'   => $headers,
    ]);

    $status = (int) ($response['status'] ?? 0);
    $ok = (bool) ($response['ok'] ?? false) && $status >= 200 && $status < 300;
    $gone = in_array($status, [404, 410], true);

    if (!$ok) {
        app_log('info', 'Push delivery failed', [
            'status'    => $status,
            'error'     => (string) ($response['error'] ?? ''),
            'encrypted' => $body !== '',
            'host'      => (string) parse_url($endpoint, PHP_URL_HOST),
        ]);
    }

    return [
        'ok'        => $ok,
        'status'    => $status,
        'gone'      => $gone,
        'error'     => (string) ($response['error'] ?? ''),
        'encrypted' => $body !== '',
    ];
}

function push_broadcast(string $category = '', int $limit = 0, array $payload = []): array
{
    $result = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0, 'queued_daily' => 0];
    if (!push_is_configured()) {
        return $result;
    }

    $subs = push_subscriptions_load();
    if (!$subs) {
        return $result;
    }

    $payload = $payload ?: push_default_payload();

    $max = $limit > 0 ? $limit : (defined('PUSH_MAX_PER_RUN') ? PUSH_MAX_PER_RUN : 150);
    $removedIds = [];
    $updates = [];
    $networkFailures = 0;
    $now = time();

    // Separate instant vs daily digest subscribers
    $instantSubs = [];
    $dailySubs = [];
    foreach ($subs as $id => $row) {
        if (!is_array($row) || empty($row['endpoint'])) {
            continue;
        }
        $digestMode = ($row['digest_mode'] ?? 'instant');
        if ($digestMode === 'daily') {
            $dailySubs[$id] = $row;
        } else {
            $instantSubs[$id] = $row;
        }
    }

    // Send instant pushes
    $result = push_broadcast_to_subs($instantSubs, $category, $max, $payload, $result, $now, $removedIds, $updates, $networkFailures);

    // Queue for daily digest (store in daily digest file)
    if ($dailySubs) {
        $queued = queue_daily_digest($dailySubs, $category, $payload);
        $result['queued_daily'] = $queued;
    }

    $result['removed'] = count($removedIds);
    if ($updates || $removedIds) {
        push_with_lock(static function () use ($updates, $removedIds): void {
            $current = push_subscriptions_load();
            foreach ($updates as $id => $patch) {
                if (!isset($current[$id])) {
                    continue;
                }
                if (isset($patch['fails'])) {
                    $current[$id]['fails'] = $patch['fails'];
                }
                if (isset($patch['last_ok'])) {
                    $current[$id]['last_ok'] = $patch['last_ok'];
                    $current[$id]['ok'] = (int) ($current[$id]['ok'] ?? 0) + 1;
                }
            }
            foreach ($removedIds as $id) {
                unset($current[$id]);
            }
            push_subscriptions_save($current);
        });
    }

    if ($result['sent'] > 0 || $result['failed'] > 0 || $result['queued_daily'] > 0) {
        app_log('info', 'Push broadcast finished', $result + ['category' => $category]);
    }

    return $result;
}

/**
 * Send push to a list of subscriptions (used for instant pushes).
 */
function push_broadcast_to_subs(array $subs, string $category, int $max, array $payload, array $result, int $now, array &$removedIds, array &$updates, int &$networkFailures): array
{
    foreach ($subs as $id => $row) {
        if ($result['sent'] + $result['failed'] >= $max) {
            $result['skipped']++;
            continue;
        }
        if ($networkFailures >= 5) {
            $result['skipped']++;
            continue;
        }
        if (!is_array($row) || empty($row['endpoint'])) {
            $removedIds[] = $id;
            continue;
        }
        $topics = is_array($row['topics'] ?? null) ? $row['topics'] : [];
        if ($category !== '' && $topics && !in_array($category, $topics, true)) {
            $result['skipped']++;
            continue;
        }
        if ((int) ($row['fails'] ?? 0) >= 5) {
            $result['skipped']++;
            continue;
        }

        $send = push_send_one(
            (string) $row['endpoint'],
            (string) ($row['p256dh'] ?? ''),
            (string) ($row['auth'] ?? ''),
            $payload
        );
        if ($send['ok']) {
            $result['sent']++;
            $updates[$id] = ['fails' => 0, 'last_ok' => $now];
            continue;
        }

        $result['failed']++;
        if ($send['status'] === 0) {
            $networkFailures++;
        }
        if ($send['gone']) {
            $removedIds[] = $id;
            continue;
        }
        $updates[$id] = ['fails' => (int) ($row['fails'] ?? 0) + 1];
    }
    return $result;
}

/**
 * Queue notifications for daily digest subscribers.
 * Stores in a JSON file to be sent by a daily cron at 9 AM.
 */
function queue_daily_digest(array $subs, string $category, array $payload): int
{
    $file = push_storage_path('daily-digest-queue.json');
    $queue = is_file($file) ? json_decode((string) @file_get_contents($file), true) : [];
    if (!is_array($queue)) {
        $queue = [];
    }

    $queued = 0;
    foreach ($subs as $id => $row) {
        if (!is_array($row) || empty($row['endpoint'])) {
            continue;
        }
        $topics = is_array($row['topics'] ?? null) ? $row['topics'] : [];
        if ($category !== '' && $topics && !in_array($category, $topics, true)) {
            continue;
        }
        if ((int) ($row['fails'] ?? 0) >= 5) {
            continue;
        }

        $queue[] = [
            'endpoint'  => $row['endpoint'],
            'p256dh'    => $row['p256dh'],
            'auth'      => $row['auth'],
            'payload'   => $payload,
            'category'  => $category,
            'queued_at' => time(),
        ];
        $queued++;
    }

    if ($queued > 0) {
        @file_put_contents($file, json_encode($queue, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    return $queued;
}

/**
 * Price-drop payload: previous -> current price, real numbers only.
 *
 * @param array<string,mixed> $drop One row from fetch_recent_price_drops()
 * @return array{title:string,body:string,url:string,image:string,tag:string,discount:int,price:float,mrp:float,platform:string}
 */
function push_price_drop_payload(array $drop): array
{
    $product = is_array($drop['product'] ?? null) ? $drop['product'] : [];
    $name = truncate_text(clean_text((string) ($product['title'] ?? '')), 70);
    if ($name === '') {
        $name = 'a tracked deal';
    }
    $current = (float) ($drop['current'] ?? 0);
    $previous = (float) ($drop['previous'] ?? 0);
    $percent = (int) ($drop['drop_percent'] ?? 0);
    $slug = trim((string) ($product['slug'] ?? ''));

    $body = ($current > 0 && $previous > 0)
        ? '₹' . inr_plain($previous) . ' → ₹' . inr_plain($current) . ($percent > 0 ? ' · ' . $percent . '% kam' : '')
        : 'Price just dropped. Tap to see the live price.';

    return [
        'title'      => truncate_text('📉 Price drop · ' . $name, 120),
        'body'       => truncate_text($body, 180),
        'url'        => $slug !== ''
            ? site_url('product.php?slug=' . rawurlencode($slug))
            : site_url('price-drops.php'),
        'image'      => (string) ($product['thumbnail_url'] ?? ''),
        'tag'        => 'safa-drop-' . ($slug !== '' ? substr(sha1($slug), 0, 10) : 'latest'),
        'discount'   => $percent,
        'price'      => $current,
        'mrp'        => $previous,
        'platform'   => trim((string) ($product['source_platform'] ?? '')),
    ];
}

/**
 * One push burst per 6 hours for the biggest tracked price drop. Never
 * repeats the same drop: the throttle file is the only gate, so a new drop
 * always gets through once the window passes.
 *
 * @return array{sent:int,failed:int,removed:int,skipped:int}
 */
function push_announce_price_drops(): array
{
    $empty = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0];
    if (!push_is_configured() || !supabase_is_configured() || !function_exists('fetch_recent_price_drops')) {
        return $empty;
    }

    $drops = fetch_recent_price_drops(8);
    if (!$drops) {
        return $empty;
    }

    $throttleFile = push_storage_path('push-last-drop.txt');
    $last = is_file($throttleFile) ? (int) @file_get_contents($throttleFile) : 0;
    if ($last > 0 && time() - $last < 21600) {
        $empty['skipped'] = count($drops);
        return $empty;
    }
    @file_put_contents($throttleFile, (string) time(), LOCK_EX);

    $best = $drops[0];
    if (!is_array($best)) {
        return $empty;
    }

    $category = (string) (($best['product'] ?? [])['category'] ?? '');
    return push_broadcast($category, 0, push_price_drop_payload($best));
}

/**
 * Turn freshly listed products into one push burst. Only the highest-discount
 * product is announced so users are not spammed, and its live price is sent
 * inside the encrypted payload.
 *
 * @param list<array<string,mixed>> $products
 * @return array{sent:int,failed:int,removed:int,skipped:int}
 */
function push_announce_new_products(array $products): array
{
    $empty = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0];
    if (!$products) {
        return $empty;
    }

    // Avoid spamming: one burst per 10 minutes even if Telegram lists a lot.
    $throttleFile = push_storage_path('push-last-burst.txt');
    $last = is_file($throttleFile) ? (int) @file_get_contents($throttleFile) : 0;
    if ($last > 0 && time() - $last < 600) {
        $empty['skipped'] = count($products);
        return $empty;
    }
    @file_put_contents($throttleFile, (string) time(), LOCK_EX);

    $best = null;
    $bestDiscount = -1;
    $categories = [];
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $categories[(string) ($product['category'] ?? '')] = true;
        $discount = (int) ($product['discount_percent'] ?? 0);
        if ($discount > $bestDiscount) {
            $bestDiscount = $discount;
            $best = $product;
        }
    }
    if (!is_array($best)) {
        return $empty;
    }

    // A mixed batch (no single category) goes to everybody.
    $category = count($categories) === 1 ? (string) ($best['category'] ?? '') : '';

    return push_broadcast($category, 0, push_payload_for_product($best));
}
