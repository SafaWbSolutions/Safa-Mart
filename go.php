<?php
require_once __DIR__ . '/config.php';
security_headers();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Vary: User-Agent, Cookie');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$id = (int) ($_GET['id'] ?? 0);
$fallback = site_url('index.php');
if ($id < 1) {
    header('Location: ' . $fallback, true, 302);
    exit;
}

$product = find_product_by_id($id);
if ($product === null) {
    app_log('info', 'Affiliate redirect skipped: product not found', ['id' => $id]);
    header('Location: ' . $fallback, true, 302);
    exit;
}

$affiliateUrl = trim((string) ($product['affiliate_url'] ?? ''));
$parts = parse_url($affiliateUrl);
if (!$parts || empty($parts['scheme']) || empty($parts['host'])
    || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
    app_log('error', 'Affiliate redirect skipped: invalid URL', ['id' => $id]);
    header('Location: ' . $fallback, true, 302);
    exit;
}

$clickResult = supabase_rpc('increment_product_click', ['product_id' => $id], false, 5);
if (!$clickResult['ok']) {
    app_log('warning', 'Click counter RPC failed', [
        'id'     => $id,
        'status' => $clickResult['status'],
        'error'  => $clickResult['error'],
    ]);
}

$platform = trim((string) ($product['source_platform'] ?? ''));
if ($platform === '') {
    $platform = detect_platform($affiliateUrl);
}

// Android phones: try the merchant app first, fall back to the affiliate link
// (website) when the app is missing. Everything else keeps the plain 302.
//
// The bridge marks a store when the app did not open, so repeat visits go
// straight to the website instead of showing the "opening app" screen again.
$appMissingKey = merchant_app_platform_key($platform);
if (merchant_app_package($platform) !== ''
    && is_android_mobile_request()
    && ($appMissingKey === '' || !isset($_COOKIE['safa_noapp_' . $appMissingKey]))) {
    render_merchant_app_bridge($product, $affiliateUrl, $platform);
    exit;
}

header('Location: ' . $affiliateUrl, true, 302);
exit;
