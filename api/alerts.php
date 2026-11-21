<?php
/**
 * GET /api/alerts.php
 *
 * Fresh deal data for the service worker notification and the in-app bell.
 * Deliberately tiny: one best deal + the latest few products.
 */

require_once dirname(__DIR__) . '/config.php';
security_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$context = strtolower(trim((string) ($_GET['context'] ?? 'app')));
$category = clean_text((string) ($_GET['category'] ?? ''));

$filters = ['sort' => 'newest'];
if ($category !== '' && in_array($category, PRODUCT_CATEGORIES, true)) {
    $filters['category'] = $category;
}

$products = supabase_is_configured() ? fetch_storefront_products($filters, 8) : [];
if (!$products) {
    json_response(['ok' => false, 'error' => 'No deals available right now.', 'notification' => null, 'deals' => []]);
}

$best = null;
$bestScore = -1;
foreach ($products as $product) {
    $discount = (int) ($product['discount_percent'] ?? 0);
    $price = (float) ($product['price'] ?? 0);
    $score = $discount + ($price > 0 && $price <= 999 ? 6 : 0);
    if ($score > $bestScore) {
        $bestScore = $score;
        $best = $product;
    }
}

$deals = [];
foreach ($products as $product) {
    $slug = (string) ($product['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $deals[] = [
        'title'    => clean_product_title((string) ($product['title'] ?? 'Deal')),
        'price'    => (float) ($product['price'] ?? 0),
        'mrp'      => (float) ($product['mrp'] ?? 0),
        'discount' => (int) ($product['discount_percent'] ?? 0),
        'platform' => clean_text((string) ($product['source_platform'] ?? '')),
        'category' => clean_text((string) ($product['category'] ?? '')),
        'image'    => (string) ($product['thumbnail_url'] ?? ''),
        'url'      => site_url('product.php?slug=' . rawurlencode($slug)),
    ];
}

$notification = null;
if (is_array($best)) {
    $title = clean_product_title((string) ($best['title'] ?? 'New deal'));
    $price = (float) ($best['price'] ?? 0);
    $discount = (int) ($best['discount_percent'] ?? 0);
    $platform = clean_text((string) ($best['source_platform'] ?? ''));
    $slug = (string) ($best['slug'] ?? '');

    $headline = $discount >= 10
        ? '🔥 ' . $discount . '% off just dropped'
        : '🔥 New deal just dropped';

    $bits = [];
    if ($price > 0) {
        $bits[] = '₹' . inr_plain($price);
    }
    if ($platform !== '') {
        $bits[] = $platform;
    }
    $bits[] = 'Tap to grab it';

    $notification = [
        'title' => $headline,
        'body'  => truncate_text($title, 95) . ' · ' . implode(' · ', $bits),
        'url'   => site_url('product.php?slug=' . rawurlencode($slug)),
        'image' => preg_match('#^https?://#i', (string) ($best['thumbnail_url'] ?? '')) ? (string) $best['thumbnail_url'] : '',
        'tag'   => 'deal-' . ($slug !== '' ? $slug : 'latest'),
    ];
}

json_response([
    'ok'           => true,
    'context'      => $context,
    'generated_at' => gmdate('c'),
    'notification' => $notification,
    'deals'        => $deals,
]);
