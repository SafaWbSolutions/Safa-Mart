<?php
/**
 * Shared header/footer for the SEO content pages (guides, sale trackers).
 */

function render_landing_header(string $activeStore = '', string $topNote = ''): void
{
    $topNote = $topNote !== '' ? $topNote : 'Live deals, updated every few hours';
    ?>
    <div class="top-strip">
        <div class="site-container top-strip-inner">
            <span><?= e($topNote) ?></span>
            <span class="top-strip-sep" aria-hidden="true">•</span>
            <span>Flipkart, Myntra, Amazon, Ajio, Shopsy, DigiHaat</span>
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
            <form class="search-form" id="searchForm" action="<?= e(site_url('index.php')) ?>" method="get" role="search">
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
                <a class="platform-pill <?= $storeName === $activeStore ? 'is-active' : '' ?>"
                   href="<?= e(site_url('store/' . $storeMeta['slug'])) ?>">
                    <span class="platform-dot platform-dot-<?= e(strtolower($storeName)) ?>"></span> <?= e($storeName) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </header>
    <?php
}

function render_landing_footer(): void
{
    if (function_exists('render_site_footer')) {
        render_site_footer();
    }
    if (function_exists('render_pwa_widgets')) {
        render_pwa_widgets();
    }
    require __DIR__ . '/telegram_button.php';
    require __DIR__ . '/telegram_popup.php';
}
