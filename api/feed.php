<?php
/**
 * Authenticated fetch proxy for the GitHub Actions scraper.
 *
 * GitHub runners sit outside India, where Myntra and a few other stores serve
 * a stripped page with no product links. This endpoint lets the workflow fetch
 * merchant HTML through the web server instead, so the pages look the same as
 * they do from the storefront.
 *
 * Requires the X-Safa-Secret header to match UPLOAD_API_SECRET. Only public
 * merchant URLs are fetched (no internal hosts, no arbitrary URLs).
 */

require_once dirname(__DIR__) . '/config.php';
security_headers();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$secret = (string) ($_SERVER['HTTP_X_SAFA_SECRET'] ?? '');
if (UPLOAD_API_SECRET === '' || $secret === '' || !hash_equals(UPLOAD_API_SECRET, $secret)) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 8192) {
    json_response(['ok' => false, 'error' => 'Invalid payload'], 400);
}
$payload = json_decode($rawBody, true);
$url = is_array($payload) ? trim((string) ($payload['url'] ?? '')) : '';

if ($url === '' || !preg_match('#^https?://#i', $url)) {
    json_response(['ok' => false, 'error' => 'url is required'], 400);
}
if (!is_safe_remote_url($url) || detect_platform($url) === 'Other') {
    json_response(['ok' => false, 'error' => 'URL is not allowed'], 400);
}

$response = http_request($url, [
    'timeout'   => 25,
    'max_bytes' => 4194304,
    'accept'    => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
    'referer'   => origin_of_url($url),
]);

if (!$response['ok']) {
    app_log('warning', 'Feed proxy fetch failed', [
        'url'    => $url,
        'status' => (int) $response['status'],
        'error'  => (string) ($response['error'] ?? ''),
    ]);
}

$body = ensure_utf8((string) ($response['body'] ?? ''), (string) ($response['headers']['content-type'] ?? ''));
if ($body !== '' && !preg_match('//u', $body)) {
    if (function_exists('mb_convert_encoding')) {
        $body = (string) @mb_convert_encoding($body, 'UTF-8', 'UTF-8');
    } elseif (function_exists('iconv')) {
        $body = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $body);
    }
}

json_response([
    'ok'            => (bool) $response['ok'],
    'status'        => (int) $response['status'],
    'effective_url' => (string) ($response['effective_url'] ?? $url),
    'body'          => $body,
    'error'         => (string) ($response['error'] ?? ''),
]);
