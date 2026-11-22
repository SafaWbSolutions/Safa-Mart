# AGENTS.md — Safa Mart rulebook

## Tech Stack & Versions
- Vanilla PHP 8.2+ (no framework, no Laravel, no Composer, no vendor/).
- DB: Supabase/Postgres via REST (`includes/supabase.php`); RLS: anon = select-only, service_role = writes.
- Automation: Python 3.12 scraper (`automation/scraper.py`) on GitHub Actions (`.github/workflows/sync_products.yml`, cron every 4h + on push to automation/**).
- Frontend: vanilla JS (`assets/js/app.js`, `assets/js/pwa.js`), vanilla CSS (`assets/css/`), PWA service worker `sw.js`.
- Hosting: Hostinger; images WebP under `uploads/products/YYYY/MM/`.
- Multi-tenancy: none — single storefront. Do not invent tenant scoping.
- Telegram bot webhook: `api/webhook.php`; web push: `includes/push.php` (VAPID + RFC 8291 aes128gcm).

## Key Directory Map
- `config.php` — constants + includes (`PRODUCT_CATEGORIES`, `PLATFORM_FILTERS`, `PRODUCTS_PER_PAGE`, `HOME_PRODUCT_ORDER`, PUSH_*); secrets via `config.local.php` (gitignored).
- `config.seo.php` — SEO catalog + GA4.
- `index.php` / `product.php` / `browse.php` / `price-drops.php` / `guides.php` / `guide.php` / `sitemap.php` / `offline.php` / `sw.js` / `manifest.webmanifest` — pages/PWA.
- `includes/` — `helpers.php` (infer_category weighted scoring), `supabase.php` (queries, `mix_products_by_category`), `push.php`, `pwa.php`, `seo.php`, `http.php`, `images.php`, `metadata.php`, `featured.php`, `recommend.php`, `semantic.php`, `ai.php`, `guides.php`, `events.php`, `ads.php`, `footer.php`, `landing.php`, `telegram_*.php`.
- `api/` — `webhook.php` (Telegram commands incl. `/recategorize`), `push.php` (subscribe/test/`announce`), `upload.php`, `alerts.php`, `feed.php` (fetch proxy), `recommend.php`.
- `automation/scraper.py` — feeds, round-robin, `infer_category`, `recategorize()`, EarnKaro conversion, image upload.
- `storage/` — `vapid.json`, `push-subscriptions.json`, logs (.htaccess-denied; never commit/read).
- `database.sql` — schema/RLS; `price_history` table needs manual Supabase run.

## Strict Token-Saving Rules
- Never read full large files; grep/search or line ranges first.
- Never read ignored/lock/vendor files, `storage/`, or `.env`/`config.local.php` secrets.
- Never paste full file contents in chat; edit targeted lines, summarize in 2-3 bullets.
- Before editing models/queries: anon RLS is select-only — writes must go through service-key server paths (`supabase_patch`, Telegram `/recategorize`, scraper sync); never expose `SUPABASE_SERVICE_KEY`/`UPLOAD_API_SECRET`.

## Product Rules
- EarnKaro-supported platforms only; single product pages only; auto-hide non-affiliated/junk products.
- Real prices/discounts only; no fabricated urgency; no fake countdowns.
- User-configurable settings must not require editing `config.local.php`.
- Footer credit "Made By : Safa Web Solutions" + Indian flag "Made in India" capsule must stay.
- Live: https://safamart.bis24.in; repo branch `main`; local PHP at `%TEMP%\opencode\php\php.exe`.
