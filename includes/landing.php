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
    ?>
    <footer class="site-footer">
        <div class="site-container footer-bottom">
            <span>© <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</span>
            <span>Made for smart shoppers in India.</span>
            <div class="made-by-credit">
                <span class="indian-flag" role="img" aria-label="Indian flag" title="India">
                    <svg viewBox="0 0 30 20" width="26" height="18" aria-hidden="true" focusable="false">
                        <defs><clipPath id="creditFlagClip"><rect width="30" height="20" rx="3"/></clipPath></defs>
                        <g clip-path="url(#creditFlagClip)">
                            <rect width="30" height="6.67" fill="#FF9933"/>
                            <rect y="6.67" width="30" height="6.66" fill="#FFFFFF"/>
                            <rect y="13.33" width="30" height="6.67" fill="#138808"/>
                            <circle cx="15" cy="10" r="2.9" fill="none" stroke="#000080" stroke-width="0.9"/>
                            <circle cx="15" cy="10" r="0.7" fill="#000080"/>
                        </g>
                    </svg>
                </span>
                <span class="made-by-text">
                    <span class="made-by-label">Made by</span>
                    <span class="made-by-name">Safa Web Solutions</span>
                </span>
                <span class="credit-divider" aria-hidden="true"></span>
                <span class="made-in-india">Made in India</span>
            </div>
        </div>
    </footer>
    <?php require __DIR__ . '/telegram_button.php'; ?>
    <?php require __DIR__ . '/telegram_popup.php'; ?>
    <?php
}
