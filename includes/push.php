<?php
/**
 * Web Push (VAPID) helpers.
 *
 * Push messages are sent as a "tickle" with no encrypted payload. The service
 * worker wakes up, calls /api/alerts.php and shows the newest deal. That keeps
 * this file dependency-free (no Composer, no GMP) and still works on Chrome,
 * Edge, Firefox and Safari 16.4+.
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
 */
function push_subscribe(array $subscription, array $topics, string $userAgent = ''): string
{
    $endpoint = (string) ($subscription['endpoint'] ?? '');
    $id = substr(sha1($endpoint), 0, 20);

    push_with_lock(static function () use ($subscription, $topics, $id, $userAgent, $endpoint): void {
        $all = push_subscriptions_load();
        $existing = is_array($all[$id] ?? null) ? $all[$id] : [];
        $all[$id] = [
            'endpoint' => $endpoint,
            'p256dh'   => (string) $subscription['p256dh'],
            'auth'     => (string) $subscription['auth'],
            'topics'   => push_normalize_topics($topics),
            'ua'       => truncate_text(clean_text($userAgent), 140),
            'created'  => (int) ($existing['created'] ?? time()),
            'updated'  => time(),
            'ok'       => (int) ($existing['ok'] ?? 0),
            'fails'    => 0,
            'last_ok'  => (int) ($existing['last_ok'] ?? 0),
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
 * Send one tickle push.
 *
 * @return array{ok:bool,status:int,gone:bool,error:string}
 */
function push_send_one(string $endpoint): array
{
    $token = push_vapid_token($endpoint);
    $publicKey = push_public_key();
    if ($token === '' || $publicKey === '') {
        return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => 'VAPID signing failed.'];
    }

    $response = http_request($endpoint, [
        'method'    => 'POST',
        'body'      => '',
        'timeout'   => 8,
        'max_bytes' => 8192,
        'accept'    => '*/*',
        'headers'   => [
            'Authorization: vapid t=' . $token . ', k=' . $publicKey,
            'TTL: 86400',
            'Urgency: normal',
            'Accept: */*',
        ],
    ]);

    $status = (int) ($response['status'] ?? 0);
    $ok = (bool) ($response['ok'] ?? false) && $status >= 200 && $status < 300;
    $gone = in_array($status, [404, 410], true);

    if (!$ok) {
        app_log('info', 'Push delivery failed', [
            'status' => $status,
            'error'  => (string) ($response['error'] ?? ''),
            'host'   => (string) parse_url($endpoint, PHP_URL_HOST),
        ]);
    }

    return [
        'ok'     => $ok,
        'status' => $status,
        'gone'   => $gone,
        'error'  => (string) ($response['error'] ?? ''),
    ];
}

/**
 * Fan out one notification to every subscriber (or only subscribers who chose
 * the given category). Returns counters for logging/Telegram.
 *
 * @return array{sent:int,failed:int,removed:int,skipped:int}
 */
function push_broadcast(string $category = '', int $limit = 0): array
{
    $result = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0];
    if (!push_is_configured()) {
        return $result;
    }

    $subs = push_subscriptions_load();
    if (!$subs) {
        return $result;
    }

    $max = $limit > 0 ? $limit : (defined('PUSH_MAX_PER_RUN') ? PUSH_MAX_PER_RUN : 150);
    $removedIds = [];
    $updates = [];
    $networkFailures = 0;
    $now = time();

    // Network calls happen without holding the file lock, so a slow push
    // service never blocks a new visitor from subscribing.
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

        $send = push_send_one((string) $row['endpoint']);
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

    if ($result['sent'] > 0 || $result['failed'] > 0) {
        app_log('info', 'Push broadcast finished', $result + ['category' => $category]);
    }

    return $result;
}

/**
 * Turn freshly listed products into one push burst. Only the highest-discount
 * product is announced (the worker fetches its live price from the API).
 *
 * @param list<array<string,mixed>> $products
 */
function push_announce_new_products(array $products): array
{
    if (!$products) {
        return ['sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0];
    }

    // Avoid spamming: one burst per 10 minutes even if Telegram lists a lot.
    $throttleFile = push_storage_path('push-last-burst.txt');
    $last = is_file($throttleFile) ? (int) @file_get_contents($throttleFile) : 0;
    if ($last > 0 && time() - $last < 600) {
        return ['sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => count($products)];
    }
    @file_put_contents($throttleFile, (string) time(), LOCK_EX);

    $bestCategory = '';
    $bestDiscount = -1;
    foreach ($products as $product) {
        $discount = (int) ($product['discount_percent'] ?? 0);
        if ($discount > $bestDiscount) {
            $bestDiscount = $discount;
            $bestCategory = (string) ($product['category'] ?? '');
        }
    }

    // A mixed batch (no single category) goes to everybody.
    if (count($products) > 1) {
        $categories = [];
        foreach ($products as $product) {
            $categories[(string) ($product['category'] ?? '')] = true;
        }
        if (count($categories) > 1) {
            $bestCategory = '';
        }
    }

    return push_broadcast($bestCategory);
}
