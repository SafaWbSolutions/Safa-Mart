# Safa Mart — Affiliate Storefront & Auto-Listing Engine

A complete, mobile-first affiliate storefront for Indian marketplaces. Products arrive from a real-time Telegram bot or a scheduled GitHub Actions scraper, images are re-hosted as compressed WebP files on Hostinger, and all metadata lives in Supabase.

No cart, no checkout: **Buy Now** tracks the click in Supabase and redirects to the merchant affiliate URL.

---

## What is included

| Path | Purpose |
| --- | --- |
| `config.php` | Central configuration loader and shared includes |
| `database.sql` | Products table, indexes, RLS policies, click RPC |
| `index.php` | Storefront with search, category pills, platform filters and infinite scroll |
| `product.php` | Product detail page with SEO/OpenGraph tags, gallery and price breakdown |
| `go.php` | Click tracker + 302 affiliate redirect |
| `api/webhook.php` | Telegram webhook, EarnKaro conversion, OG/JSON-LD scraping, WebP image saving, Supabase upsert |
| `api/upload.php` | Authenticated image upload/conversion endpoint for GitHub Actions |
| `includes/` | Shared helpers, HTTP client, metadata parser, image pipeline, Supabase client |
| `assets/` | Tailwind-assisted CSS and vanilla JavaScript |
| `uploads/products/` | Local WebP image storage, organised as `YYYY/MM/` |
| `automation/scraper.py` | Bulk feed scraper, affiliate converter, uploader, price/stock verifier |
| `.github/workflows/sync_products.yml` | Cron sync every four hours |
| `logs/` | Private application logs (web access denied) |

---

## Requirements

- PHP **8.2+** with `curl`, `gd` (WebP support), `json`, `dom`, `mbstring`, `openssl`.
- Supabase project (free tier is sufficient).
- Hostinger shared hosting with HTTPS and a writable `uploads/` and `logs/` directory.
- Telegram bot token from **@BotFather**.
- EarnKaro account and API token (for Flipkart, Myntra, Amazon, etc.).
- GitHub repository for the scheduled workflow.

---

## 1. Set up Supabase

1. Create a project at [supabase.com](https://supabase.com).
2. Open **SQL Editor** and run the entire contents of [`database.sql`](database.sql).
3. Open **Project Settings → API** and copy:
   - **Project URL** → `SUPABASE_URL`
   - **anon public** key → `SUPABASE_ANON_KEY`
   - **service_role** key → `SUPABASE_SERVICE_KEY` (server-side only, never in frontend code)
4. Confirm the table exists under **Table Editor → products** and that RLS is enabled.

The SQL creates:

- `products` with unique `slug` and `original_url` (the upsert conflict target).
- Indexes for category/platform filtering, recency, clicks and trigram title search.
- RLS: `anon` can only `SELECT` rows where `is_active = true`.
- `increment_product_click(product_id bigint)` as `SECURITY DEFINER`, executable by `anon`.
- An `updated_at` trigger.

---

## 2. Configure Hostinger

### Upload the project

Upload the repository contents into `public_html` (or a subfolder). Keep the directory structure intact.

### PHP settings

In **hPanel → Advanced → PHP Configuration**:

- Select PHP **8.2** or newer.
- Enable `curl`, `gd`, `dom`, `mbstring`, `json`, `openssl`.
- Confirm `gd` reports **WebP Support: Yes** (PHP Info page).
- Set `upload_max_filesize` and `post_max_size` to at least `12M`.

### Create `config.local.php`

`config.php` reads real environment variables first, then `config.local.php`. On shared hosting the file approach is simplest. Create `public_html/config.local.php` (never commit it):

```php
<?php
return [
    'APP_NAME'                 => 'Safa Mart',
    'APP_ENV'                  => 'production',
    'APP_DEBUG'                => false,
    'SITE_URL'                 => 'https://your-domain.com',
    'SITE_TAGLINE'             => 'Trending deals at the lowest prices',
    'SITE_DESCRIPTION'         => 'Handpicked fashion, electronics, home and beauty deals.',

    'SUPABASE_URL'             => 'https://YOUR-PROJECT.supabase.co',
    'SUPABASE_ANON_KEY'        => 'YOUR-ANON-KEY',
    'SUPABASE_SERVICE_KEY'     => 'YOUR-SERVICE-ROLE-KEY',
    'SUPABASE_TABLE'           => 'products',

    'TELEGRAM_BOT_TOKEN'       => '123456789:AA...',
    'TELEGRAM_WEBHOOK_SECRET'  => 'a-long-random-webhook-secret',
    'TELEGRAM_ALLOWED_CHAT_IDS'=> '123456789', // comma-separated; use /id in the bot

    'EARNKARO_TOKEN'           => 'YOUR-EARNKARO-API-TOKEN',
    // EarnKaro's public link converter (ek.affiliaters.in JWT):
    'EARNKARO_API_URL'         => 'https://ekaro-api.affiliaters.in/api/converter/public',
    // Optional: 3-15 seconds. Keep it small so autoposting never hangs.
    'EARNKARO_TIMEOUT'         => 8,

    'UPLOAD_API_SECRET'        => 'a-different-long-random-upload-secret',
    'PRODUCTS_PER_PAGE'        => 24,
    // Storefront par sirf EarnKaro tracking link wale products dikhayein.
    // false karne par bina commission wale products bhi dikhenge.
    'HIDE_UNAFFILIATED_PRODUCTS' => true,
];
```

Alternatively set the same names as hPanel **Environment Variables** or Apache `SetEnv` values.

### Permissions

```bash
chmod 755 uploads uploads/products logs
```

The PHP user must be able to create `uploads/products/YYYY/MM/` and write `.webp` files. Do **not** use `777`.

The included `.htaccess` files:

- Disable directory listings.
- Block direct web access to `config.php`, `database.sql`, `automation/`, `logs/` and `.git`.
- Prevent PHP execution inside `uploads/`.
- Add long-lived cache headers for WebP images.

### Cloudflare

If Cloudflare proxies the domain:

- SSL/TLS mode: **Full (strict)**.
- Bypass cache for `/api/*` and `/go.php`.
- Do not challenge Telegram webhook POSTs (allow the Telegram IP ranges or create a WAF skip rule for `/api/webhook.php`).
- Cloudflare limits request time. The webhook processes at most two links per message to stay well inside the limit.

### Verify PHP setup

```bash
curl -I https://your-domain.com/index.php
```

You should see `HTTP/2 200`, `X-Content-Type-Options: nosniff`, and the security headers from `security_headers()`.

---

## 3. Set up the Telegram bot

1. Message **@BotFather**, run `/newbot`, and copy the token into `TELEGRAM_BOT_TOKEN`.
2. Generate a webhook secret:

   ```bash
   openssl rand -hex 24
   ```

3. Put it in `TELEGRAM_WEBHOOK_SECRET` and pass the same value to Telegram:

```bash
curl -X POST "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" \
  -F "url=https://your-domain.com/api/webhook.php" \
  -F "secret_token=<WEBHOOK_SECRET>" \
  -F "allowed_updates=[\"message\",\"channel_post\"]"
```

4. Check it:

```bash
curl "https://api.telegram.org/bot<BOT_TOKEN>/getWebhookInfo"
```

5. Send `/id` to the bot and add the returned chat ID to `TELEGRAM_ALLOWED_CHAT_IDS`. Restart is not required; the next request reads the file.
6. Send a product URL. The bot replies:

```text
✅ Listed: <title> - ₹<price>
```

### How the bot works

- Verifies `X-Telegram-Bot-Api-Secret-Token` with `hash_equals`.
- Optional chat allowlist plus per-chat rate limiting.
- Extracts up to two URLs from a message caption/text.
- Rejects brand/category/campaign pages and accepts only single product pages for Myntra, Flipkart, Ajio and Amazon.
- Expands EarnKaro collection/store links (for example a Shopsy seller store) into up to two individual products and copies the affiliate `affid`/`affExtParam` parameters onto each one.
- Converts supported stores (Flipkart, Myntra, Amazon, etc.) through EarnKaro using `Authorization: Bearer <EARNKARO_TOKEN>`.
- Scrapes `og:title`, `og:image`, OpenGraph price and JSON-LD `Product`/`Offer`, and rejects logos, sprites, placeholders and tiny 200x200 share images.
- Downloads up to three images, resizes them, converts to WebP with GD, and stores them under `uploads/products/YYYY/MM/`.
- Upserts to Supabase on `original_url` with `Prefer: resolution=merge-duplicates`.
- Replies with the public product page link.
- `/debug <link>` shows resolution, platform and the EarnKaro conversion result.
- `/repairlinks` re-converts old products in small batches and hides rows whose source is not a single product page.

Telegram retries the same update if it does not receive HTTP 200. The webhook deduplicates update IDs and upserts are idempotent.

### EarnKaro endpoint

The converter is `https://ekaro-api.affiliaters.in/api/converter/public` with `Authorization: Bearer <EARNKARO_TOKEN>` and body `{"deal": "<product url>", "convert_option": "convert_only"}`. Success looks like `{"success":1,"data":"https://..."}`; a failure message in `data` means the store/product is not eligible (the original URL is kept and the bot shows a warning). The token must be the JWT issued by **ek.affiliaters.in** after your EarnKaro account is linked. Old `api.earnkaro.com` values are migrated automatically. For Flipkart, EarnKaro requires your own Flipkart tag; ask your EarnKaro account manager to generate it in **ek.affiliaters.in → Affiliate Settings**.

---

## 4. Configure GitHub Actions

Push the repository to GitHub. Then open **Settings → Secrets and variables → Actions** and add:

| Secret | Required | Value |
| --- | --- | --- |
| `SUPABASE_URL` | Yes | `https://YOUR-PROJECT.supabase.co` |
| `SUPABASE_SERVICE_KEY` | Yes | service_role key |
| `SITE_URL` | Yes | `https://your-domain.com` |
| `UPLOAD_API_SECRET` | Yes | same value as in `config.local.php` |
| `EARNKARO_TOKEN` | Recommended | EarnKaro API token |
| `EARNKARO_API_URL` | Optional | converter endpoint override |
| `FEED_URLS` | Optional | comma/newline-separated EarnKaro-supported category/feed URLs (Flipkart, Myntra, Amazon, etc.) |

The workflow runs at **minute 0 of every fourth hour** (`0 */4 * * *`) and can also be started manually:

**Actions → Sync affiliate products → Run workflow**

Manual inputs:

- `max_products` — default `60`
- `skip_stock_check` — default `false`

### India fetch proxy

GitHub runners sit outside India, where Myntra and a few other stores serve a stripped page with **zero product links**. The workflow therefore sets `FETCH_PROXY_URL` to `<SITE_URL>/api/feed.php`; the scraper fetches merchant HTML through the web server (Indian IP) instead. The endpoint is protected by the `X-Safa-Secret` header, which must match `UPLOAD_API_SECRET`.

### What the sync does

1. Fetches `FEED_URLS`; if empty it uses Flipkart category pages defined in `automation/scraper.py`.
2. Extracts product links from anchors and embedded JSON, and filters to supported marketplaces.
3. Scrapes OpenGraph/JSON-LD metadata and skips out-of-stock or priceless products.
4. Converts affiliate links, downloads images, re-encodes them with Pillow, and uploads to `/api/upload.php` using `X-API-SECRET`.
5. Upserts batches of up to 50 products with `resolution=merge-duplicates`.
6. Checks up to `STOCK_CHECK_LIMIT` (150) active products:
   - `404` / `410` or clear out-of-stock → `is_active = false`.
   - Price changes greater than 2% → update price, MRP and discount.
   - Updates `last_checked_at`.
7. Uploads `automation/sync.log` as a workflow artifact.

### Feed notes

Marketplace anti-bot systems frequently block GitHub datacenter IPs. If a marketplace returns 403/CAPTCHA:

- Point `FEED_URLS` at an EarnKaro-supported feed or a permitted category endpoint.
- Run the scraper from a small VPS instead of GitHub Actions.
- Lower `PRODUCTS_PER_FEED` and `MAX_PRODUCTS` to reduce request volume.

Use only feeds and content you are permitted to access and republish.

---

## Click tracking and redirects

`go.php?id=123`:

1. Loads the active product from Supabase.
2. Calls `increment_product_click(123)` with the anon key.
3. Validates the stored affiliate URL.
4. Returns `302` with `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex`.

Buy links use `target="_blank"` and `rel="nofollow sponsored noopener"`.

---

## Local development

```bash
cd "Safa Mart"
php -S 127.0.0.1:8000
```

Open `http://127.0.0.1:8000/index.php`. The PHP built-in server does not read `.htaccess`; protect files another way in local testing.

Useful scraper checks:

```bash
python -m pip install -r automation/requirements.txt
python automation/scraper.py --dry-run --max-products 5 --verbose
```

`--dry-run` scrapes and logs metadata but does **not** convert links, upload images or write to Supabase.

Test the upload endpoint:

```bash
curl -X POST "https://your-domain.com/api/upload.php" \
  -H "X-API-SECRET: YOUR-UPLOAD-SECRET" \
  -F "image=@sample.jpg" \
  -F "slug=test-product"
```

Expected response:

```json
{"ok":true,"url":"https://your-domain.com/uploads/products/2026/11/test-product-ab12cd34ef.webp","relative":"uploads/products/2026/11/test-product-ab12cd34ef.webp","width":1400,"height":1400,"bytes":123456}
```

---

## Security checklist

- `SUPABASE_SERVICE_KEY` and `UPLOAD_API_SECRET` only exist in `config.local.php`/GitHub Secrets. They are never printed into HTML or JavaScript.
- The browser receives only the Supabase URL and anon key; RLS limits reads to active products.
- Telegram webhook requires the secret header and supports a chat allowlist.
- Every outbound scrape/image URL is checked against private, loopback, link-local and reserved IP ranges (SSRF protection).
- cURL uses `15s` timeouts, follows redirects manually, and caps response sizes.
- Uploaded images are validated with `getimagesizefromstring`, capped at 10 MB, resized and re-encoded as WebP.
- `uploads/` cannot execute PHP. `config.local.php`, logs and automation scripts are blocked by `.htaccess`.
- `hash_equals` protects the upload secret and Telegram webhook secret.
- Rate limits apply to Telegram listings and uploads.
- Affiliate links are disclosed on the storefront and product pages.

Rotate the upload secret, Telegram secret and EarnKaro token if either endpoint is ever exposed.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Storefront is empty | Check `SUPABASE_URL`/`SUPABASE_ANON_KEY`, run `database.sql`, and reload the PostgREST schema cache (`notify pgrst, 'reload schema'` is included). |
| Webhook returns 401 | `TELEGRAM_WEBHOOK_SECRET` and the `secret_token` used in `setWebhook` must match exactly. |
| Bot ignores you | Add your chat ID from `/id` to `TELEGRAM_ALLOWED_CHAT_IDS`. |
| “GD with WebP support is required” | Enable `gd` and WebP in Hostinger PHP settings. |
| Upload returns 401 | `X-API-SECRET` does not match `UPLOAD_API_SECRET`. |
| Upload returns 422 | File is not a supported image, is too large, or the directory is not writable. |
| Images 404 after deploy | `uploads/products/YYYY/MM/` was not uploaded or is not writable. |
| EarnKaro conversion warning | Set `EARNKARO_TOKEN` and the correct `EARNKARO_API_URL`; unsupported stores keep original URL. |
| GitHub scrape gets 403 | Merchant is blocking datacenter IPs; use permitted feed URLs or another runner. |
| Cloudflare 524 on webhook | Send fewer links per message; check the WAF rule for `/api/webhook.php`. |
| Prices outdated | Increase `STOCK_CHECK_LIMIT` or run the workflow more often. |

---

## Licence and affiliate disclosure

This project is provided as-is for operating an affiliate storefront. Product data and images belong to their respective merchants. Ensure your use of marketplace content, EarnKaro, and affiliate links complies with their terms.

Safa Mart earns a commission when a visitor buys through an affiliate link. The customer pays the same price.
