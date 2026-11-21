<?php
/**
 * /sale/<slug> — festival sale tracker pages.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/landing.php';
security_headers();

$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
$events = seo_event_catalog();
$event = $events[$slug] ?? null;

if ($event === null) {
    http_response_code(404);
    ?>
    <!doctype html><html lang="en-IN"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,follow">
    <title>Sale not found | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>"></head>
    <body><main class="site-container" style="padding:60px 0;text-align:center">
    <h1>Sale tracker not found</h1>
    <p><a href="<?= e(site_url('index.php')) ?>">Browse all deals</a></p>
    </main></body></html>
    <?php
    exit;
}

$pageUrl = site_url('sale/' . $event['slug']);
$pageTitle = $event['title'];
$metaDescription = truncate_text($event['intro'], 158);
$products = fetch_storefront_products(['platform' => $event['platform'], 'sort' => 'discount_desc'], 36);
$fallbackUsed = false;
if (!$products) {
    // The store may have no affiliate-ready listings right now. Keep the page
    // useful with today's top deals from the other stores.
    $products = fetch_storefront_products(['sort' => 'discount_desc'], 24);
    $fallbackUsed = true;
}
$products = attach_featured_badges($products);
$origin = rtrim(site_url(), '/');

$pickItems = [];
$pickPosition = 1;
foreach (array_slice($products, 0, 24) as $product) {
    $pickItems[] = [
        '@type'    => 'ListItem',
        'position' => $pickPosition++,
        'url'      => site_url('product.php?slug=' . rawurlencode((string) ($product['slug'] ?? ''))),
        'name'     => clean_product_title((string) ($product['title'] ?? 'Product')),
    ];
}

$collectionSchema = seo_jsonld([
    '@context'    => 'https://schema.org',
    '@type'       => 'CollectionPage',
    'name'        => $event['h1'],
    'description' => $metaDescription,
    'url'         => $pageUrl,
    'isPartOf'    => ['@id' => $origin . '/#website'],
]);

$faqSchema = seo_jsonld([
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(static function (array $item): array {
        return [
            '@type'          => 'Question',
            'name'           => $item['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
        ];
    }, $event['faq']),
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
    <?php foreach (seo_verification_meta() as $tag): ?><?= $tag ?><?php endforeach; ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
    <meta property="og:title" content="<?= e($event['h1']) ?>">
    <meta property="og:description" content="<?= e($metaDescription) ?>">
    <meta property="og:url" content="<?= e($pageUrl) ?>">
    <meta name="twitter:card" content="summary">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/home-v2.css')) ?>">
    <?php pwa_head_tags(); ?>
    <script src="<?= e(asset_url('assets/js/app.js')) ?>" defer></script>
    <?= seo_site_schema() ?>
    <?= $collectionSchema ?>
    <?= $faqSchema ?>
    <?= $pickItems ? seo_jsonld([
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'name'            => $event['h1'],
        'url'             => $pageUrl,
        'numberOfItems'   => count($pickItems),
        'itemListElement' => $pickItems,
    ]) : '' ?>
    <?= seo_breadcrumb_schema([
        ['name' => 'Home', 'url' => site_url('index.php')],
        ['name' => 'Sale trackers', 'url' => site_url('guides/')],
        ['name' => $event['name'], 'url' => $pageUrl],
    ]) ?>
    <?= seo_analytics_snippet() ?>
</head>
<body>
<?php render_landing_header($event['platform'], $event['name'] . ' · live deal tracker'); ?>

<main class="site-container storefront-main">
    <nav class="category-strip" aria-label="Shop by category">
        <a class="category-chip" href="<?= e(site_url('index.php')) ?>">All deals</a>
        <?php foreach (seo_category_catalog() as $catName => $catMeta): ?>
            <a class="category-chip" href="<?= e(site_url('deals/' . $catMeta['slug'])) ?>"><?= e($catName) ?></a>
        <?php endforeach; ?>
    </nav>

    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="<?= e(site_url('index.php')) ?>">Home</a>
        <span aria-hidden="true">/</span>
        <a href="<?= e(site_url('guides/')) ?>">Sale trackers</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= e($event['name']) ?></span>
    </nav>

    <section class="event-hero">
        <span class="event-season"><?= e($event['season']) ?></span>
        <h1><?= e($event['h1']) ?></h1>
        <p><?= e($event['intro']) ?></p>
        <span class="event-note">Dates are announced by <?= e($event['platform']) ?>. This page tracks live prices, not a countdown.</span>
    </section>

    <?= ad_slot('browse-top') ?>

    <?php if ($fallbackUsed): ?>
        <div class="notice-card">
            <strong>No <?= e($event['platform']) ?> deals with a working affiliate link right now.</strong>
            <p><?= e($event['platform']) ?> listings are added as soon as our tracking link is approved. Meanwhile, here are today's top live deals from the other stores.</p>
        </div>
    <?php endif; ?>

    <?php if ($products): ?>
        <section class="guide-picks" aria-labelledby="eventDealsHeading">
            <div class="section-heading">
                <div>
                    <h2 id="eventDealsHeading"><?= $fallbackUsed ? 'Top live deals from other stores' : 'Top ' . e($event['platform']) . ' deals right now' ?></h2>
                    <p><?= count($products) ?> live listings, sorted by biggest discount</p>
                </div>
            </div>
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <?= render_product_card($product) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
        <div class="notice-card">
            <strong>No live <?= e($event['platform']) ?> deals right now.</strong>
            <p>Fresh listings are added through the day. <a href="<?= e(site_url('index.php')) ?>">Browse all deals</a> in the meantime.</p>
        </div>
    <?php endif; ?>

    <section class="browse-copy">
        <h2>About <?= e($event['name']) ?></h2>
        <p><?= e($event['long']) ?></p>
        <p>Safa Mart may earn a small affiliate commission when you buy through these links. The price you pay on <?= e($event['platform']) ?> stays the same, and delivery, returns and warranty are handled by the store.</p>
    </section>

    <section class="product-faq" aria-labelledby="eventFaqHeading">
        <h2 id="eventFaqHeading">Common questions</h2>
        <?php foreach ($event['faq'] as $faq): ?>
            <details class="faq-item">
                <summary><?= e($faq['q']) ?></summary>
                <p><?= e($faq['a']) ?></p>
            </details>
        <?php endforeach; ?>
    </section>

    <?= ad_slot('browse-bottom') ?>

    <section class="browse-links" aria-label="Other sale trackers">
        <h2>Other sale trackers</h2>
        <div class="browse-link-grid">
            <?php foreach ($events as $otherEvent): ?>
                <?php if ($otherEvent['slug'] === $event['slug']) { continue; } ?>
                <a class="category-chip" href="<?= e(site_url('sale/' . $otherEvent['slug'])) ?>"><?= e($otherEvent['name']) ?></a>
            <?php endforeach; ?>
            <?php foreach (seo_guide_catalog() as $guideMeta): ?>
                <a class="category-chip" href="<?= e(site_url('guides/' . $guideMeta['slug'])) ?>"><?= e($guideMeta['h1']) ?></a>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<?php render_landing_footer(); ?>
</body>
</html>
