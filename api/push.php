<?php
/**
 * Push subscription API.
 *
 * GET  ?action=config      -> public VAPID key + categories
 * POST  action=subscribe   -> { subscription: {...}, topics: [] }
 * POST  action=unsubscribe -> { endpoint: "https://..." }
 * POST  action=test        -> { endpoint: "https://..." }
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
    $exists = isset(push_subscriptions_load()[substr(sha1($endpoint), 0, 20)]);
    if (!$exists) {
        json_response(['ok' => false, 'error' => 'Enable alerts first.'], 404);
    }
    $result = push_send_one($endpoint);
    $stats = push_stats();
    json_response([
        'ok'     => $result['ok'],
        'status' => $result['status'],
        'error'  => $result['ok'] ? '' : ($result['error'] !== '' ? $result['error'] : 'The push service rejected the test.'),
        'stats'  => ['total' => $stats['total'], 'active' => $stats['active']],
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
