<?php
/**
 * /guides — buying guide index.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/landing.php';
security_headers();

$guides = seo_guide_catalog();
$pageUrl = site_url('guides/');
$pageTitle = 'Buying Guides & Deal Trackers | ' . APP_NAME;
$pageDescription = 'Practical buying guides for earbuds, kurtas, footwear and everyday deals, with live prices from Indian marketplaces.';
$origin = rtrim(site_url(), '/');

$guideItems = [];
$guidePosition = 1;
foreach ($guides as $guide) {
    $guideItems[] = [
        '@type'    => 'ListItem',
        'position' => $guidePosition++,
        'url'      => site_url('guides/' . $guide['slug']),
        'name'     => $guide['h1'],
    ];
}
$itemList = seo_jsonld([
    '@context'        => 'https://schema.org',
    '@type'           => 'ItemList',
    'name'            => 'Safa Mart buying guides',
    'url'             => $pageUrl,
    'numberOfItems'   => count($guides),
    'itemListElement' => $guideItems,
]);
?>
<!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#e91e63">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($pageDescription) ?>">
    <?= seo_robots_meta(true) ?>
    <link rel="canonical" href="<?= e($pageUrl) ?>">
    <?= seo_hreflang_links($pageUrl) ?>
    <?php foreach (seo_verification_meta() as $tag): ?><?= $tag ?><?php endforeach; ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <meta property="og:url" content="<?= e($pageUrl) ?>">
    <meta name="twitter:card" content="summary">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/home-v2.css')) ?>">
    <script src="<?= e(asset_url('assets/js/app.js')) ?>" defer></script>
    <?= seo_site_schema() ?>
    <?= $itemList ?>
    <?= seo_breadcrumb_schema([
        ['name' => 'Home', 'url' => site_url('index.php')],
        ['name' => 'Guides', 'url' => $pageUrl],
    ]) ?>
    <?= seo_analytics_snippet() ?>
</head>
<body>
<?php render_landing_header('', 'Buying guides with live prices'); ?>

<main class="site-container storefront-main">
    <nav class="category-strip" aria-label="Shop by category">
        <a class="category-chip is-active" href="<?= e(site_url('index.php')) ?>">All deals</a>
        <?php foreach (seo_category_catalog() as $catName => $catMeta): ?>
            <a class="category-chip" href="<?= e(site_url('deals/' . $catMeta['slug'])) ?>"><?= e($catName) ?></a>
        <?php endforeach; ?>
    </nav>

    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="<?= e(site_url('index.php')) ?>">Home</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Guides</span>
    </nav>

    <section class="browse-hero">
        <h1>Buying guides &amp; deal trackers</h1>
        <p>Practical guides written for Indian shoppers — what to check, what to skip, and which live listings are worth opening.</p>
    </section>

    <section class="guide-card-grid" aria-label="All guides">
        <?php foreach ($guides as $guide): ?>
            <a class="guide-card" href="<?= e(site_url('guides/' . $guide['slug'])) ?>">
                <span class="guide-card-kicker">Buying guide</span>
                <strong><?= e($guide['h1']) ?></strong>
                <span class="guide-card-desc"><?= e($guide['description']) ?></span>
                <span class="guide-card-meta">Updated <?= e(date('d M Y', strtotime($guide['updated']))) ?></span>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="browse-links" aria-label="Budget collections and sale trackers">
        <h2>Budget collections</h2>
        <div class="browse-link-grid">
            <?php foreach (seo_budget_catalog() as $budgetMeta): ?>
                <a class="category-chip" href="<?= e(site_url('deals/' . $budgetMeta['slug'])) ?>"><?= e($budgetMeta['label']) ?> deals</a>
            <?php endforeach; ?>
        </div>
        <h2 style="margin-top:18px">Sale trackers</h2>
        <div class="browse-link-grid">
            <?php foreach (seo_event_catalog() as $eventMeta): ?>
                <a class="category-chip" href="<?= e(site_url('sale/' . $eventMeta['slug'])) ?>"><?= e($eventMeta['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<?php render_landing_footer(); ?>
</body>
</html>
