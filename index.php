<?php
require_once __DIR__ . '/config.php';
security_headers();

$searchQuery = clean_search_term((string) ($_GET['q'] ?? ''));
$searchTerms = [];

// Parse Hinglish/English intent (e.g., "500 se kam kadhai", "phone under 15000")
$intent = parse_intent_query($searchQuery);

$category = trim((string) ($_GET['category'] ?? ''));
// Intent category overrides explicit category param if intent has higher confidence
if ($intent['category'] !== '' && $category === '') {
    $category = $intent['category'];
}
if (!in_array($category, PRODUCT_CATEGORIES, true)) {
    $category = '';
}
$platform = trim((string) ($_GET['platform'] ?? ''));
if (!in_array($platform, PLATFORM_FILTERS, true)) {
    $platform = '';
}

$sort = trim((string) ($_GET['sort'] ?? ''));
if (!in_array($sort, ['newest', 'price_asc', 'price_desc', 'discount_desc', 'rating_desc', 'deal_score'], true)) {
    $sort = '';
}
$minPrice = max(0.0, (float) ($_GET['min_price'] ?? 0));
// Intent max_price overrides explicit param if not explicitly set by user
if ($intent['max_price'] > 0 && (float) ($_GET['max_price'] ?? 0) === 0.0) {
    $maxPrice = $intent['max_price'];
} else {
    $maxPrice = max(0.0, (float) ($_GET['max_price'] ?? 0));
}
if ($intent['min_price'] > 0 && (float) ($_GET['min_price'] ?? 0) === 0.0) {
    $minPrice = $intent['min_price'];
} else {
    $minPrice = max(0.0, (float) ($_GET['min_price'] ?? 0));
}
if ($minPrice > 0 && $maxPrice > 0 && $minPrice > $maxPrice) {
    $swapPrice = $minPrice;
    $minPrice = $maxPrice;
    $maxPrice = $swapPrice;
}
$minDiscount = (int) ($_GET['min_discount'] ?? 0);
if (!in_array($minDiscount, [10, 25, 40, 60], true)) {
    $minDiscount = 0;
}
$brand = clean_text((string) ($_GET['brand'] ?? ''));
$brand = function_exists('mb_substr') ? mb_substr($brand, 0, 80, 'UTF-8') : substr($brand, 0, 80);
$minRating = (float) ($_GET['min_rating'] ?? 0);
if (!in_array($minRating, [3.0, 4.0, 4.5], true)) {
    $minRating = 0.0;
}

$filters = [
    'q'            => $intent['search'] !== '' ? $intent['search'] : $searchQuery,
    'q_terms'      => $searchTerms,
    'category'     => $category,
    'platform'     => $platform,
    'sort'         => $sort,
    'min_price'    => $minPrice,
    'max_price'    => $maxPrice,
    'min_discount' => $minDiscount,
    'brand'        => $brand,
    'min_rating'   => $minRating,
];
$products = fetch_storefront_products($filters, PRODUCTS_PER_PAGE);

// If a search has few matches, ask the AI for related product words and retry
// once. With no API key (or a failure) this keeps the plain results.
if ($searchQuery !== '' && count($products) < 6) {
    $expandedTerms = ai_expand_query($searchQuery);
    if (count($expandedTerms) > 1) {
        $searchTerms = $expandedTerms;
        $filters['q_terms'] = $searchTerms;
        $products = fetch_storefront_products($filters, PRODUCTS_PER_PAGE);
    }
}

// Semantic fill: typos and word variants ("kurti", "sneeker") that SQL ILIKE
// cannot catch. Keyword results stay first; vectors only fill the empty slots.
// With zero keyword hits the bar is much higher, so an unrelated category
// ("watch" -> kurta) is never shown as a match.
if ($searchQuery !== '' && count($products) < PRODUCTS_PER_PAGE) {
    $seenIds = [];
    foreach ($products as $seenProduct) {
        $seenIds[] = (int) ($seenProduct['id'] ?? 0);
    }
    $semanticRows = semantic_search_products(
        $searchQuery,
        PRODUCTS_PER_PAGE - count($products),
        $seenIds,
        count($products) === 0 ? 0.20 : 0.10
    );
    if ($semanticRows) {
        $products = array_merge($products, $semanticRows);
    }
}

$facets = fetch_filter_facets();
$hasFilters = $searchQuery !== '' || $category !== '' || $platform !== ''
    || $sort !== '' || $minPrice > 0 || $maxPrice > 0
    || $minDiscount > 0 || $brand !== '' || $minRating > 0;
$configMissing = missing_config(['SUPABASE_URL', 'SUPABASE_ANON_KEY']);

// Home page: take the newest 120 products and let categories take turns so the
// first screen feels like a real store instead of one long row of shirts.
// Filtered pages and category tabs keep the normal newest-first order.
$homeMixed = false;
if (!$hasFilters && $products) {
    $homePool = fetch_storefront_products(['sort' => $sort], 60);
    $mixedHome = mix_products_by_category($homePool, PRODUCTS_PER_PAGE);
    if ($mixedHome) {
        $products = $mixedHome;
        $homeMixed = true;
    }
}

// Deal score sort: compute score for each product and sort descending.
// Only applies when user explicitly chose 'deal_score' and we're not on home mixed view.
if ($sort === 'deal_score' && $products && !$homeMixed) {
    usort($products, static function (array $a, array $b): int {
        $scoreA = compute_deal_score($a);
        $scoreB = compute_deal_score($b);
        return $scoreB <=> $scoreA;
    });
}
$hasMore = $homeMixed ? false : count($products) >= PRODUCTS_PER_PAGE;

// Home page rails: curated spotlight + biggest-discount strip + trending.
$spotlightProducts = $hasFilters ? [] : fetch_spotlight_products(8);
$bestOfferProducts = $hasFilters ? [] : fetch_best_offer_products(12, BEST_OFFER_MIN_DISCOUNT);
$trendingProducts = $hasFilters ? [] : fetch_trending_products(12);
$products = attach_featured_badges($products);
$products = sort_featured_first($products);
$dbProductCount = count($products);

// Highlighted products are pinned to the top of the home grid even when they
// are older than the current page of newest products.
if (!$hasFilters && $spotlightProducts) {
    $gridSeen = [];
    foreach ($products as $gridProduct) {
        $gridSeen[(string) ($gridProduct['slug'] ?? '')] = true;
    }
    $pinned = [];
    foreach ($spotlightProducts as $spotlightProduct) {
        $spotlightSlug = (string) ($spotlightProduct['slug'] ?? '');
        if ($spotlightSlug !== '' && !isset($gridSeen[$spotlightSlug])) {
            $gridSeen[$spotlightSlug] = true;
            $pinned[] = $spotlightProduct;
        }
    }
    if ($pinned) {
        $products = array_merge($pinned, $products);
    }
}

$heroSlides = [];
if (HERO_CAROUSEL_ENABLED && !$hasFilters) {
    $heroSlides = array_slice($bestOfferProducts, 0, HERO_SLIDE_COUNT);
}
$heroPalettes = [
    ['#1c1024', '#6d0d2c', '#e91e63'],
    ['#0b1224', '#16307a', '#38bdf8'],
    ['#170f2b', '#4c1d95', '#c084fc'],
    ['#0b2018', '#065f46', '#34d399'],
    ['#241004', '#9a3412', '#fdba74'],
    ['#1b1026', '#881641', '#f472b6'],
];
$heroDefaultOrder = product_order_clause((string) HOME_PRODUCT_ORDER);
$uiSort = $sort;
if ($uiSort === '' && HOME_PRODUCT_ORDER !== 'newest') {
    $uiSort = HOME_PRODUCT_ORDER;
}

$railMaxDiscount = 0;
foreach ($bestOfferProducts as $railProduct) {
    $railMaxDiscount = max($railMaxDiscount, (int) ($railProduct['discount_percent'] ?? 0));
}

// One or two highlights look best as a wide spotlight card; a larger set
// becomes the horizontal rail.
$useSpotlightHero = count($spotlightProducts) <= 2;

$buildUrl = static function (array $changes) use (
    $searchQuery,
    $category,
    $platform,
    $sort,
    $minPrice,
    $maxPrice,
    $minDiscount,
    $brand,
    $minRating
): string {
    $params = [
        'q'            => $searchQuery,
        'category'     => $category,
        'platform'     => $platform,
        'sort'         => $sort,
        'min_price'    => $minPrice > 0 ? $minPrice : '',
        'max_price'    => $maxPrice > 0 ? $maxPrice : '',
        'min_discount' => $minDiscount > 0 ? $minDiscount : '',
        'brand'        => $brand,
        'min_rating'   => $minRating > 0 ? $minRating : '',
    ];
    foreach ($changes as $key => $value) {
        $params[$key] = $value;
    }
    $params = array_filter($params, static function ($value): bool {
        return $value !== '' && $value !== null && $value !== 0 && $value !== 0.0 && $value !== '0';
    });
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
};

$pageTitle = $searchQuery !== ''
    ? 'Search results for “' . $searchQuery . '” | ' . APP_NAME
    : ($hasFilters ? 'Deals | ' . APP_NAME : APP_NAME . ' – ' . SITE_TAGLINE);
$pageDescription = $searchQuery !== ''
    ? 'Shop ' . $searchQuery . ' deals from Flipkart, Myntra and Amazon at the lowest prices.'
    : SITE_DESCRIPTION;
$indexable = $searchQuery === '' && !$hasFilters;
$ogImage = '';
foreach ($products as $ogProduct) {
    $ogUrl = (string) ($ogProduct['thumbnail_url'] ?? '');
    if (preg_match('#^https?://#i', $ogUrl)) {
        $ogImage = $ogUrl;
        break;
    }
}
$supabaseOrigin = '';
if (SUPABASE_URL !== '') {
    $parts = parse_url(SUPABASE_URL);
    if (!empty($parts['scheme']) && !empty($parts['host'])) {
        $supabaseOrigin = $parts['scheme'] . '://' . $parts['host'];
    }
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
    <?= seo_robots_meta($indexable) ?>
    <link rel="canonical" href="<?= e(site_url('index.php')) ?>">
    <?= seo_hreflang_links(site_url('index.php')) ?>
    <?php foreach (seo_verification_meta() as $verificationTag): ?>
        <?= $verificationTag ?>
    <?php endforeach; ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <meta property="og:url" content="<?= e(site_url('index.php')) ?>">
    <?php if ($ogImage !== ''): ?>
        <meta property="og:image" content="<?= e($ogImage) ?>">
        <meta property="og:image:alt" content="<?= e(APP_NAME) ?> trending deals">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="<?= e($ogImage) ?>">
    <?php else: ?>
        <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <meta name="twitter:title" content="<?= e($pageTitle) ?>">
    <meta name="twitter:description" content="<?= e($pageDescription) ?>">
    <?= seo_site_schema() ?>
    <?= seo_analytics_snippet() ?>
    <?php if ($supabaseOrigin !== ''): ?>
        <link rel="preconnect" href="<?= e($supabaseOrigin) ?>" crossorigin>
    <?php endif; ?>
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
    <?php pwa_head_tags(); ?>
    <script>
        window.SAFA_CONFIG = <?= json_encode([
            'supabaseUrl' => SUPABASE_URL,
            'supabaseKey' => SUPABASE_ANON_KEY,
            'table'       => SUPABASE_TABLE,
            'siteUrl'     => rtrim(site_url(), '/'),
            'pageSize'    => PRODUCTS_PER_PAGE,
            'defaultOrder'=> $heroDefaultOrder,
            'featuredBadges' => featured_badges(),
            'searchTerms' => array_values($searchTerms),
            'current'     => [
                // q stays the raw phrase the shopper typed (search box + heading).
                'q'            => $searchQuery,
                // apiQ is the parsed intent ("shirt" from "shirt under 2000")
                // used for pagination so "load more" matches the first page.
                'apiQ'         => $filters['q'],
                'category'     => $filters['category'],
                'platform'     => $filters['platform'],
                'sort'         => $filters['sort'],
                'minPrice'     => $filters['min_price'],
                'maxPrice'     => $filters['max_price'],
                'minDiscount'  => $filters['min_discount'],
                'brand'        => $filters['brand'],
                'minRating'    => $filters['min_rating'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="<?= e(asset_url('assets/js/app.js')) ?>" defer></script>
</head>
<body>
<div class="top-strip">
    <div class="site-container top-strip-inner">
        <span>Lowest price deals, updated every 4 hours</span>
        <span class="top-strip-sep" aria-hidden="true">•</span>
        <span>Affiliate links support <?= e(APP_NAME) ?></span>
    </div>
</div>

<header class="site-header" id="siteHeader">
    <div class="site-container header-main">
        <a class="brand" href="<?= e(site_url('index.php')) ?>" aria-label="<?= e(APP_NAME) ?> home">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="23" height="23" fill="none" stroke="currentColor" stroke-width="1.9">
                    <path d="M5.5 8.5h13l-1.1 11H6.6l-1.1-11Z"/>
                    <path d="M9 8.5a3 3 0 0 1 6 0"/>
                </svg>
            </span>
            <span class="brand-text">Safa<span>Mart</span></span>
        </a>

        <form class="search-form" id="searchForm" action="index.php" method="get" role="search">
            <svg class="search-icon" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>
            </svg>
            <input
                type="search"
                id="searchInput"
                name="q"
                value="<?= e($searchQuery) ?>"
                placeholder="Search sarees, earbuds, shoes…"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search products"
                maxlength="60"
            >
            <button type="submit" class="search-submit">Search</button>
        </form>

        <div class="header-badge" aria-hidden="true">
            <span class="header-badge-dot"></span>
            <span>Live deals</span>
        </div>
    </div>

    <div class="site-container platform-strip" aria-label="Filter by store">
        <a class="platform-pill <?= $platform === '' ? 'is-active' : '' ?>"
           href="<?= e($buildUrl(['platform' => ''])) ?>" data-platform="">
            <span class="platform-dot platform-dot-all"></span> All
        </a>
        <?php foreach (PLATFORM_FILTERS as $store): ?>
            <a class="platform-pill platform-pill-<?= e(strtolower($store)) ?> <?= $platform === $store ? 'is-active' : '' ?>"
               href="<?= e($buildUrl(['platform' => $store])) ?>" data-platform="<?= e($store) ?>">
                <span class="platform-dot platform-dot-<?= e(strtolower($store)) ?>"></span> <?= e($store) ?>
            </a>
        <?php endforeach; ?>
    </div>
</header>

<main class="site-container storefront-main">
    <nav class="category-strip" aria-label="Shop by category">
        <a class="category-chip <?= $category === '' ? 'is-active' : '' ?>"
           href="<?= e($buildUrl(['category' => ''])) ?>" data-category="">All deals</a>
        <?php foreach (PRODUCT_CATEGORIES as $cat): ?>
            <a class="category-chip <?= $category === $cat ? 'is-active' : '' ?>"
               href="<?= e($buildUrl(['category' => $cat])) ?>" data-category="<?= e($cat) ?>"><?= e($cat) ?></a>
        <?php endforeach; ?>
    </nav>

    <?= ad_slot('home-top') ?>

    <?php
    $activeFilterCount = 0;
    if ($sort !== '') {
        $activeFilterCount++;
    }
    if ($brand !== '') {
        $activeFilterCount++;
    }
    if ($minPrice > 0 || $maxPrice > 0) {
        $activeFilterCount++;
    }
    if ($minDiscount > 0) {
        $activeFilterCount++;
    }
    if ($minRating > 0) {
        $activeFilterCount++;
    }
    ?>
    <details class="filter-panel" <?= $hasFilters ? 'open' : '' ?>>
        <summary class="filter-summary">
            <span class="filter-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 6h16M7 12h10M10 18h4"/>
                </svg>
            </span>
            <span>Filters &amp; sort</span>
            <?php if ($activeFilterCount > 0): ?>
                <span class="filter-count"><?= $activeFilterCount ?> active</span>
            <?php endif; ?>
            <svg class="filter-chevron" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="m6 9 6 6 6-6"/>
            </svg>
        </summary>

        <form class="filter-form" method="get" action="index.php">
            <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= e($searchQuery) ?>"><?php endif; ?>
            <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
            <?php if ($platform !== ''): ?><input type="hidden" name="platform" value="<?= e($platform) ?>"><?php endif; ?>

            <label class="filter-field">
                <span>Sort by</span>
                <select name="sort">
                    <option value="" <?= $uiSort === '' ? 'selected' : '' ?>>Newest first</option>
                    <option value="price_asc" <?= $uiSort === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option>
                    <option value="price_desc" <?= $uiSort === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option>
                    <option value="discount_desc" <?= $uiSort === 'discount_desc' ? 'selected' : '' ?>>Biggest discount</option>
                    <option value="rating_desc" <?= $uiSort === 'rating_desc' ? 'selected' : '' ?>>Top rated</option>
                    <option value="deal_score" <?= $uiSort === 'deal_score' ? 'selected' : '' ?>>Smart Deal Score</option>
                </select>
            </label>

            <?php if (!empty($facets['brands']) || $brand !== ''): ?>
                <label class="filter-field">
                    <span>Brand</span>
                    <select name="brand">
                        <option value="">All brands</option>
                        <?php
                        $brandOptions = $facets['brands'];
                        if ($brand !== '' && !in_array($brand, $brandOptions, true)) {
                            $brandOptions[] = $brand;
                        }
                        ?>
                        <?php foreach ($brandOptions as $brandOption): ?>
                            <option value="<?= e($brandOption) ?>" <?= $brand === $brandOption ? 'selected' : '' ?>><?= e($brandOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <label class="filter-field filter-field-price">
                <span>Price (₹)</span>
                <div class="price-inputs">
                    <input type="number" name="min_price" min="0" step="10" inputmode="numeric"
                           placeholder="<?= $facets['price_min'] > 0 ? (int) $facets['price_min'] : 'Min' ?>"
                           value="<?= $minPrice > 0 ? (int) $minPrice : '' ?>" aria-label="Minimum price">
                    <span aria-hidden="true">–</span>
                    <input type="number" name="max_price" min="0" step="10" inputmode="numeric"
                           placeholder="<?= $facets['price_max'] > 0 ? (int) $facets['price_max'] : 'Max' ?>"
                           value="<?= $maxPrice > 0 ? (int) $maxPrice : '' ?>" aria-label="Maximum price">
                </div>
            </label>

            <label class="filter-field">
                <span>Discount</span>
                <select name="min_discount">
                    <option value="" <?= $minDiscount === 0 ? 'selected' : '' ?>>Any discount</option>
                    <option value="10" <?= $minDiscount === 10 ? 'selected' : '' ?>>10% or more</option>
                    <option value="25" <?= $minDiscount === 25 ? 'selected' : '' ?>>25% or more</option>
                    <option value="40" <?= $minDiscount === 40 ? 'selected' : '' ?>>40% or more</option>
                    <option value="60" <?= $minDiscount === 60 ? 'selected' : '' ?>>60% or more</option>
                </select>
            </label>

            <?php if (!empty($facets['has_rating']) || $minRating > 0): ?>
                <label class="filter-field">
                    <span>Rating</span>
                    <select name="min_rating">
                        <option value="" <?= $minRating <= 0 ? 'selected' : '' ?>>Any rating</option>
                        <option value="3" <?= $minRating === 3.0 ? 'selected' : '' ?>>3★ &amp; up</option>
                        <option value="4" <?= $minRating === 4.0 ? 'selected' : '' ?>>4★ &amp; up</option>
                        <option value="4.5" <?= $minRating === 4.5 ? 'selected' : '' ?>>4.5★ &amp; up</option>
                    </select>
                </label>
            <?php endif; ?>

            <div class="filter-actions">
                <button type="submit" class="filter-apply">Apply filters</button>
                <a class="filter-reset" href="<?= e(site_url('index.php')) ?>">Reset</a>
            </div>
        </form>
    </details>

    <?php if (!$hasFilters): ?>
        <?php
        $heroMaxDiscount = $railMaxDiscount > 0 ? $railMaxDiscount : 90;
        $heroSlideTotal = 1 + count($heroSlides);
        ?>
        <section class="hero-carousel" data-hero-carousel
                 data-autoplay="<?= (int) HERO_AUTOPLAY_MS ?>"
                 aria-roledescription="carousel"
                 aria-label="Today's top deals">
            <div class="hero-track">
                <article class="hero-slide is-active" data-slide aria-hidden="false"
                         style="--hero-a:#1c1024;--hero-b:#6d0d2c;--hero-c:#e91e63">
                    <div class="hero-slide-copy">
                        <span class="hero-eyebrow"><span class="live-dot"></span> Trending on Safa Mart</span>
                        <h1>Big savings.<br><span>Zero guesswork.</span></h1>
                        <p>Handpicked products from your favourite stores — at prices worth talking about.</p>
                        <div class="hero-points">
                            <span>✓ Local fast-loading images</span>
                            <span>✓ Fresh deals every 4 hours</span>
                        </div>
                    </div>
                    <div class="hero-slide-art" aria-hidden="true">
                        <span class="hero-orb hero-orb-a"></span>
                        <span class="hero-orb hero-orb-b"></span>
                        <div class="hero-deal-card">
                            <span class="hero-deal-label">TODAY'S PICKS</span>
                            <strong>Up to <?= (int) $heroMaxDiscount ?>% off</strong>
                            <span class="hero-deal-line"></span>
                            <span class="hero-deal-meta">Fashion · Beauty · Home</span>
                        </div>
                    </div>
                </article>

                <?php foreach ($heroSlides as $heroIndex => $heroProduct): ?>
                    <?php
                    $heroPalette = $heroPalettes[$heroIndex % count($heroPalettes)];
                    $heroTitle = clean_product_title((string) ($heroProduct['title'] ?? 'Product'));
                    $heroPrice = (float) ($heroProduct['price'] ?? 0);
                    $heroMrp = (float) ($heroProduct['mrp'] ?? 0);
                    $heroDiscount = (int) ($heroProduct['discount_percent'] ?? 0);
                    if ($heroDiscount <= 0) {
                        $heroDiscount = calculate_discount($heroMrp, $heroPrice);
                    }
                    $heroSaving = max(0, (float) round($heroMrp - $heroPrice));
                    $heroPlatform = clean_text((string) ($heroProduct['source_platform'] ?? 'Store'));
                    $heroImage = (string) ($heroProduct['thumbnail_url'] ?? '');
                    if (!preg_match('#^https?://#i', $heroImage)) {
                        $heroImage = '';
                    }
                    $heroUrl = site_url('product.php?slug=' . rawurlencode((string) ($heroProduct['slug'] ?? '')));
                    ?>
                    <article class="hero-slide" data-slide aria-hidden="true"
                             style="--hero-a:<?= e($heroPalette[0]) ?>;--hero-b:<?= e($heroPalette[1]) ?>;--hero-c:<?= e($heroPalette[2]) ?>">
                        <div class="hero-slide-copy">
                            <span class="hero-eyebrow"><span class="live-dot"></span> Deal <?= $heroIndex + 1 ?> · <?= e($heroPlatform) ?></span>
                            <span class="hero-offer"><?= $heroDiscount > 0 ? $heroDiscount . '% OFF' : 'Limited deal' ?></span>
                            <h2 class="hero-slide-title"><?= e($heroTitle) ?></h2>
                            <div class="hero-slide-price">
                                <strong><?= $heroPrice > 0 ? e(inr($heroPrice)) : 'Check price' ?></strong>
                                <?php if ($heroMrp > $heroPrice && $heroPrice > 0): ?>
                                    <s><?= e(inr($heroMrp)) ?></s>
                                <?php endif; ?>
                                <?php if ($heroSaving > 0): ?>
                                    <em>Save <?= e(inr($heroSaving)) ?></em>
                                <?php endif; ?>
                            </div>
                            <a class="hero-slide-cta" href="<?= e($heroUrl) ?>" tabindex="-1">
                                Grab this deal
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
                            </a>
                        </div>
                        <div class="hero-slide-art" aria-hidden="true">
                            <span class="hero-orb hero-orb-a"></span>
                            <span class="hero-orb hero-orb-b"></span>
                            <?php if ($heroImage !== ''): ?>
                                <img src="<?= e($heroImage) ?>" alt="" loading="<?= $heroIndex === 0 ? 'eager' : 'lazy' ?>" decoding="async">
                            <?php else: ?>
                                <span class="hero-art-placeholder">🛍️</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($heroSlideTotal > 1): ?>
                <button type="button" class="hero-nav hero-nav-prev" data-hero-prev aria-label="Previous deal">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <button type="button" class="hero-nav hero-nav-next" data-hero-next aria-label="Next deal">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>
                <div class="hero-dots" data-hero-dots role="tablist" aria-label="Choose a deal">
                    <?php for ($dot = 0; $dot < $heroSlideTotal; $dot++): ?>
                        <button type="button" class="hero-dot <?= $dot === 0 ? 'is-active' : '' ?>"
                                data-hero-dot="<?= $dot ?>" role="tab"
                                aria-label="Show slide <?= $dot + 1 ?>"
                                aria-selected="<?= $dot === 0 ? 'true' : 'false' ?>"></button>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($spotlightProducts): ?>
        <section class="deal-rail-section rail-spotlight <?= $useSpotlightHero ? 'spotlight-hero-section' : '' ?>" aria-labelledby="spotlightHeading">
            <div class="rail-head">
                <div class="rail-head-text">
                    <h2 id="spotlightHeading"><span aria-hidden="true">⭐</span> Spotlight deals</h2>
                    <p>Hand-picked by our team — live for a limited time</p>
                </div>
                <a class="rail-link" href="<?= e(site_url('index.php')) ?>">
                    All deals
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </a>
            </div>
            <?php if ($useSpotlightHero): ?>
                <div class="spotlight-hero-grid spotlight-count-<?= count($spotlightProducts) ?>">
                    <?php foreach ($spotlightProducts as $railProduct): ?>
                        <?= render_spotlight_hero_card($railProduct) ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rail-shell">
                    <button type="button" class="rail-arrow rail-prev" data-rail-prev aria-label="Scroll spotlight deals left">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <div class="deal-rail" data-rail>
                        <?php foreach ($spotlightProducts as $railProduct): ?>
                            <?= render_offer_rail_card($railProduct) ?>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="rail-arrow rail-next" data-rail-next aria-label="Scroll spotlight deals right">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </button>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($bestOfferProducts): ?>
        <section class="deal-rail-section rail-best" aria-labelledby="bestOfferHeading">
            <div class="rail-head">
                <div class="rail-head-text">
                    <h2 id="bestOfferHeading"><span aria-hidden="true">🔥</span> Best offers right now</h2>
                    <p>Fresh discounts across every store</p>
                </div>
                <div class="rail-head-actions">
                    <?php if ($railMaxDiscount > 0): ?>
                        <span class="rail-chip">Up to <?= $railMaxDiscount ?>% off</span>
                    <?php endif; ?>
                    <a class="rail-link" href="<?= e(site_url('index.php?sort=discount_desc')) ?>">
                        View all
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </a>
                </div>
            </div>
            <div class="rail-shell">
                <button type="button" class="rail-arrow rail-prev" data-rail-prev aria-label="Scroll best offers left">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <div class="deal-rail" data-rail>
                    <?php foreach ($bestOfferProducts as $index => $railProduct): ?>
                        <?= render_offer_rail_card($railProduct, $index + 1) ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="rail-arrow rail-next" data-rail-next aria-label="Scroll best offers right">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($trendingProducts): ?>
        <section class="deal-rail-section rail-trending" aria-labelledby="trendingHeading">
            <div class="rail-head">
                <div class="rail-head-text">
                    <h2 id="trendingHeading"><span aria-hidden="true">📈</span> Trending this week</h2>
                    <p>Most clicked deals by other shoppers</p>
                </div>
                <div class="rail-head-actions">
                    <a class="rail-link" href="<?= e(site_url('index.php?sort=deal_score')) ?>">
                        View all trending
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </a>
                </div>
            </div>
            <div class="rail-shell">
                <button type="button" class="rail-arrow rail-prev" data-rail-prev aria-label="Scroll trending left">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <div class="deal-rail" data-rail>
                    <?php foreach ($trendingProducts as $index => $railProduct): ?>
                        <?= render_offer_rail_card($railProduct, $index + 1) ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="rail-arrow rail-next" data-rail-next aria-label="Scroll trending right">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>
            </div>
        </section>
    <?php endif; ?>

    <?= ad_slot('home-mid') ?>

    <?php if (!$hasFilters): ?>
        <section class="deal-rail-section for-you-section" id="forYouSection" hidden aria-labelledby="forYouHeading">
            <div class="rail-head">
                <div class="rail-head-text">
                    <h2 id="forYouHeading"><span aria-hidden="true">✨</span> Recommended for you</h2>
                    <p id="forYouNote">Based on the deals you viewed and searched on this device</p>
                </div>
                <a class="rail-link" href="<?= e(site_url('index.php?sort=discount_desc')) ?>">
                    Explore more
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </a>
            </div>
            <div class="product-grid" id="forYouGrid"></div>
        </section>
    <?php endif; ?>

    <section class="products-section" aria-labelledby="dealsHeading">
        <div class="section-heading">
            <div>
                <h2 id="dealsHeading">
                    <?php
                    if ($searchQuery !== '') {
                        echo 'Results for “' . e($searchQuery) . '”';
                    } elseif ($category !== '') {
                        echo e($category) . ' deals';
                    } elseif ($platform !== '') {
                        echo e($platform) . ' deals';
                    } elseif ($hasFilters) {
                        echo 'Filtered deals';
                    } else {
                        echo 'Handpicked deals';
                    }
                    ?>
                </h2>
                <p id="resultCount" aria-live="polite">
                    <?= count($products) > 0 ? e((string) count($products)) . ' deals loaded' : 'Fresh deals are on the way' ?>
                </p>
            </div>
            <button type="button" class="clear-filters" data-clear-filters <?= $hasFilters ? '' : 'hidden' ?>>Clear filters</button>
        </div>

        <?php if ($configMissing): ?>
            <div class="notice-card">
                <strong>Store setup incomplete.</strong>
                <p>Add your Supabase URL and anon key to <code>config.local.php</code> on the server, then reload this page.</p>
            </div>
        <?php endif; ?>

        <div class="product-grid" id="productGrid"
             data-offset="<?= (int) $dbProductCount ?>"
             data-has-more="<?= $hasMore ? '1' : '0' ?>"
             data-initial-count="<?= count($products) ?>">
            <?php foreach ($products as $product): ?>
                <?= render_product_card($product) ?>
            <?php endforeach; ?>
        </div>

        <div class="empty-state <?= count($products) === 0 ? 'is-visible' : '' ?>" id="emptyState">
            <div class="empty-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6">
                    <circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>
                </svg>
            </div>
            <?php
            $emptyTitle = 'No deals found here yet';
            $emptyText = 'Try a different search or clear the filters to see all deals.';
            if ($platform === 'Flipkart') {
                $emptyTitle = 'Flipkart deals are on the way';
                $emptyText = 'New Flipkart offers are being verified. Try Myntra, Shopsy or DigiHaat for live deals right now.';
            } elseif ($platform !== '') {
                $emptyTitle = $platform . ' deals are on the way';
                $emptyText = 'Try another store or category, or clear the filters to see every live deal.';
            } elseif ($category !== '') {
                $emptyTitle = 'No ' . $category . ' deals right now';
                $emptyText = 'Try another category or clear the filters to see every live deal.';
            }
            ?>
            <h3 id="emptyTitle"><?= e($emptyTitle) ?></h3>
            <p id="emptyText"><?= e($emptyText) ?></p>
            <button type="button" class="empty-action" data-clear-filters>Show all deals</button>
        </div>

        <div class="load-more-wrap <?= $hasMore ? '' : 'is-hidden' ?>" id="loadMoreWrap">
            <button type="button" class="load-more-btn" id="loadMoreBtn">
                <span class="load-more-label">Load more deals</span>
                <span class="btn-spinner" aria-hidden="true"></span>
            </button>
        </div>
        <div id="infiniteSentinel" class="infinite-sentinel" aria-hidden="true"></div>
        <?= ad_slot('home-bottom') ?>
    </section>

    <?php if (!$hasFilters): ?>
        <section class="browse-links" aria-labelledby="seoBrowseHeading">
            <h2 id="seoBrowseHeading">Shop deals by category and store</h2>
            <div class="browse-link-grid">
                <?php foreach (seo_category_catalog() as $seoCatName => $seoCatMeta): ?>
                    <a class="category-chip" href="<?= e(site_url('deals/' . $seoCatMeta['slug'])) ?>"><?= e($seoCatName) ?> deals</a>
                <?php endforeach; ?>
                <?php foreach (seo_store_catalog() as $seoStoreName => $seoStoreMeta): ?>
                    <a class="category-chip" href="<?= e(site_url('store/' . $seoStoreMeta['slug'])) ?>"><?= e($seoStoreName) ?> deals</a>
                <?php endforeach; ?>
            </div>

            <h2 style="margin-top:22px">Deals by budget</h2>
            <div class="browse-link-grid">
                <?php foreach (seo_budget_catalog() as $seoBudgetMeta): ?>
                    <a class="category-chip" href="<?= e(site_url('deals/' . $seoBudgetMeta['slug'])) ?>"><?= e($seoBudgetMeta['label']) ?> deals</a>
                <?php endforeach; ?>
            </div>

            <h2 style="margin-top:22px">Buying guides &amp; sale trackers</h2>
            <div class="browse-link-grid">
                <a class="category-chip" href="<?= e(site_url('price-drops')) ?>">🔥 Biggest price drops</a>
                <?php foreach (seo_guide_catalog() as $seoGuideMeta): ?>
                    <a class="category-chip" href="<?= e(site_url('guides/' . $seoGuideMeta['slug'])) ?>"><?= e($seoGuideMeta['h1']) ?></a>
                <?php endforeach; ?>
                <?php foreach (seo_event_catalog() as $seoEventMeta): ?>
                    <a class="category-chip" href="<?= e(site_url('sale/' . $seoEventMeta['slug'])) ?>"><?= e($seoEventMeta['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="browse-copy" aria-labelledby="seoAboutHeading">
            <h2 id="seoAboutHeading"><?= e(APP_NAME) ?> — India's smart deal discovery site</h2>
            <p><?= e(APP_NAME) ?> collects live discounts from Flipkart, Myntra, Amazon, Ajio, Shopsy and DigiHaat in one clean feed. Instead of scrolling six apps, you browse one page where every product shows the current price, the listed MRP, the real discount percentage and the store it comes from.</p>
            <p>We only list products that have a working affiliate link and a direct store page. When you tap <strong>Buy Now</strong> you go straight to the official merchant listing — the price stays the same and checkout, delivery and returns are handled by the store you already trust.</p>
            <h2>Fresh deals, honest discounts</h2>
            <p>New offers are added through the day and old or unavailable products are hidden automatically. Use the filters to sort by biggest discount, lowest price, top rating or brand, or open a <a href="<?= e(site_url('deals/fashion')) ?>">fashion</a>, <a href="<?= e(site_url('deals/electronics')) ?>">electronics</a> or <a href="<?= e(site_url('deals/home-kitchen')) ?>">home &amp; kitchen</a> collection to see a focused list.</p>
        </section>
    <?php endif; ?>
</main>

<?php render_site_footer(); ?>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<?php render_pwa_widgets(); ?>
<?php require __DIR__ . '/includes/telegram_button.php'; ?>
<?php require __DIR__ . '/includes/telegram_popup.php'; ?>
</body>
</html>
