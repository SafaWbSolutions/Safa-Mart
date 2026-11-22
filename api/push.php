<?php
/**
 * Push subscription API.
 *
 * GET  ?action=config      -> public VAPID key + categories
 * POST  action=subscribe   -> { subscription: {...}, topics: [] }
 * POST  action=unsubscribe -> { endpoint: "https://..." }
 * POST  action=test        -> { endpoint: "https://..." }
 * POST  action=announce    -> X-Api-Secret; announces products added since
 *                             the previous announcement (GitHub sync only)
 *
 * No account, no cookies: a row is stored against the browser's push
 * endpoint and removed the moment the browser unsubscribes.
 */

require_once dirname(__DIR__) . '/config.php';
security_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$action = strtolower(trim((string) ($_GET['action'] ?? 'config')));

if ($action === 'config') {
    $keys = push_vapid_keys();
    json_response([
        'ok'        => !empty($keys['public_key']),
        'publicKey' => (string) ($keys['public_key'] ?? ''),
        'topics'    => push_topics_allowed(),
        'enabled'   => push_is_configured(),
    ]);
}

/**
 * POST ?action=announce — called by the GitHub Actions product sync with the
 * X-Api-Secret header (same secret as uploads). Announces only products that
 * were added since the previous announcement, so every scheduled run does not
 * spam subscribers.
 */
if ($action === 'announce') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'error' => 'POST required'], 405);
    }
    $secret = (string) ($_SERVER['HTTP_X_API_SECRET'] ?? '');
    if (UPLOAD_API_SECRET === '' || $secret === '' || !hash_equals(UPLOAD_API_SECRET, $secret)) {
        json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
    if (!push_is_configured()) {
        json_response(['ok' => false, 'error' => 'Push is not configured on the server yet.'], 503);
    }
    if (!supabase_is_configured()) {
        json_response(['ok' => false, 'error' => 'Catalogue is not configured.'], 503);
    }

    $products = fetch_storefront_products(['sort' => 'newest'], 12);
    if (!$products) {
        json_response(['ok' => true, 'action' => 'announce', 'new' => 0, 'reason' => 'empty catalogue']);
    }

    $latestId = 0;
    foreach ($products as $product) {
        $latestId = max($latestId, (int) ($product['id'] ?? 0));
    }

    $markerFile = push_storage_path('push-last-announce.json');
    $marker = is_file($markerFile) ? json_decode((string) @file_get_contents($markerFile), true) : null;
    $lastId = is_array($marker) ? (int) ($marker['latest_id'] ?? 0) : 0;

    if ($lastId <= 0) {
        // First run: announce only the single newest product.
        $fresh = array_slice($products, 0, 1);
    } else {
        $fresh = array_values(array_filter($products, static function ($product) use ($lastId): bool {
            return (int) ($product['id'] ?? 0) > $lastId;
        }));
    }

    if (!$fresh) {
        json_response([
            'ok'        => true,
            'action'    => 'announce',
            'new'       => 0,
            'latest_id' => $lastId,
            'reason'    => 'no new products since last announcement',
        ]);
    }

    $result = push_announce_new_products($fresh);

    // Keep the marker when the 10-minute throttle swallowed the burst, so the
    // next run can still announce these products.
    $throttled = $result['sent'] === 0 && $result['failed'] === 0 && $result['skipped'] > 0;
    if (!$throttled && $latestId > 0) {
        $json = json_encode(['latest_id' => $latestId, 'at' => time()], JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($markerFile, $json, LOCK_EX);
        }
    }

    json_response([
        'ok'        => true,
        'action'    => 'announce',
        'new'       => count($fresh),
        'latest_id' => $latestId,
        'throttled' => $throttled,
        'result'    => $result,
    ]);
}

/**
 * POST ?action=announce-drops — called by GitHub Actions to send the
 * biggest tracked price drop. Uses the same secret as uploads.
 */
if ($action === 'announce-drops') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'error' => 'POST required'], 405);
    }
    $secret = (string) ($_SERVER['HTTP_X_API_SECRET'] ?? '');
    if (UPLOAD_API_SECRET === '' || $secret === '' || !hash_equals(UPLOAD_API_SECRET, $secret)) {
        json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
    if (!push_is_configured()) {
        json_response(['ok' => false, 'error' => 'Push is not configured on the server yet.'], 503);
    }
    if (!supabase_is_configured()) {
        json_response(['ok' => false, 'error' => 'Catalogue is not configured.'], 503);
    }

    $result = push_announce_price_drops();

    json_response([
        'ok'     => true,
        'action' => 'announce-drops',
        'result' => $result,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

if (!push_same_origin_request()) {
    json_response(['ok' => false, 'error' => 'Origin not allowed'], 403);
}

if (!rate_limit_hit('push:' . client_ip(), 60, 600)) {
    json_response(['ok' => false, 'error' => 'Too many requests. Try again later.'], 429);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 16384) {
    json_response(['ok' => false, 'error' => 'Invalid payload'], 400);
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

if ($action === 'subscribe') {
    if (!push_is_configured()) {
        json_response(['ok' => false, 'error' => 'Push is not configured on the server yet.'], 503);
    }
    $validated = push_validate_subscription($payload['subscription'] ?? $payload);
    if (!$validated['ok'] || $validated['subscription'] === null) {
        json_response(['ok' => false, 'error' => $validated['error']], 400);
    }
    $topics = push_normalize_topics($payload['topics'] ?? []);
    $id = push_subscribe($validated['subscription'], $topics, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    json_response(['ok' => true, 'id' => $id, 'topics' => $topics]);
}

if ($action === 'unsubscribe') {
    $endpoint = trim((string) ($payload['endpoint'] ?? ''));
    if ($endpoint === '') {
        json_response(['ok' => false, 'error' => 'Endpoint missing'], 400);
    }
    push_unsubscribe($endpoint);
    json_response(['ok' => true]);
}

if ($action === 'test') {
    $endpoint = trim((string) ($payload['endpoint'] ?? ''));
    if (!push_endpoint_allowed($endpoint)) {
        json_response(['ok' => false, 'error' => 'Unknown endpoint'], 400);
    }
    if (!rate_limit_hit('push-test:' . client_ip(), 5, 600)) {
        json_response(['ok' => false, 'error' => 'Test limit reached. Try again in a few minutes.'], 429);
    }
    $row = push_subscriptions_load()[substr(sha1($endpoint), 0, 20)] ?? null;
    if (!is_array($row)) {
        json_response(['ok' => false, 'error' => 'Enable alerts first.'], 404);
    }

    $payload = push_default_payload();
    // Preserve extra fields from push_payload_for_product, only override test-specific ones
    $payload['title'] = 'Safa Mart test alert';
    $payload['body'] = 'Alerts are working on this device. New deals will arrive like this.';
    $payload['tag'] = 'safa-test';
    // Add test-specific discount/price for rich notification
    $payload['discount'] = 50;
    $payload['price'] = 499;
    $payload['mrp'] = 999;
    $payload['platform'] = 'Test';

    $result = push_send_one(
        $endpoint,
        (string) ($row['p256dh'] ?? ''),
        (string) ($row['auth'] ?? ''),
        $payload
    );
    $stats = push_stats();
    json_response([
        'ok'        => $result['ok'],
        'status'    => $result['status'],
        'encrypted' => (bool) ($result['encrypted'] ?? false),
        'error'     => $result['ok'] ? '' : ($result['error'] !== '' ? $result['error'] : 'The push service rejected the test.'),
        'stats'     => ['total' => $stats['total'], 'active' => $stats['active']],
    ]);
}

json_response(['ok' => false, 'error' => 'Unknown action'], 404);

function push_same_origin_request(): bool
{
    $site = parse_url(site_url());
    $siteHost = strtolower((string) ($site['host'] ?? ''));
    if ($siteHost === '') {
        return true;
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            $origin = isset($parts['scheme'], $parts['host'])
                ? strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host'])
                : '';
        }
    }
    if ($origin === '') {
        return true;
    }

    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    return $originHost !== '' && $originHost === $siteHost;
}
