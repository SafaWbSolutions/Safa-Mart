<?php
/**
 * /price-drops — products whose tracked price fell recently.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/landing.php';
security_headers();

$drops = fetch_recent_price_drops(18);
$products = array_map(static function (array $entry): array {
    return $entry['product'];
}, $drops);

$pageUrl = site_url('price-drops');
$pageTitle = 'Biggest Price Drops Today – Live Deal Tracker | ' . APP_NAME;
$pageDescription = 'Products whose price actually dropped, tracked from Flipkart, Myntra, Amazon, Ajio, Shopsy and DigiHaat. See the old price, new price and total saving.';
$origin = rtrim(site_url(), '/');

$pickItems = [];
$pickPosition = 1;
foreach ($drops as $entry) {
    $pickItems[] = [
        '@type'    => 'ListItem',
        'position' => $pickPosition++,
        'url'      => site_url('product.php?slug=' . rawurlencode((string) ($entry['product']['slug'] ?? ''))),
        'name'     => clean_product_title((string) ($entry['product']['title'] ?? 'Product')),
    ];
}
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
    <?= seo_jsonld([
        '@context'    => 'https://schema.org',
        '@type'       => 'CollectionPage',
        'name'        => 'Biggest price drops',
        'description' => $pageDescription,
        'url'         => $pageUrl,
        'isPartOf'    => ['@id' => $origin . '/#website'],
    ]) ?>
    <?= $pickItems ? seo_jsonld([
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'name'            => 'Products with recent price drops',
        'url'             => $pageUrl,
        'numberOfItems'   => count($pickItems),
        'itemListElement' => $pickItems,
    ]) : '' ?>
    <?= seo_breadcrumb_schema([
        ['name' => 'Home', 'url' => site_url('index.php')],
        ['name' => 'Price drops', 'url' => $pageUrl],
    ]) ?>
    <?= seo_analytics_snippet() ?>
</head>
<body>
<?php render_landing_header('', 'Live price-drop tracker'); ?>

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
        <span aria-current="page">Price drops</span>
    </nav>

    <section class="browse-hero">
        <h1>Biggest price drops right now</h1>
        <p>These products became cheaper than their previous tracked price. The card shows how much the price fell, so you can spot a real drop instead of an inflated discount.</p>
        <p class="browse-count"><?= count($drops) ?> tracked price drops · updated through the day</p>
    </section>

    <?= ad_slot('browse-top') ?>

    <?php if ($drops): ?>
        <div class="drop-list">
            <?php foreach ($drops as $entry): ?>
                <?php $product = $entry['product']; ?>
                <div class="drop-item">
                    <?= render_product_card($product) ?>
                    <div class="drop-meta">
                        <span>Was <s><?= e(inr($entry['previous'])) ?></s> → now <strong><?= e(inr($entry['current'])) ?></strong></span>
                        <span>You save <?= e(inr($entry['drop'])) ?> vs the previous price</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="notice-card">
            <strong>Price-drop tracking abhi start hua hai.</strong>
            <p>Naye price changes record hone ke baad yahan drops dikhne lagenge.
               Tab tak <a href="<?= e(site_url('index.php')) ?>">all deals</a> ya
               <a href="<?= e(site_url('deals/under-999')) ?>">deals under ₹999</a> dekho.</p>
        </div>
    <?php endif; ?>

    <?= ad_slot('browse-bottom') ?>

    <section class="browse-copy">
        <h2>How price-drop tracking works</h2>
        <p>Whenever a product is checked or refreshed, Safa Mart stores its current price. If the new price is lower than the previous snapshot, it appears here with the drop amount and percentage. Old prices are the merchant's listed price at the time of the check — they are not a promise of future pricing.</p>
        <p>Prices on marketplaces change without notice. Always confirm the final amount on the store page before buying.</p>
    </section>
</main>

<?php render_landing_footer(); ?>
</body>
</html>
