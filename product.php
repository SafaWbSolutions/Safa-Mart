<?php
require_once __DIR__ . '/config.php';
security_headers();

$slug = trim((string) ($_GET['slug'] ?? ''));
$product = $slug !== '' ? find_product_by_slug($slug) : null;
if ($product === null) {
    render_product_not_found();
}

$id = (int) ($product['id'] ?? 0);
$title = clean_product_title($product['title'] ?? 'Product');
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
$featuredBadge = '';
$featuredBadges = featured_badges();
if ($featuredBadges) {
    $featuredBadge = truncate_text(clean_text((string) ($featuredBadges[strtolower($slug)] ?? '')), 24);
}
$rating = (float) ($product['rating'] ?? 0);
$ratingCount = (int) ($product['rating_count'] ?? 0);
$description = clean_text($product['description'] ?? '');
if ($description === '') {
    $description = $title . ' available at ' . ($price > 0 ? inr($price) : 'the best price')
        . ' on ' . $platform . '. Check the latest price and buy through ' . APP_NAME . '.';
}
$gallery = product_gallery($product);
$primaryImage = $gallery[0] ?? '';
$savings = max(0, (float) round($mrp - $price));
$productUrl = site_url('product.php?slug=' . rawurlencode((string) ($product['slug'] ?? '')));
$buyUrl = site_url('go.php?id=' . $id);
$lastChecked = human_date($product['last_synced_at'] ?? ($product['updated_at'] ?? null));
$platformClass = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($platform)) ?? '', '-');
if ($platformClass === '') {
    $platformClass = 'store';
}

$specs = [];
if (isset($product['specs']) && is_array($product['specs'])) {
    $specs = array_values(array_filter($product['specs'], static function ($spec): bool {
        return is_array($spec) && trim((string) ($spec['key'] ?? '')) !== '' && trim((string) ($spec['value'] ?? '')) !== '';
    }));
}

$specPriorities = [
    'fabric', 'material', 'fit', 'pattern', 'print', 'colour', 'color', 'occasion',
    'sleeve', 'neck', 'display', 'screen', 'battery', 'ram', 'storage', 'processor',
    'camera', 'capacity', 'weight', 'dimensions', 'type',
];
$rankedSpecs = [];
foreach ($specs as $spec) {
    $specKey = strtolower((string) ($spec['key'] ?? ''));
    if ($specKey === '' || str_contains($specKey, 'body or garment')
        || preg_match('/^(material\s*&\s*care|size\s*&\s*fit|product details)$/i', trim($specKey))) {
        continue;
    }
    $rank = 100;
    foreach ($specPriorities as $index => $needle) {
        $matches = $needle === 'ram'
            ? (bool) preg_match('/\bram\b/', $specKey)
            : str_contains($specKey, $needle);
        if ($matches) {
            $rank = $index;
            break;
        }
    }
    if ($rank < 100) {
        $rankedSpecs[] = [$rank, $spec];
    }
}
usort($rankedSpecs, static function (array $a, array $b): int {
    return $a[0] <=> $b[0];
});
$keySpecs = [];
$seenSpecValues = [];
foreach ($rankedSpecs as $entry) {
    $value = strtolower(trim((string) ($entry[1]['value'] ?? '')));
    if ($value === '' || isset($seenSpecValues[$value])) {
        continue;
    }
    $seenSpecValues[$value] = true;
    $keySpecs[] = $entry[1];
    if (count($keySpecs) >= 4) {
        break;
    }
}

if ($discount >= 60) {
    $dealLabel = '🔥 Blockbuster deal';
} elseif ($discount >= 40) {
    $dealLabel = '🔥 Great deal';
} elseif ($discount >= 20) {
    $dealLabel = '⚡ Good deal';
} elseif ($rating >= 4) {
    $dealLabel = '⭐ Top rated pick';
} else {
    $dealLabel = '✓ Verified deal';
}

$stars = '';
if ($rating > 0) {
    $filled = max(1, min(5, (int) round($rating)));
    $stars = str_repeat('★', $filled) . str_repeat('☆', 5 - $filled);
}

// Marketplace-wide boilerplate is not about the product, so do not show it as highlights.
$descriptionIsUseful = $description !== ''
    && !preg_match('/only on digihaat|order everything from one website/i', $description);
$aboutDescription = $descriptionIsUseful ? $description : '';
[$descriptionHighlights, $descriptionRest] = split_description_highlights($aboutDescription, 3);
$seenHighlights = [];
$titleKey = strtolower($title);
$descriptionHighlights = array_values(array_filter(array_map(static function (string $line) use (&$seenHighlights, $titleKey): string {
    $line = clean_product_title($line);
    $line = preg_replace('/\s*[-–—]\s*Apparel for (?:Men|Women|Kids|Boys|Girls)\b/iu', '', $line) ?? $line;
    $line = preg_replace('/\s+from\s+[^,]{1,60}?\s+at\s+Rs\.?\s*[\d,.]+.*$/iu', '', $line) ?? $line;
    $line = preg_replace('/\s+at\s+Rs\.?\s*[\d,.]+.*$/iu', '', $line) ?? $line;
    $line = trim((string) preg_replace('/\s{2,}/u', ' ', $line) ?? $line, " \t\n\r-–—|,");
    $line = $line !== '' ? ucfirst($line) : '';
    $key = strtolower(rtrim($line, " ."));
    // Skip a "description" that is just the product name again.
    if ($line === '' || $key === '' || isset($seenHighlights[$key]) || ($titleKey !== '' && str_contains($titleKey, $key))) {
        return '';
    }
    $seenHighlights[$key] = true;
    return $line;
}, $descriptionHighlights)));

// Fill the highlight list with extra specs when the description is short. The
// strongest specs already appear as tiles below, so use different ones here.
$tileKeys = array_map(static function (array $spec): string {
    return strtolower(trim((string) ($spec['key'] ?? '')));
}, $keySpecs);
$secondaryPriorities = [
    'occasion', 'sleeve', 'neck', 'wash', 'length', 'transparency', 'colour', 'color',
    'hemline', 'placket', 'pocket', 'cuff', 'collar', 'weight', 'dimensions', 'warranty',
];
$spareSpecs = [];
foreach ($specs as $spec) {
    $specKey = strtolower(trim((string) ($spec['key'] ?? '')));
    if ($specKey === '' || in_array($specKey, $tileKeys, true)
        || preg_match('/^(material\s*&\s*care|size\s*&\s*fit|product details|body or garment size)$/i', $specKey)) {
        continue;
    }
    $rank = 50;
    foreach ($secondaryPriorities as $index => $needle) {
        if (str_contains($specKey, $needle)) {
            $rank = $index;
            break;
        }
    }
    $spareSpecs[] = [$rank, $spec];
}
usort($spareSpecs, static function (array $a, array $b): int {
    return $a[0] <=> $b[0];
});
$usedNoteTopics = [];
foreach ($spareSpecs as $entry) {
    if (count($descriptionHighlights) >= 3) {
        break;
    }
    $spec = $entry[1];
    $specKey = strtolower((string) ($spec['key'] ?? ''));
    $topic = preg_match('/sleeve/', $specKey) ? 'sleeve'
        : (preg_match('/pattern/', $specKey) ? 'pattern'
        : (preg_match('/occasion/', $specKey) ? 'occasion'
        : (preg_match('/length/', $specKey) ? 'length' : '')));
    if ($topic !== '') {
        if (isset($usedNoteTopics[$topic])) {
            continue;
        }
        $usedNoteTopics[$topic] = true;
    }
    $line = trim((string) ($spec['key'] ?? '')) . ': ' . truncate_text((string) ($spec['value'] ?? ''), 70);
    $key = strtolower(rtrim($line, " ."));
    if (isset($seenHighlights[$key])) {
        continue;
    }
    $seenHighlights[$key] = true;
    $descriptionHighlights[] = $line;
}
$lastCheckedLabel = $lastChecked !== '' ? $lastChecked : 'recently';

$related = fetch_related_products($category, $id, 8);

$priceHistory = fetch_price_history($slug, 40);
$historyStats = null;
if (count($priceHistory) >= 2) {
    $prices = array_map(static function (array $row): float {
        return (float) $row['price'];
    }, $priceHistory);
    $lowest = min($prices);
    $highest = max($prices);
    $lowestDate = '';
    foreach ($priceHistory as $row) {
        if ((float) $row['price'] === $lowest) {
            $lowestDate = human_date($row['recorded_at'] ?? '');
            break;
        }
    }
    $historyStats = [
        'count'       => count($priceHistory),
        'lowest'      => $lowest,
        'lowest_date' => $lowestDate,
        'highest'     => $highest,
        'current'     => (float) $priceHistory[0]['price'],
    ];
}

$shareTitle = $title . ' at ' . ($price > 0 ? inr($price) : 'a great price') . ' on ' . APP_NAME;
$whatsappUrl = 'https://wa.me/?text=' . rawurlencode($shareTitle . ' ' . $productUrl);
$telegramUrl = 'https://t.me/share/url?url=' . rawurlencode($productUrl) . '&text=' . rawurlencode($shareTitle);

$pageTitle = truncate_text($title, 62) . ' – ' . ($price > 0 ? inr($price) : 'Best price') . ' | ' . APP_NAME;
$metaDescription = truncate_text(
    $aboutDescription !== ''
        ? $aboutDescription
        : $title . ' available at ' . ($price > 0 ? inr($price) : 'the best price') . ' on ' . $platform . '.',
    158
);

$schema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $title,
    'description' => truncate_text($aboutDescription !== '' ? $aboutDescription : $metaDescription, 900),
    'sku'         => (string) $id,
    'url'         => $productUrl,
    'brand'       => ['@type' => 'Brand', 'name' => $brand !== '' ? $brand : $platform],
    'category'    => $category !== '' ? $category : 'Deals',
    'offers'      => [
        '@type'         => 'Offer',
        'url'           => $buyUrl,
        'priceCurrency' => 'INR',
        'price'         => number_format(max(0, $price), 2, '.', ''),
        'availability'  => 'https://schema.org/InStock',
        'itemCondition' => 'https://schema.org/NewCondition',
        'seller'        => ['@type' => 'Organization', 'name' => $platform],
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
        [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => $category !== '' ? $category : 'Deals',
            'item' => (function_exists('seo_category_slug_map') && isset(seo_category_slug_map()[$category]))
                ? site_url('deals/' . seo_category_slug_map()[$category])
                : site_url('index.php'),
        ],
        ['@type' => 'ListItem', 'position' => 3, 'name' => truncate_text($title, 80), 'item' => $productUrl],
    ],
];

$faqItems = [
    [
        'q' => 'Is ' . ($price > 0 ? inr($price) : 'this price') . ' the final price on ' . $platform . '?',
        'a' => 'Safa Mart shows the same price as the ' . $platform . ' listing at the last check. Bank offers, coupons or delivery fees shown at checkout can change the final amount.',
    ],
    [
        'q' => 'Where do I complete the purchase?',
        'a' => 'Tap Buy Now to open the official ' . $platform . ' product page. Payment, delivery and returns are handled by ' . $platform . ', not by Safa Mart.',
    ],
    [
        'q' => 'Is this product genuine?',
        'a' => 'Yes. The listing links directly to the official product page on ' . $platform . '. Safa Mart only curates and tracks prices; it does not sell or ship the item.',
    ],
    [
        'q' => 'How often is the price updated?',
        'a' => 'Prices are re-checked regularly and the last verified date is shown near the price at the top of this page.',
    ],
];
$faqSchema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(static function (array $item): array {
        return [
            '@type'          => 'Question',
            'name'           => $item['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
        ];
    }, $faqItems),
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
    <?= seo_robots_meta(true) ?>
    <?= seo_hreflang_links($productUrl) ?>
    <?php foreach (seo_verification_meta() as $verificationTag): ?>
        <?= $verificationTag ?>
    <?php endforeach; ?>
    <?= seo_site_schema() ?>
    <?= seo_analytics_snippet() ?>
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
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/home-v2.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/product-v2.css')) ?>">
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
<script type="application/ld+json"><?= json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</head>
<body class="product-page">
<div class="top-strip product-top-strip">
    <div class="site-container top-strip-inner">
        <?php if ($discount > 0): ?>
            <span class="top-strip-highlight">🔥 <?= $discount ?>% OFF today</span>
            <span class="top-strip-sep" aria-hidden="true">•</span>
        <?php endif; ?>
        <span>Deal verified <?= e($lastCheckedLabel) ?></span>
        <span class="top-strip-sep" aria-hidden="true">•</span>
        <span>Same price as <?= e($platform) ?> — no extra fees</span>
    </div>
</div>

<header class="site-header product-header" id="siteHeader">
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

        <form class="search-form" id="searchForm" action="<?= e(site_url('index.php')) ?>" method="get" role="search">
            <svg class="search-icon" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>
            </svg>
            <input
                type="search"
                id="searchInput"
                name="q"
                value=""
                placeholder="Search sarees, earbuds, shoes…"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search products"
                maxlength="60"
            >
            <button type="submit" class="search-submit">Search</button>
        </form>

        <button type="button" class="header-share" data-share-button aria-label="Share this product">
            <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/>
                <path d="m8.2 10.8 7.6-4.5M8.2 13.2l7.6 4.5"/>
            </svg>
            <span>Share</span>
        </button>
    </div>

    <div class="site-container platform-strip" aria-label="Browse deals by store">
        <a class="platform-pill" href="<?= e(site_url('index.php')) ?>">
            <span class="platform-dot platform-dot-all"></span> All deals
        </a>
        <?php foreach (PLATFORM_FILTERS as $store): ?>
            <a class="platform-pill" href="<?= e(site_url('index.php?platform=' . rawurlencode($store))) ?>">
                <span class="platform-dot platform-dot-<?= e(strtolower($store)) ?>"></span> <?= e($store) ?>
            </a>
        <?php endforeach; ?>
        <a class="platform-pill platform-pill-more" href="<?= e(site_url('index.php')) ?>">
            More deals
            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </a>
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

    <?= ad_slot('product-top') ?>

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
                <?php if ($discount >= 50): ?>
                    <span class="gallery-ribbon">🔥 Deal of the day</span>
                <?php endif; ?>
                <?php if ($primaryImage !== ''): ?>
                    <span class="gallery-zoom-hint" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m16 16 4 4M11 8v6M8 11h6"/></svg>
                        Hover to zoom
                    </span>
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
                <span><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg> Exact <?= e($platform) ?> images</span>
                <span><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg> Price verified <?= e($lastCheckedLabel) ?></span>
                <span><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg> Secure merchant checkout</span>
            </div>
        </section>

        <section class="product-info">
            <div class="product-kicker">
                <span class="platform-badge platform-<?= e($platformClass) ?>"><?= e($platform) ?></span>
                <span class="category-tag"><?= e($category !== '' ? $category : 'Deal') ?></span>
                <?php if ($brand !== ''): ?>
                    <span class="brand-tag"><?= e($brand) ?></span>
                <?php endif; ?>
                <?php if ($featuredBadge !== ''): ?>
                    <span class="brand-tag featured-tag">⭐ <?= e($featuredBadge) ?></span>
                <?php endif; ?>
            </div>

            <h1 class="product-h1"><?= e($title) ?></h1>

            <?php if ($rating > 0): ?>
                <div class="product-rating-row">
                    <span class="rating-badge"><span aria-hidden="true">★</span> <?= e(number_format($rating, 1)) ?></span>
                    <span class="rating-stars" aria-hidden="true"><?= e($stars) ?></span>
                    <?php if ($ratingCount > 0): ?>
                        <span class="rating-text"><?= e(compact_count($ratingCount)) ?> ratings</span>
                    <?php endif; ?>
                    <span class="rating-source">on <?= e($platform) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($price > 0): ?>
                <div class="price-hero">
                    <div class="price-hero-head">
                        <span class="deal-score"><?= e($dealLabel) ?></span>
                        <span class="price-verified-chip"><span class="live-dot"></span> Live deal</span>
                    </div>
                    <div class="price-hero-main">
                        <strong class="hero-price"><?= e(inr($price)) ?></strong>
                        <?php if ($mrp > $price): ?>
                            <span class="hero-mrp"><?= e(inr($mrp)) ?></span>
                            <span class="hero-off"><?= $discount ?>% OFF</span>
                        <?php endif; ?>
                    </div>
                    <p class="hero-tax">Inclusive of all taxes · same price as <?= e($platform) ?></p>
                    <?php if ($savings > 0): ?>
                        <div class="hero-save">
                            <div class="hero-save-text">
                                <strong>You save <?= e(inr($savings)) ?></strong>
                                <span>That's <?= $discount ?>% less than the listed MRP</span>
                            </div>
                            <div class="save-bar" aria-hidden="true"><span style="width: <?= max(4, min(100, $discount)) ?>%"></span></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="price-hero">
                    <div class="price-hero-head">
                        <span class="deal-score"><?= e($dealLabel) ?></span>
                        <span class="price-verified-chip"><span class="live-dot"></span> Live deal</span>
                    </div>
                    <div class="price-hero-main">
                        <strong class="hero-price">Check price on <?= e($platform) ?></strong>
                    </div>
                    <p class="hero-tax">Final price is shown on the merchant page.</p>
                </div>
            <?php endif; ?>

            <div class="deal-reasons">
                <span class="deal-reasons-title">Why this deal is worth it</span>
                <ul>
                    <?php if ($savings > 0): ?>
                        <li><span class="reason-icon">₹</span> <?= $discount ?>% cheaper than MRP — you keep <?= e(inr($savings)) ?></li>
                    <?php endif; ?>
                    <?php if ($rating > 0 && $ratingCount > 0): ?>
                        <li><span class="reason-icon">★</span> <?= e(number_format($rating, 1)) ?>★ from <?= e(compact_count($ratingCount)) ?> shoppers on <?= e($platform) ?></li>
                    <?php endif; ?>
                    <li><span class="reason-icon">🔒</span> Checkout happens on <?= e($platform) ?> — we add no hidden charges</li>
                </ul>
            </div>

            <div class="cta-stack">
                <a class="primary-buy primary-buy-xl" href="<?= e($buyUrl) ?>" target="_blank" rel="nofollow sponsored noopener">
                    <span class="cta-bag" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2"><path d="M5.5 8.5h13l-1.1 11H6.6l-1.1-11Z"/><path d="M9 8.5a3 3 0 0 1 6 0"/></svg>
                    </span>
                    <span class="cta-label">
                        <strong>Grab this deal</strong>
                        <small><?= $price > 0 ? e(inr($price)) . ' on ' . e($platform) : 'on ' . e($platform) ?></small>
                    </span>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
                </a>
                <p class="cta-note"><span class="live-dot"></span> Prices on <?= e($platform) ?> change fast — this deal may not last.</p>
                <div class="trust-row" aria-label="Buying benefits">
                    <span><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg> Secure checkout</span>
                    <span><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg> Genuine product</span>
                    <span><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7"/><path d="M4 4v5h5"/></svg> Merchant returns</span>
                </div>

                <a class="offer-banner" href="<?= e($buyUrl) ?>" target="_blank" rel="nofollow sponsored noopener">
                    <span class="offer-banner-icon" aria-hidden="true">🏷️</span>
                    <span class="offer-banner-text">
                        <strong>Extra offers available on <?= e($platform) ?></strong>
                        <small>Bank offers, coupons &amp; delivery benefits — check at checkout</small>
                    </span>
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
                </a>
            </div>

            <div class="price-comparison-card">
                <div class="price-card-head">
                    <h2>Price breakdown</h2>
                    <span class="price-verified"><span class="live-dot"></span> Verified <?= e($lastCheckedLabel) ?></span>
                </div>
                <div class="price-line"><span>MRP on <?= e($platform) ?></span><span class="<?= $mrp > $price ? 'strike' : '' ?>"><?= $mrp > 0 ? e(inr($mrp)) : '—' ?></span></div>
                <div class="price-line price-line-main"><span>You pay on <?= e($platform) ?></span><strong><?= $price > 0 ? e(inr($price)) : 'Check price' ?></strong></div>
                <?php if ($savings > 0): ?>
                    <div class="price-line price-line-save"><span>Total saving</span><strong><?= e(inr($savings)) ?> · <?= $discount ?>%</strong></div>
                    <div class="savings-track" aria-hidden="true"><span style="width: <?= max(4, min(100, $discount)) ?>%"></span></div>
                <?php endif; ?>
                <div class="price-line"><span>Sold via</span><strong><?= e($platform) ?></strong></div>
                <div class="price-line"><span>Safa Mart fee</span><strong class="fee-zero">₹0 extra</strong></div>
                <p class="price-note">Delivery fee, bank offers and final price are shown on the merchant page and may change at any time.</p>
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

    <section class="product-description product-about" aria-labelledby="aboutProduct">
        <div class="about-head">
            <h2 id="aboutProduct">About this product</h2>
            <span class="about-badge">✓ Details from <?= e($platform) ?></span>
        </div>
        <?php if ($descriptionHighlights): ?>
            <ul class="about-highlights">
                <?php foreach ($descriptionHighlights as $highlight): ?>
                    <li><span class="about-check" aria-hidden="true">✓</span> <?= e($highlight) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($descriptionRest !== ''): ?>
                <details class="about-more">
                    <summary>Read full description</summary>
                    <p><?= e($descriptionRest) ?></p>
                </details>
            <?php endif; ?>
        <?php elseif ($aboutDescription !== ''): ?>
            <p><?= e($aboutDescription) ?></p>
        <?php endif; ?>
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

    <?php if ($historyStats): ?>
        <section class="product-history" aria-labelledby="historyHeading">
            <div class="history-head">
                <h2 id="historyHeading">Price history</h2>
                <span class="history-count"><?= (int) $historyStats['count'] ?> price checks</span>
            </div>
            <div class="history-stats">
                <div>
                    <span>Lowest tracked</span>
                    <strong class="history-low"><?= e(inr($historyStats['lowest'])) ?></strong>
                    <?php if ($historyStats['lowest_date'] !== ''): ?>
                        <small>on <?= e($historyStats['lowest_date']) ?></small>
                    <?php endif; ?>
                </div>
                <div>
                    <span>Highest tracked</span>
                    <strong><?= e(inr($historyStats['highest'])) ?></strong>
                    <small>previous checks</small>
                </div>
                <div>
                    <span>Current price</span>
                    <strong><?= e(inr($historyStats['current'])) ?></strong>
                    <small>on <?= e($platform) ?></small>
                </div>
            </div>
            <p class="history-note">Prices are recorded when Safa Mart refreshes this listing. Marketplace prices can change at any time — confirm on <?= e($platform) ?> before buying.</p>
        </section>
    <?php endif; ?>

    <?php if ($specs): ?>
        <section class="product-specs" aria-labelledby="specsHeading">
            <div class="specs-head">
                <h2 id="specsHeading">Specifications</h2>
                <span class="specs-count"><?= count($specs) ?> details</span>
            </div>
            <?php if ($keySpecs): ?>
                <div class="spec-tiles">
                    <?php foreach ($keySpecs as $spec): ?>
                        <div class="spec-tile">
                            <span><?= e((string) ($spec['key'] ?? '')) ?></span>
                            <strong><?= e(truncate_text((string) ($spec['value'] ?? ''), 46)) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="specs-grid">
                <?php foreach ($specs as $spec): ?>
                    <div class="spec-row">
                        <span><?= e((string) ($spec['key'] ?? '')) ?></span>
                        <strong><?= e((string) ($spec['value'] ?? '')) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="product-faq" aria-labelledby="faqHeading">
        <h2 id="faqHeading">Questions before you buy</h2>
        <details class="faq-item">
            <summary><?= e($faqItems[0]['q']) ?></summary>
            <p><?= e($faqItems[0]['a']) ?></p>
        </details>
        <details class="faq-item">
            <summary><?= e($faqItems[1]['q']) ?></summary>
            <p><?= e($faqItems[1]['a']) ?></p>
        </details>
        <details class="faq-item">
            <summary><?= e($faqItems[2]['q']) ?></summary>
            <p><?= e($faqItems[2]['a']) ?></p>
        </details>
        <details class="faq-item">
            <summary><?= e($faqItems[3]['q']) ?></summary>
            <p><?= e($faqItems[3]['a']) ?></p>
        </details>
    </section>

    <?php if ($related): ?>
        <section class="related-section" aria-labelledby="relatedHeading">
            <div class="section-heading">
                <div>
                    <h2 id="relatedHeading">More deals you'll love</h2>
                    <p>Handpicked <?= e($category !== '' ? strtolower($category) : 'deals') ?> at similar prices</p>
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
    <?= ad_slot('product-bottom') ?>
</main>

<div class="mobile-buybar">
    <div class="mobile-price">
        <span>Deal price</span>
        <strong><?= $price > 0 ? e(inr($price)) : 'Check price' ?></strong>
        <?php if ($mrp > $price && $discount > 0): ?>
            <em><s><?= e(inr($mrp)) ?></s> <?= $discount ?>% off</em>
        <?php elseif ($discount > 0): ?>
            <em><?= $discount ?>% off</em>
        <?php endif; ?>
    </div>
    <a class="mobile-buy-btn" href="<?= e($buyUrl) ?>" target="_blank" rel="nofollow sponsored noopener">
        Buy Now
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
    </a>
</div>

<?php render_site_footer(); ?>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<?php require __DIR__ . '/includes/telegram_button.php'; ?>
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
        <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
        <link rel="stylesheet" href="<?= e(asset_url('assets/css/home-v2.css')) ?>">
    </head>
    <body class="not-found-page">
    <main class="not-found-card">
        <span class="brand-mark not-found-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5.5 8.5h13l-1.1 11H6.6l-1.1-11Z"/><path d="M9 8.5a3 3 0 0 1 6 0"/></svg>
        </span>
        <h1>This deal has moved on</h1>
        <p>The product may have sold out or the link may be incorrect. Explore today's live deals instead.</p>
        <a class="primary-buy not-found-action" href="<?= e(site_url('index.php')) ?>">Browse live deals</a>
        <div class="browse-link-grid not-found-links">
            <a class="category-chip" href="<?= e(site_url('deals/fashion')) ?>">Fashion deals</a>
            <a class="category-chip" href="<?= e(site_url('deals/electronics')) ?>">Electronics deals</a>
            <a class="category-chip" href="<?= e(site_url('deals/under-499')) ?>">Under ₹499</a>
            <a class="category-chip" href="<?= e(site_url('store/myntra')) ?>">Myntra deals</a>
            <a class="category-chip" href="<?= e(site_url('price-drops')) ?>">🔥 Price drops</a>
        </div>
    </main>
    <?php render_site_footer(); ?>
    </body>
    </html>
    <?php
    exit;
}
