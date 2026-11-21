<?php
/**
 * POST /api/recommend.php
 *
 * Body: { current: {...}, views: [...], queries: [...], exclude: [ids] }
 * Returns rendered product cards for the "picked for you" grids.
 */

require_once dirname(__DIR__) . '/config.php';
security_headers();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

if (!AI_RECOMMENDATIONS) {
    json_response(['ok' => true, 'count' => 0, 'html' => '']);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 32768) {
    json_response(['ok' => false, 'error' => 'Invalid payload'], 400);
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

if (!rate_limit_hit('recommend:' . client_ip(), 120, 600)) {
    json_response(['ok' => false, 'error' => 'Too many requests'], 429);
}

$cleanProduct = static function ($row): ?array {
    if (!is_array($row)) {
        return null;
    }
    $id = (int) ($row['id'] ?? 0);
    if ($id < 1) {
        return null;
    }
    return [
        'id'       => $id,
        'title'    => truncate_text(clean_text((string) ($row['title'] ?? '')), 160),
        'category' => clean_text((string) ($row['category'] ?? '')),
        'platform' => clean_text((string) ($row['platform'] ?? '')),
        'brand'    => clean_text((string) ($row['brand'] ?? '')),
        'price'    => max(0, (float) ($row['price'] ?? 0)),
    ];
};

$current = $cleanProduct($payload['current'] ?? null);
$views = [];
foreach (array_slice(is_array($payload['views'] ?? null) ? $payload['views'] : [], 0, 10) as $view) {
    $clean = $cleanProduct($view);
    if ($clean !== null) {
        $views[] = $clean;
    }
}
$queries = [];
foreach (array_slice(is_array($payload['queries'] ?? null) ? $payload['queries'] : [], 0, 6) as $query) {
    $query = clean_search_term((string) $query);
    if ($query !== '') {
        $queries[] = $query;
    }
}
$exclude = [];
foreach (array_slice(is_array($payload['exclude'] ?? null) ? $payload['exclude'] : [], 0, 20) as $id) {
    $exclude[] = (int) $id;
}

$rows = recommend_products([
    'current' => $current,
    'views'   => $views,
    'queries' => $queries,
    'exclude' => $exclude,
], 8);

$html = '';
foreach ($rows as $row) {
    $html .= render_product_card($row);
}

json_response([
    'ok'    => true,
    'count' => count($rows),
    'html'  => $html,
]);
