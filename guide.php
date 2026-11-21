<?php
/**
 * /guides/<slug> — single buying guide with live product picks.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/landing.php';
security_headers();

$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
$guides = seo_guide_catalog();
$guide = $guides[$slug] ?? null;

if ($guide === null) {
    http_response_code(404);
    ?>
    <!doctype html><html lang="en-IN"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,follow">
    <title>Guide not found | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>"></head>
    <body><main class="site-container" style="padding:60px 0;text-align:center">
    <h1>Guide not found</h1>
    <p><a href="<?= e(site_url('guides/')) ?>">Browse all guides</a></p>
    </main></body></html>
    <?php
    exit;
}

$pageUrl = site_url('guides/' . $guide['slug']);
$pageTitle = $guide['title'];
$metaDescription = truncate_text($guide['description'], 158);
$products = fetch_storefront_products($guide['filters'], 12);
$guideFallback = false;
if (!$products) {
    $products = fetch_storefront_products(['sort' => 'discount_desc'], 12);
    $guideFallback = true;
}
$products = attach_featured_badges($products);
$origin = rtrim(site_url(), '/');

$pickItems = [];
$pickPosition = 1;
foreach ($products as $product) {
    $pickItems[] = [
        '@type'    => 'ListItem',
        'position' => $pickPosition++,
        'url'      => site_url('product.php?slug=' . rawurlencode((string) ($product['slug'] ?? ''))),
        'name'     => clean_product_title((string) ($product['title'] ?? 'Product')),
    ];
}

$articleSchema = seo_jsonld([
    '@context'      => 'https://schema.org',
    '@type'         => 'Article',
    'headline'      => $guide['h1'],
    'description'   => $guide['description'],
    'url'           => $pageUrl,
    'datePublished' => $guide['updated'],
    'dateModified'  => $guide['updated'],
    'author'        => ['@id' => $origin . '/#organization'],
    'publisher'     => ['@id' => $origin . '/#organization'],
    'mainEntity'    => $pickItems ? [
        '@type'           => 'ItemList',
        'itemListElement' => $pickItems,
    ] : null,
]);

$faqSchema = $guide['faq'] ? seo_jsonld([
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(static function (array $item): array {
        return [
            '@type'          => 'Question',
            'name'           => $item['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
        ];
    }, $guide['faq']),
]) : '';

$relatedGuides = array_filter($guides, static function (array $item) use ($slug): bool {
    return $item['slug'] !== $slug;
});
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
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
    <meta property="og:title" content="<?= e($guide['h1']) ?>">
    <meta property="og:description" content="<?= e($metaDescription) ?>">
    <meta property="og:url" content="<?= e($pageUrl) ?>">
    <meta name="twitter:card" content="summary">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/home-v2.css')) ?>">
    <script src="<?= e(asset_url('assets/js/app.js')) ?>" defer></script>
    <?= seo_site_schema() ?>
    <?= $articleSchema ?>
    <?= $faqSchema ?>
    <?= seo_breadcrumb_schema([
        ['name' => 'Home', 'url' => site_url('index.php')],
        ['name' => 'Guides', 'url' => site_url('guides/')],
        ['name' => $guide['h1'], 'url' => $pageUrl],
    ]) ?>
    <?= seo_analytics_snippet() ?>
</head>
<body>
<?php render_landing_header('', 'Buying guide with live prices'); ?>

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
        <a href="<?= e(site_url('guides/')) ?>">Guides</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= e(truncate_text($guide['h1'], 52)) ?></span>
    </nav>

    <article class="guide-article">
        <header class="browse-hero guide-hero">
            <span class="guide-kicker">Buying guide</span>
            <h1><?= e($guide['h1']) ?></h1>
            <p><?= e($guide['intro']) ?></p>
            <p class="browse-count">Last updated <?= e(date('d M Y', strtotime($guide['updated']))) ?> · prices checked regularly</p>
        </header>

        <?= ad_slot('browse-top') ?>

        <?php if ($guideFallback): ?>
            <div class="notice-card">
                <strong>No exact matches for this guide right now.</strong>
                <p>Prices and stock change quickly. Here are today's top live deals while fresh listings arrive.</p>
            </div>
        <?php endif; ?>

        <?php if ($products): ?>
            <section class="guide-picks" aria-labelledby="guidePicksHeading">
                <div class="section-heading">
                    <div>
                        <h2 id="guidePicksHeading">Live deals worth checking</h2>
                        <p>Current listings that match this guide — open the store page to confirm size or variant.</p>
                    </div>
                </div>
                <div class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <?= render_product_card($product) ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php foreach ($guide['sections'] as $section): ?>
            <section class="guide-section">
                <h2><?= e($section['h2']) ?></h2>
                <?php foreach ($section['paragraphs'] as $paragraph): ?>
                    <p><?= e($paragraph) ?></p>
                <?php endforeach; ?>
                <?php if (!empty($section['bullets'])): ?>
                    <ul class="guide-bullets">
                        <?php foreach ($section['bullets'] as $bullet): ?>
                            <li><?= e($bullet) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <?php if ($guide['faq']): ?>
            <section class="product-faq" aria-labelledby="guideFaqHeading">
                <h2 id="guideFaqHeading">Common questions</h2>
                <?php foreach ($guide['faq'] as $faq): ?>
                    <details class="faq-item">
                        <summary><?= e($faq['q']) ?></summary>
                        <p><?= e($faq['a']) ?></p>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?= ad_slot('browse-bottom') ?>

        <?php if ($relatedGuides): ?>
            <section class="browse-links" aria-label="More guides">
                <h2>More guides</h2>
                <div class="browse-link-grid">
                    <?php foreach ($relatedGuides as $related): ?>
                        <a class="category-chip" href="<?= e(site_url('guides/' . $related['slug'])) ?>"><?= e($related['h1']) ?></a>
                    <?php endforeach; ?>
                    <?php foreach (seo_budget_catalog() as $budgetMeta): ?>
                        <a class="category-chip" href="<?= e(site_url('deals/' . $budgetMeta['slug'])) ?>"><?= e($budgetMeta['label']) ?> deals</a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </article>
</main>

<?php render_landing_footer(); ?>
</body>
</html>
