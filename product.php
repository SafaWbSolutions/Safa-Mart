<?php
require_once __DIR__ . '/config.php';
security_headers();

$slug = trim((string) ($_GET['slug'] ?? ''));
$product = $slug !== '' ? find_product_by_slug($slug) : null;
if ($product === null) {
    render_product_not_found();
}

$id = (int) ($product['id'] ?? 0);
$title = clean_text($product['title'] ?? 'Product');
if ($title === '') {
    $title = 'Product';
}
$price = (float) ($product['price'] ?? 0);
$mrp = (float) ($product['mrp'] ?? 0);
$discount = (int) ($product['discount_percent'] ?? 0);
if ($discount <= 0) {
    $discount = calculate_discount($mrp, $price);
}
$platform = clean_text($product['source_platform'] ?? 'Store');
if ($platform === '' || $platform === 'Other') {
    $platform = 'Store';
}
$category = clean_text($product['category'] ?? 'Other');
$brand = clean_text((string) ($product['brand'] ?? ''));
$rating = (float) ($product['rating'] ?? 0);
$ratingCount = (int) ($product['rating_count'] ?? 0);
$description = clean_text($product['description'] ?? '');
if ($description === '') {
    $description = $title . ' available at ' . ($price > 0 ? inr($price) : 'the best price')
        . ' on ' . $platform . '. Check the latest price and buy through ' . APP_NAME . '.';
}
$gallery = product_gallery($product);
$primaryImage = $gallery[0] ?? '';
$savings = max(0, $mrp - $price);
$productUrl = site_url('product.php?slug=' . rawurlencode((string) ($product['slug'] ?? '')));
$buyUrl = site_url('go.php?id=' . $id);
$lastChecked = human_date($product['last_synced_at'] ?? ($product['updated_at'] ?? null));
$platformClass = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($platform)) ?? '', '-');
if ($platformClass === '') {
    $platformClass = 'store';
}

$related = fetch_related_products($category, $id, 8);
$shareTitle = $title . ' at ' . ($price > 0 ? inr($price) : 'a great price') . ' on ' . APP_NAME;
$whatsappUrl = 'https://wa.me/?text=' . rawurlencode($shareTitle . ' ' . $productUrl);
$telegramUrl = 'https://t.me/share/url?url=' . rawurlencode($productUrl) . '&text=' . rawurlencode($shareTitle);

$pageTitle = truncate_text($title, 62) . ' – ' . ($price > 0 ? inr($price) : 'Best price') . ' | ' . APP_NAME;
$metaDescription = truncate_text($description, 158);

$schema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $title,
    'description' => truncate_text($description, 900),
    'sku'         => (string) $id,
    'url'         => $productUrl,
    'brand'       => ['@type' => 'Brand', 'name' => $brand !== '' ? $brand : $platform],
    'offers'      => [
        '@type'         => 'Offer',
        'url'           => $buyUrl,
        'priceCurrency' => 'INR',
        'price'         => number_format(max(0, $price), 2, '.', ''),
        'availability'  => 'https://schema.org/InStock',
        'itemCondition' => 'https://schema.org/NewCondition',
        'seller'        => ['@type' => 'Organization', 'name' => APP_NAME],
    ],
];
if ($gallery) {
    $schema['image'] = array_values($gallery);
}
if ($rating > 0) {
    $schema['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => $rating,
        'bestRating'  => 5,
        'reviewCount' => max(1, $ratingCount),
    ];
}
$breadcrumbSchema = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => site_url('index.php')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $category !== '' ? $category : 'Deals', 'item' => site_url('index.php?category=' . rawurlencode($category))],
        ['@type' => 'ListItem', 'position' => 3, 'name' => truncate_text($title, 80), 'item' => $productUrl],
    ],
];
?>
<!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#e91e63">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <link rel="canonical" href="<?= e($productUrl) ?>">
    <meta property="og:type" content="product">
    <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
    <meta property="og:title" content="<?= e(truncate_text($title, 90)) ?>">
    <meta property="og:description" content="<?= e($metaDescription) ?>">
    <meta property="og:url" content="<?= e($productUrl) ?>">
    <meta property="product:price:amount" content="<?= e(number_format(max(0, $price), 2, '.', '')) ?>">
    <meta property="product:price:currency" content="INR">
    <?php if ($primaryImage !== ''): ?>
        <meta property="og:image" content="<?= e($primaryImage) ?>">
        <meta property="og:image:alt" content="<?= e($title) ?>">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="<?= e($primaryImage) ?>">
    <?php else: ?>
        <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <meta name="twitter:title" content="<?= e(truncate_text($title, 90)) ?>">
    <meta name="twitter:description" content="<?= e($metaDescription) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#fff1f6', 100: '#ffe4ed', 200: '#fecddb',
                            300: '#fda4bd', 400: '#fb719b', 500: '#f43f7a',
                            600: '#e91e63', 700: '#c51752', 800: '#a31547',
                            900: '#881641'
                        }
                    },
                    boxShadow: {
                        card: '0 1px 2px rgba(17, 24, 39, .05), 0 8px 24px rgba(17, 24, 39, .04)'
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
    <script>
        window.SAFA_CONFIG = <?= json_encode([
            'supabaseUrl' => SUPABASE_URL,
            'supabaseKey' => SUPABASE_ANON_KEY,
            'table'       => SUPABASE_TABLE,
            'siteUrl'     => rtrim(site_url(), '/'),
            'pageSize'    => PRODUCTS_PER_PAGE,
            'current'     => ['q' => '', 'category' => $category, 'platform' => ''],
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="<?= e(asset_url('assets/js/app.js')) ?>" defer></script>
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script type="application/ld+json"><?= json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</head>
<body class="product-page">
<div class="top-strip">
    <div class="site-container top-strip-inner">
        <span>Verified affiliate deal</span>
        <span class="top-strip-sep" aria-hidden="true">•</span>
        <span>Price checked <?= e($lastChecked !== '' ? $lastChecked : 'recently') ?></span>
    </div>
</div>

<header class="site-header product-header">
    <div class="site-container header-main">
        <a class="back-link" href="<?= e(site_url('index.php')) ?>" aria-label="Back to all deals">
            <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            <span>Deals</span>
        </a>
        <a class="brand brand-compact" href="<?= e(site_url('index.php')) ?>" aria-label="<?= e(APP_NAME) ?> home">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.9">
                    <path d="M5.5 8.5h13l-1.1 11H6.6l-1.1-11Z"/><path d="M9 8.5a3 3 0 0 1 6 0"/>
                </svg>
            </span>
            <span class="brand-text">Safa<span>Mart</span></span>
        </a>
        <button type="button" class="header-share" data-share-button aria-label="Share this product">
            <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/>
                <path d="m8.2 10.8 7.6-4.5M8.2 13.2l7.6 4.5"/>
            </svg>
            <span>Share</span>
        </button>
    </div>
</header>

<main class="site-container product-main">
    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="<?= e(site_url('index.php')) ?>">Home</a>
        <span aria-hidden="true">/</span>
        <a href="<?= e(site_url('index.php?category=' . rawurlencode($category))) ?>"><?= e($category !== '' ? $category : 'Deals') ?></a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= e(truncate_text($title, 46)) ?></span>
    </nav>

    <div class="product-detail-grid">
        <section class="product-gallery" data-gallery aria-label="Product images">
            <div class="gallery-main">
                <?php if ($primaryImage !== ''): ?>
                    <img id="mainProductImage" src="<?= e($primaryImage) ?>" alt="<?= e($title) ?>" fetchpriority="high" decoding="async" class="js-product-img">
                <?php else: ?>
                    <div class="gallery-placeholder" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="52" height="52" fill="none" stroke="currentColor" stroke-width="1.4">
                            <path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/>
                        </svg>
                        <span>Image coming soon</span>
                    </div>
                <?php endif; ?>
                <?php if ($discount > 0): ?>
                    <span class="gallery-discount"><?= $discount ?>% OFF</span>
                <?php endif; ?>
            </div>

            <?php if (count($gallery) > 1): ?>
                <div class="gallery-thumbs" role="list" aria-label="Choose a product image">
                    <?php foreach ($gallery as $index => $imageUrl): ?>
                        <button type="button"
                                class="gallery-thumb <?= $index === 0 ? 'is-active' : '' ?>"
                                data-gallery-thumb
                                data-src="<?= e($imageUrl) ?>"
                                role="listitem"
                                aria-label="View image <?= $index + 1 ?>"
                                aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>">
                            <img src="<?= e($imageUrl) ?>" alt="" loading="lazy" decoding="async">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="gallery-trust">
                <span><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg> Original product image</span>
                <span><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v18M3 12h18"/></svg> Locally hosted, fast loading</span>
            </div>
        </section>

        <section class="product-info">
            <div class="product-kicker">
                <span class="platform-badge platform-<?= e($platformClass) ?>"><?= e($platform) ?></span>
                <span class="category-tag"><?= e($category !== '' ? $category : 'Deal') ?></span>
                <?php if ($brand !== ''): ?>
                    <span class="brand-tag"><?= e($brand) ?></span>
                <?php endif; ?>
            </div>

            <h1 class="product-h1"><?= e($title) ?></h1>

            <?php if ($rating > 0): ?>
                <div class="product-rating-row">
                    <span class="rating-badge"><span aria-hidden="true">★</span> <?= e(number_format($rating, 1)) ?></span>
                    <?php if ($ratingCount > 0): ?>
                        <span class="rating-text"><?= e(number_format($ratingCount)) ?> ratings</span>
                    <?php endif; ?>
                    <span class="rating-source">on <?= e($platform) ?></span>
                </div>
            <?php endif; ?>

            <div class="detail-price-block">
                <span class="detail-price"><?= $price > 0 ? e(inr($price)) : 'Price on request' ?></span>
                <?php if ($mrp > $price && $price > 0): ?>
                    <span class="detail-mrp"><?= e(inr($mrp)) ?></span>
                    <span class="detail-discount"><?= $discount ?>% OFF</span>
                <?php endif; ?>
                <span class="detail-tax">Inclusive of all taxes</span>
            </div>

            <?php if ($savings > 0): ?>
                <div class="savings-banner">
                    <span class="savings-icon" aria-hidden="true">₹</span>
                    <div><strong>You save <?= e(inr($savings)) ?></strong><span>Compared with the listed MRP</span></div>
                </div>
            <?php endif; ?>

            <div class="deal-meta-row">
                <span class="stock-pill"><span class="live-dot"></span> Available on <?= e($platform) ?></span>
                <?php if ($lastChecked !== ''): ?>
                    <span class="checked-text">Checked <?= e($lastChecked) ?></span>
                <?php endif; ?>
            </div>

            <div class="cta-stack">
                <a class="primary-buy" href="<?= e($buyUrl) ?>" target="_blank" rel="nofollow sponsored noopener">
                    <span>Buy Now on <?= e($platform) ?></span>
                    <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
                </a>
                <p class="cta-note">You will be redirected to <?= e($platform) ?> to complete your purchase. The price stays the same.</p>
            </div>

            <div class="price-comparison-card">
                <div class="price-card-head">
                    <h2>Price snapshot</h2>
                    <span class="price-verified"><span class="live-dot"></span> Live deal</span>
                </div>
                <div class="price-line"><span>Listed MRP</span><span class="<?= $mrp > $price ? 'strike' : '' ?>"><?= $mrp > 0 ? e(inr($mrp)) : '—' ?></span></div>
                <div class="price-line"><span><?= e(APP_NAME) ?> deal price</span><strong><?= $price > 0 ? e(inr($price)) : 'Check price' ?></strong></div>
                <?php if ($savings > 0): ?>
                    <div class="price-line price-line-save"><span>Your saving</span><strong><?= e(inr($savings)) ?> (<?= $discount ?>%)</strong></div>
                <?php endif; ?>
                <div class="price-line"><span>Sold via</span><strong><?= e($platform) ?></strong></div>
                <p class="price-note">Final price, delivery fee and offers are shown on the merchant page and may change at any time.</p>
            </div>

            <div class="share-card">
                <div>
                    <h2>Share this deal</h2>
                    <p>Send it to someone who loves a good bargain.</p>
                </div>
                <div class="share-actions">
                    <a class="share-btn share-whatsapp" href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener nofollow" aria-label="Share on WhatsApp">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20Zm4.4-5.9c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1-.2.2-.6.8-.8.9-.1.2-.3.2-.5.1-.2-.1-1-.4-2-1.3-.7-.7-1.2-1.5-1.3-1.7-.1-.2 0-.4.1-.5l.4-.5c.1-.2.1-.3.2-.5.1-.2 0-.3 0-.5l-.8-1.8c-.2-.5-.4-.4-.5-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.8.8-.8 1.9s.8 2.2.9 2.4c.1.2 1.6 2.5 3.9 3.5 2.3 1 2.3.7 2.7.6.4 0 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1Z"/></svg>
                    </a>
                    <a class="share-btn share-telegram" href="<?= e($telegramUrl) ?>" target="_blank" rel="noopener nofollow" aria-label="Share on Telegram">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M21.9 4.3 18.7 19c-.2 1-.9 1.2-1.8.8l-4.9-3.6-2.4 2.3c-.3.3-.5.5-1 .5l.4-5 9.1-8.2c.4-.4-.1-.6-.6-.2L6.3 13.2l-4.8-1.5c-1-.3-1-1 .2-1.5l18.8-7.3c.9-.3 1.6.2 1.4 1.4Z"/></svg>
                    </a>
                    <button type="button" class="share-btn share-copy" data-copy-link aria-label="Copy product link">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
                    </button>
                    <button type="button" class="share-btn share-native" data-share-button aria-label="Open share menu">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.5M8.2 13.2l7.6 4.5"/></svg>
                    </button>
                </div>
            </div>
        </section>
    </div>

    <section class="product-description" aria-labelledby="aboutProduct">
        <h2 id="aboutProduct">About this product</h2>
        <p><?= e($description) ?></p>
        <div class="detail-facts">
            <?php if ($brand !== ''): ?>
                <div><span>Brand</span><strong><?= e($brand) ?></strong></div>
            <?php endif; ?>
            <div><span>Category</span><strong><?= e($category !== '' ? $category : 'Deal') ?></strong></div>
            <div><span>Store</span><strong><?= e($platform) ?></strong></div>
            <?php if ($rating > 0): ?>
                <div><span>Rating</span><strong>★ <?= e(number_format($rating, 1)) ?><?= $ratingCount > 0 ? ' (' . e(number_format($ratingCount)) . ')' : '' ?></strong></div>
            <?php endif; ?>
            <div><span>Price checked</span><strong><?= e($lastChecked !== '' ? $lastChecked : 'Recently') ?></strong></div>
        </div>
    </section>

    <?php if ($related): ?>
        <section class="related-section" aria-labelledby="relatedHeading">
            <div class="section-heading">
                <div>
                    <h2 id="relatedHeading">You may also like</h2>
                    <p>More deals from <?= e($category !== '' ? $category : 'the storefront') ?></p>
                </div>
                <a class="clear-filters" href="<?= e(site_url('index.php?category=' . rawurlencode($category))) ?>">View all</a>
            </div>
            <div class="product-grid">
                <?php foreach ($related as $relatedProduct): ?>
                    <?= render_product_card($relatedProduct) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</main>

<div class="mobile-buybar">
    <div class="mobile-price">
        <span>Best price</span>
        <strong><?= $price > 0 ? e(inr($price)) : 'Check price' ?></strong>
        <?php if ($discount > 0): ?><em><?= $discount ?>% off</em><?php endif; ?>
    </div>
    <a class="mobile-buy-btn" href="<?= e($buyUrl) ?>" target="_blank" rel="nofollow sponsored noopener">Buy Now</a>
</div>

<footer class="site-footer product-footer">
    <div class="site-container footer-bottom">
        <span>© <?= date('Y') ?> <?= e(APP_NAME) ?>. Affiliate links may earn us a commission.</span>
        <a href="<?= e(site_url('index.php')) ?>">Browse all deals</a>
    </div>
</footer>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<?php require __DIR__ . '/includes/telegram_popup.php'; ?>
</body>
</html>
<?php

function render_product_not_found(): void
{
    http_response_code(404);
    $title = 'Deal not found | ' . APP_NAME;
    ?>
    <!doctype html>
    <html lang="en-IN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,follow">
        <title><?= e($title) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
    </head>
    <body class="not-found-page">
    <main class="not-found-card">
        <span class="brand-mark not-found-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5.5 8.5h13l-1.1 11H6.6l-1.1-11Z"/><path d="M9 8.5a3 3 0 0 1 6 0"/></svg>
        </span>
        <h1>This deal has moved on</h1>
        <p>The product may have sold out or the link may be incorrect. Explore today's live deals instead.</p>
        <a class="primary-buy not-found-action" href="<?= e(site_url('index.php')) ?>">Browse live deals</a>
    </main>
    </body>
    </html>
    <?php
    exit;
}
