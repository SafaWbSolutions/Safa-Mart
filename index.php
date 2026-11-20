<?php
require_once __DIR__ . '/config.php';
security_headers();

$searchQuery = clean_search_term((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$platform = trim((string) ($_GET['platform'] ?? ''));
if (!in_array($category, PRODUCT_CATEGORIES, true)) {
    $category = '';
}
if (!in_array($platform, PLATFORM_FILTERS, true)) {
    $platform = '';
}

$filters = ['q' => $searchQuery, 'category' => $category, 'platform' => $platform];
$products = fetch_storefront_products($filters, PRODUCTS_PER_PAGE);
$hasMore = count($products) >= PRODUCTS_PER_PAGE;
$hasFilters = $searchQuery !== '' || $category !== '' || $platform !== '';
$configMissing = missing_config(['SUPABASE_URL', 'SUPABASE_ANON_KEY']);

$buildUrl = static function (array $changes) use ($searchQuery, $category, $platform): string {
    $params = ['q' => $searchQuery, 'category' => $category, 'platform' => $platform];
    foreach ($changes as $key => $value) {
        $params[$key] = $value;
    }
    $params = array_filter($params, static function ($value): bool {
        return $value !== '' && $value !== null;
    });
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
};

$pageTitle = $searchQuery !== ''
    ? 'Search results for “' . $searchQuery . '” | ' . APP_NAME
    : ($hasFilters ? 'Deals | ' . APP_NAME : APP_NAME . ' – ' . SITE_TAGLINE);
$pageDescription = $searchQuery !== ''
    ? 'Shop ' . $searchQuery . ' deals from Meesho, Flipkart, Myntra, Ajio and Amazon at the lowest prices.'
    : SITE_DESCRIPTION;
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
    <meta name="robots" content="<?= $searchQuery !== '' ? 'noindex,follow' : 'index,follow' ?>">
    <link rel="canonical" href="<?= e(site_url('index.php')) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <meta property="og:url" content="<?= e(site_url('index.php')) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= e($pageTitle) ?>">
    <meta name="twitter:description" content="<?= e($pageDescription) ?>">
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
    <script>
        window.SAFA_CONFIG = <?= json_encode([
            'supabaseUrl' => SUPABASE_URL,
            'supabaseKey' => SUPABASE_ANON_KEY,
            'table'       => SUPABASE_TABLE,
            'siteUrl'     => rtrim(site_url(), '/'),
            'pageSize'    => PRODUCTS_PER_PAGE,
            'current'     => [
                'q'        => $searchQuery,
                'category' => $category,
                'platform' => $platform,
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

    <?php if (!$hasFilters): ?>
        <section class="hero-banner" aria-label="Safa Mart highlights">
            <div class="hero-copy">
                <span class="hero-eyebrow"><span class="live-dot"></span> Trending on Safa Mart</span>
                <h1>Big savings.<br><span>Zero guesswork.</span></h1>
                <p>Handpicked products from your favourite stores — at prices worth talking about.</p>
                <div class="hero-points">
                    <span>✓ Local fast-loading images</span>
                    <span>✓ Fresh deals every 4 hours</span>
                </div>
            </div>
            <div class="hero-deal-card" aria-hidden="true">
                <span class="hero-deal-label">TODAY'S PICKS</span>
                <strong>Up to 90% off</strong>
                <span class="hero-deal-line"></span>
                <span class="hero-deal-meta">Fashion · Beauty · Home</span>
            </div>
        </section>
    <?php endif; ?>

    <section class="products-section" aria-labelledby="dealsHeading">
        <div class="section-heading">
            <div>
                <h2 id="dealsHeading">
                    <?php
                    if ($searchQuery !== '') {
                        echo 'Results for “' . e($searchQuery) . '”';
                    } elseif ($hasFilters) {
                        echo e($category !== '' ? $category : ($platform !== '' ? $platform : 'Deals'));
                    } else {
                        echo 'Handpicked deals';
                    }
                    ?>
                </h2>
                <p id="resultCount">
                    <?= count($products) > 0 ? e((string) count($products)) . ' deals loaded' : 'Fresh deals are on the way' ?>
                </p>
            </div>
            <?php if ($hasFilters): ?>
                <button type="button" class="clear-filters" data-clear-filters>Clear filters</button>
            <?php endif; ?>
        </div>

        <?php if ($configMissing): ?>
            <div class="notice-card">
                <strong>Store setup incomplete.</strong>
                <p>Add your Supabase URL and anon key to <code>config.local.php</code> on the server, then reload this page.</p>
            </div>
        <?php endif; ?>

        <div class="product-grid" id="productGrid"
             data-offset="<?= count($products) ?>"
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
            <h3 id="emptyTitle"><?= count($products) === 0 ? 'No deals found here yet' : 'Nothing matches these filters' ?></h3>
            <p id="emptyText">Try a different search or clear the filters to see all deals.</p>
            <button type="button" class="empty-action" data-clear-filters>Show all deals</button>
        </div>

        <div class="load-more-wrap <?= $hasMore ? '' : 'is-hidden' ?>" id="loadMoreWrap">
            <button type="button" class="load-more-btn" id="loadMoreBtn">
                <span class="load-more-label">Load more deals</span>
                <span class="btn-spinner" aria-hidden="true"></span>
            </button>
        </div>
        <div id="infiniteSentinel" class="infinite-sentinel" aria-hidden="true"></div>
    </section>
</main>

<footer class="site-footer">
    <div class="site-container footer-grid">
        <div class="footer-brand">
            <a class="brand brand-footer" href="<?= e(site_url('index.php')) ?>">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9">
                        <path d="M5.5 8.5h13l-1.1 11H6.6l-1.1-11Z"/><path d="M9 8.5a3 3 0 0 1 6 0"/>
                    </svg>
                </span>
                <span class="brand-text">Safa<span>Mart</span></span>
            </a>
            <p>Smart deals from India's favourite shopping destinations. We track prices so you don't have to.</p>
        </div>
        <div class="footer-links">
            <h3>Shop by category</h3>
            <?php foreach (PRODUCT_CATEGORIES as $cat): ?>
                <a href="<?= e($buildUrl(['category' => $cat, 'platform' => ''])) ?>"><?= e($cat) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="footer-links">
            <h3>Top stores</h3>
            <?php foreach (PLATFORM_FILTERS as $store): ?>
                <a href="<?= e($buildUrl(['platform' => $store, 'category' => ''])) ?>"><?= e($store) ?> deals</a>
            <?php endforeach; ?>
        </div>
        <div class="footer-disclosure">
            <h3>Affiliate disclosure</h3>
            <p>As an affiliate partner, <?= e(APP_NAME) ?> may earn a small commission when you buy through our links. The price you pay does not change.</p>
            <p class="footer-small">Prices and availability are set by the merchant and can change without notice. Always confirm on the merchant's page.</p>
        </div>
    </div>
    <div class="site-container footer-bottom">
        <span>© <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</span>
        <span>Made for smart shoppers in India.</span>
    </div>
</footer>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
