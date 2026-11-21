<?php
/**
 * SEO landing pages: /deals/<category> and /store/<store>.
 */

require_once __DIR__ . '/config.php';
security_headers();

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));

$isCategory = $type === 'category';
$isStore = $type === 'store';
$isBudget = $type === 'budget';
$categoryMap = seo_category_slug_map();
$storeMap = seo_store_slug_map();
$budgetMap = seo_budget_catalog();

$meta = null;
$name = '';
$pageUrl = '';
$productFilters = [];

if ($isCategory && isset($categoryMap[$slug])) {
    $name = $categoryMap[$slug];
    $meta = seo_category_catalog()[$name];
    $pageUrl = site_url('deals/' . $meta['slug']);
    $productFilters = ['category' => $name];
} elseif ($isStore && isset($storeMap[$slug])) {
    $name = $storeMap[$slug];
    $meta = seo_store_catalog()[$name];
    $pageUrl = site_url('store/' . $meta['slug']);
    $productFilters = ['platform' => $name];
} elseif ($isBudget && isset($budgetMap[$slug])) {
    $meta = $budgetMap[$slug];
    $name = $meta['label'];
    $pageUrl = site_url('deals/' . $meta['slug']);
    $productFilters = ['max_price' => $meta['max'], 'sort' => 'discount_desc'];
}

if ($meta === null) {
    http_response_code(404);
    $pageTitle = 'Page not found | ' . APP_NAME;
    ?>
    <!doctype html>
    <html lang="en-IN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,follow">
        <title><?= e($pageTitle) ?></title>
        <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
        <?php pwa_head_tags(); ?>
    </head>
    <body>
    <main class="site-container" style="padding:60px 0;text-align:center">
        <h1>Page not found</h1>
        <p>This collection does not exist.</p>
        <p><a class="filter-apply" href="<?= e(site_url('index.php')) ?>">Browse all deals</a></p>
    </main>
    <?php render_pwa_widgets(); ?>
    </body>
    </html>
    <?php
    exit;
}

$products = fetch_storefront_products($productFilters, 48);
$browseFallback = false;
if (!$products) {
    // Never leave a collection empty: keep the page useful with top deals.
    $products = fetch_storefront_products(['sort' => 'discount_desc'], 24);
    $browseFallback = true;
}
$products = attach_featured_badges($products);

$origin = rtrim(site_url(), '/');
$ogImage = '';
foreach ($products as $product) {
    $image = (string) ($product['thumbnail_url'] ?? '');
    if (preg_match('#^https?://#i', $image)) {
        $ogImage = $image;
        break;
    }
}

$pageTitle = $meta['title'];
$metaDescription = truncate_text($meta['intro'] . ' ' . $meta['long'], 158);
$breadcrumb = [
    ['name' => 'Home', 'url' => site_url('index.php')],
    ['name' => $isCategory ? 'Categories' : 'Stores', 'url' => site_url('index.php')],
    ['name' => $name, 'url' => $pageUrl],
];

$itemList = [
    '@context'        => 'https://schema.org',
    '@type'           => 'ItemList',
    'name'            => $meta['h1'],
    'url'             => $pageUrl,
    'numberOfItems'   => count($products),
    'itemListElement' => [],
];
foreach (array_slice($products, 0, 24) as $index => $product) {
    $itemList['itemListElement'][] = [
        '@type'    => 'ListItem',
        'position' => $index + 1,
        'url'      => site_url('product.php?slug=' . rawurlencode((string) ($product['slug'] ?? ''))),
        'name'     => clean_product_title((string) ($product['title'] ?? 'Product')),
    ];
}

$collectionSchema = seo_jsonld([
    '@context'    => 'https://schema.org',
    '@type'       => 'CollectionPage',
    'name'        => $meta['h1'],
    'description' => $metaDescription,
    'url'         => $pageUrl,
    'isPartOf'    => ['@id' => $origin . '/#website'],
]);
?>
<!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#e91e63">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <?= seo_robots_meta(true) ?>
    <link rel="canonical" href="<?= e($pageUrl) ?>">
    <?= seo_hreflang_links($pageUrl) ?>
    <?php foreach (seo_verification_meta() as $tag): ?>
        <?= $tag ?>
    <?php endforeach; ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
    <meta property="og:title" content="<?= e($meta['h1']) ?>">
    <meta property="og:description" content="<?= e($metaDescription) ?>">
    <meta property="og:url" content="<?= e($pageUrl) ?>">
    <?php if ($ogImage !== ''): ?>
        <meta property="og:image" content="<?= e($ogImage) ?>">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="<?= e($ogImage) ?>">
    <?php else: ?>
        <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/home-v2.css')) ?>">
    <?php pwa_head_tags(); ?>
    <script src="<?= e(asset_url('assets/js/app.js')) ?>" defer></script>
    <?= seo_site_schema() ?>
    <?= $collectionSchema ?>
    <?= $itemList ? seo_jsonld($itemList) : '' ?>
    <?= seo_breadcrumb_schema($breadcrumb) ?>
    <?= seo_analytics_snippet() ?>
</head>
<body>
<div class="top-strip">
    <div class="site-container top-strip-inner">
        <span>Lowest price deals, updated every 4 hours</span>
        <span class="top-strip-sep" aria-hidden="true">•</span>
        <span><?= $isCategory ? 'Fashion, electronics, home and more' : 'Flipkart, Myntra, Amazon, Ajio, Shopsy, DigiHaat' ?></span>
    </div>
</div>

<header class="site-header" id="siteHeader">
    <div class="site-container header-main">
        <a class="brand" href="<?= e(site_url('index.php')) ?>" aria-label="<?= e(APP_NAME) ?> home">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="23" height="23" fill="none" stroke="currentColor" stroke-width="1.9">
                    <path d="M5.5 8.5h13l-1.1 11H6.6l-1.1-11Z"/><path d="M9 8.5a3 3 0 0 1 6 0"/>
                </svg>
            </span>
            <span class="brand-text">Safa<span>Mart</span></span>
        </a>
        <form class="search-form" id="searchForm" action="index.php" method="get" role="search">
            <svg class="search-icon" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>
            </svg>
            <input type="search" id="searchInput" name="q" placeholder="Search sarees, earbuds, shoes…"
                   autocomplete="off" enterkeyhint="search" aria-label="Search products" maxlength="60">
            <button type="submit" class="search-submit">Search</button>
        </form>
        <a class="header-badge" href="<?= e(site_url('index.php')) ?>">
            <span class="header-badge-dot"></span><span>Live deals</span>
        </a>
    </div>
    <div class="site-container platform-strip" aria-label="Browse by store">
        <?php foreach (seo_store_catalog() as $storeName => $storeMeta): ?>
            <a class="platform-pill <?= (!$isCategory && $storeName === $name) ? 'is-active' : '' ?>"
               href="<?= e(site_url('store/' . $storeMeta['slug'])) ?>">
                <span class="platform-dot platform-dot-<?= e(strtolower($storeName)) ?>"></span> <?= e($storeName) ?>
            </a>
        <?php endforeach; ?>
    </div>
</header>

<main class="site-container storefront-main">
    <nav class="category-strip" aria-label="Shop by category">
        <a class="category-chip <?= ($isCategory || $isBudget) ? '' : 'is-active' ?>" href="<?= e(site_url('index.php')) ?>">All deals</a>
        <?php foreach (seo_category_catalog() as $catName => $catMeta): ?>
            <a class="category-chip <?= ($isCategory && $catName === $name) ? 'is-active' : '' ?>"
               href="<?= e(site_url('deals/' . $catMeta['slug'])) ?>"><?= e($catName) ?></a>
        <?php endforeach; ?>
    </nav>

    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="<?= e(site_url('index.php')) ?>">Home</a>
        <span aria-hidden="true">/</span>
        <span><?= $isCategory ? 'Categories' : 'Stores' ?></span>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= e($name) ?></span>
    </nav>

    <section class="browse-hero">
        <h1><?= e($meta['h1']) ?></h1>
        <p><?= e($meta['intro']) ?></p>
        <p class="browse-count"><?= count($products) ?> live deals · prices checked daily</p>
    </section>

    <?= ad_slot('browse-top') ?>

    <?php if ($browseFallback): ?>
        <div class="notice-card">
            <strong>No live <?= e($name) ?> deals right now.</strong>
            <p>Fresh listings are added through the day. Here are today's top live deals from other stores while you wait.</p>
        </div>
    <?php endif; ?>

    <?php if ($products): ?>
        <div class="product-grid">
            <?php foreach ($products as $product): ?>
                <?= render_product_card($product) ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="notice-card">
            <strong>No live deals right now.</strong>
            <p>Fresh <?= e($name) ?> offers are added every few hours. <a href="<?= e(site_url('index.php')) ?>">Browse all deals</a> in the meantime.</p>
        </div>
    <?php endif; ?>

    <?= ad_slot('browse-bottom') ?>

    <section class="browse-copy">
        <h2>Why shop <?= e($name) ?> deals on <?= e(APP_NAME) ?>?</h2>
        <p><?= e($meta['long']) ?></p>
        <p>Safa Mart lists only products with a working affiliate link, shows the current price next to the listed MRP and links you straight to the official store. Checkout, delivery and returns always stay with the merchant — you never pay extra for using Safa Mart.</p>
        <h2>How the price check works</h2>
        <p>Every listing is re-checked and old, unavailable or non-affiliated products are hidden automatically. The discount percentage on each card is calculated from the merchant's own listed price, so a "70% off" tag reflects a real drop rather than an inflated MRP.</p>
    </section>

    <section class="browse-links" aria-label="More collections">
        <h2>Browse more collections</h2>
        <div class="browse-link-grid">
            <?php foreach (seo_category_catalog() as $catName => $catMeta): ?>
                <a class="category-chip" href="<?= e(site_url('deals/' . $catMeta['slug'])) ?>"><?= e($catName) ?> deals</a>
            <?php endforeach; ?>
            <?php foreach (seo_store_catalog() as $storeName => $storeMeta): ?>
                <a class="category-chip" href="<?= e(site_url('store/' . $storeMeta['slug'])) ?>"><?= e($storeName) ?> deals</a>
            <?php endforeach; ?>
            <?php foreach (seo_budget_catalog() as $budgetMeta): ?>
                <a class="category-chip" href="<?= e(site_url('deals/' . $budgetMeta['slug'])) ?>"><?= e($budgetMeta['label']) ?> deals</a>
            <?php endforeach; ?>
            <?php foreach (seo_guide_catalog() as $guideMeta): ?>
                <a class="category-chip" href="<?= e(site_url('guides/' . $guideMeta['slug'])) ?>"><?= e($guideMeta['h1']) ?></a>
            <?php endforeach; ?>
            <?php foreach (seo_event_catalog() as $eventMeta): ?>
                <a class="category-chip" href="<?= e(site_url('sale/' . $eventMeta['slug'])) ?>"><?= e($eventMeta['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<?php render_site_footer(); ?>
<?php render_pwa_widgets(); ?>
<?php require __DIR__ . '/includes/telegram_button.php'; ?>
<?php require __DIR__ . '/includes/telegram_popup.php'; ?>
</body>
</html>
