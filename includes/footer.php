<?php
/**
 * Site-wide footer with internal links. Used on every public page so visitors
 * can keep browsing deals, guides and sale trackers from anywhere.
 */

function render_site_footer(): void
{
    $categories = function_exists('seo_category_catalog') ? seo_category_catalog() : [];
    $stores = function_exists('seo_store_catalog') ? seo_store_catalog() : [];
    $budgets = function_exists('seo_budget_catalog') ? seo_budget_catalog() : [];
    $guides = function_exists('seo_guide_catalog') ? seo_guide_catalog() : [];
    $events = function_exists('seo_event_catalog') ? seo_event_catalog() : [];
    ?>
    <footer class="site-footer">
        <div class="site-container footer-grid footer-grid-rich">
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
                <p class="footer-small">As an affiliate partner, <?= e(APP_NAME) ?> may earn a small commission when you buy through our links. The price you pay does not change.</p>
            </div>

            <div class="footer-links">
                <h3>Shop by category</h3>
                <?php foreach ($categories as $catName => $catMeta): ?>
                    <a href="<?= e(site_url('deals/' . $catMeta['slug'])) ?>"><?= e($catName) ?></a>
                <?php endforeach; ?>
                <a href="<?= e(site_url('price-drops')) ?>">Biggest price drops</a>
            </div>

            <div class="footer-links">
                <h3>Top stores</h3>
                <?php foreach ($stores as $storeName => $storeMeta): ?>
                    <a href="<?= e(site_url('store/' . $storeMeta['slug'])) ?>"><?= e($storeName) ?> deals</a>
                <?php endforeach; ?>
            </div>

            <div class="footer-links">
                <h3>Deals by budget</h3>
                <?php foreach ($budgets as $budgetMeta): ?>
                    <a href="<?= e(site_url('deals/' . $budgetMeta['slug'])) ?>"><?= e($budgetMeta['label']) ?></a>
                <?php endforeach; ?>
                <a href="<?= e(site_url('index.php?sort=discount_desc')) ?>">Biggest discounts</a>
            </div>

            <div class="footer-links">
                <h3>Guides &amp; sales</h3>
                <a href="<?= e(site_url('guides/')) ?>">All buying guides</a>
                <?php foreach (array_slice($guides, 0, 2) as $guide): ?>
                    <a href="<?= e(site_url('guides/' . $guide['slug'])) ?>"><?= e($guide['h1']) ?></a>
                <?php endforeach; ?>
                <?php foreach (array_slice($events, 0, 2) as $event): ?>
                    <a href="<?= e(site_url('sale/' . $event['slug'])) ?>"><?= e($event['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="site-container footer-bottom">
            <span>© <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</span>
            <span class="footer-bottom-links">
                <a href="<?= e(site_url('index.php')) ?>">All deals</a>
                <span aria-hidden="true">·</span>
                <a href="<?= e(site_url('sitemap.xml')) ?>">Sitemap</a>
                <span aria-hidden="true">·</span>
                <a href="<?= e(site_url('feed.xml')) ?>">Deal feed</a>
            </span>
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
    <?php
}
