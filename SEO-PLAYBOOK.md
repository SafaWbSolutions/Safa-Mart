# Safa Mart — SEO Playbook (On-page + Off-page)

Ye file batati hai ki site me kya-kya SEO already laga hua hai aur Google par
ranking ke liye aage kya karna hai.

---

## 1. On-page SEO — jo already live hai

### Har page par
- Unique `<title>` aur meta description
- Canonical URL
- `hreflang` (en-IN + x-default)
- Open Graph + Twitter card (WhatsApp/Telegram share preview)
- `robots` meta with `max-image-preview:large`
- Schema.org `Organization` + `WebSite` (SearchAction ke saath)
- Breadcrumb schema
- Mobile-first responsive layout, HTTPS, fast WebP images

### Home page
- H1 + keyword-rich intro copy
- Category aur store ke internal links (`/deals/fashion`, `/store/myntra`)
- Filter/search URLs `noindex` (duplicate content se bachne ke liye)

### Product pages
- Unique title: `Product Name – ₹price | Safa Mart`
- `Product` schema: price, availability, brand, category, seller, rating
- `BreadcrumbList` + `FAQPage` schema
- Real FAQ block (price, checkout, genuine product, update frequency)
- Specs, ratings, related products — thin content nahi

### Category / store landing pages
- `/deals/<category>` — unique title, H1, intro + long copy, 48 products
- `/store/<store>` — same treatment, har platform ke liye alag copy
- `CollectionPage` + `ItemList` + breadcrumb schema
- Ye pages "myntra deals", "fashion deals india" jaise queries target karte hain

### Technical files
| URL | Kaam |
|---|---|
| `/sitemap.xml` | Saare active products + landing pages, auto-generated |
| `/robots.txt` | Crawl rules + sitemap reference |
| `/feed.xml` | Google Merchant Center product feed (RSS 2.0) |
| `/ads.txt` | AdSense seller file |

---

## 2. Google Search Console setup (sabse pehle)

1. <https://search.google.com/search-console> par jao → **Add property** →
   **URL prefix**: `https://safamart.bis24.in/`
2. **HTML tag** verification choose karo. Tag aisa dikhega:
   ```html
   <meta name="google-site-verification" content="ABC123..." />
   ```
3. Sirf `content` value copy karo aur repo ki **`config.seo.php`** file me daalo:
   ```php
   'GOOGLE_SITE_VERIFICATION' => 'ABC123...',
   ```
   Ye file committed hai, isliye GitHub par edit + commit karte hi Hostinger
   auto-deploy ho jayegi. `config.local.php` chhedne ki zaroorat nahi.
4. Site reload karo, phir Search Console me **Verify** dabao.
5. **Sitemaps** → `sitemap.xml` submit karo.
6. **URL Inspection** → homepage URL daal ke **Request indexing**.

> Bing ke liye <https://www.bing.com/webmasters> par same tarika, config.seo.php
> me `'BING_SITE_VERIFICATION' => '...'`.

### GA4 (traffic dekhne ke liye, optional)
1. <https://analytics.google.com> → property banao → Web stream.
2. Measurement ID (`G-XXXXXXXXXX`) `config.seo.php` me:
   ```php
   'GOOGLE_ANALYTICS_ID' => 'G-XXXXXXXXXX',
   ```

---

## 3. IndexNow — Bing/Yandex instant indexing

Naya product publish hote hi bot khud Bing/Yandex ko URL bhej deta hai.
Key file already live hai: `/a3f9c2b7d4e6f8102c4b6d8e0f2a4c6e.txt`

- Manual full ping: Telegram me `/indexnow`
- Endpoint: `https://api.indexnow.org/indexnow`
- Key config me: `config.seo.php` → `INDEXNOW_KEY`

Bing Webmaster Tools me site add karo (<https://www.bing.com/webmasters>) →
GSC se import kar lo → sitemap submit karo.

---

## 4. Content landing pages (live)

| URL | Kya target karta hai |
|---|---|
| `/deals/under-499` | "deals under 499", budget shoppers |
| `/deals/under-999` | "deals under 999" |
| `/deals/under-1999` | "deals under 1999" |
| `/guides/` | Buying guide hub |
| `/guides/best-mens-shirts-under-499` | "best shirts under 500" |
| `/guides/best-kurtas-under-599` | "kurtas under 600" |
| `/sale/big-billion-days` | Flipkart BBD tracker |
| `/sale/end-of-reason-sale` | Myntra EORS tracker |
| `/sale/great-indian-festival` | Amazon GIF tracker |
| `/sale/ajio-big-bold-sale` | Ajio sale tracker |
| `/price-drops` | "price drop tracker India" |

Naya guide add karna ho to **`includes/guides.php`** me ek entry daalo — page, sitemap
aur home links automatically ban jayenge.

### Price history (optional, ek baar SQL chalana hai)

Supabase SQL Editor me `database.sql` ka **price_history** section chalao:

```sql
create table if not exists public.price_history (
    id bigserial primary key,
    product_id bigint,
    slug text not null,
    price numeric(12,2) not null,
    mrp numeric(12,2) not null default 0,
    recorded_at timestamptz not null default now()
);

create index if not exists price_history_slug_idx
    on public.price_history (slug, recorded_at desc);

alter table public.price_history enable row level security;

create policy "Public can read price history"
    on public.price_history for select to anon, authenticated using (true);

grant select on public.price_history to anon, authenticated;
grant all privileges on public.price_history to service_role;
```

Uske baad har scrape / Telegram listing price snapshot save karega aur
`/price-drops` + product page ka "Price history" block apne aap live ho jayega.
Table na ho to kuch break nahi hota — bas section hidden rehta hai.

---

## 5. Google Merchant Center (free shopping traffic)
1. <https://merchants.google.com> par account banao.
2. **Products → Feeds → Add feed** → **Scheduled fetch**.
3. Feed URL: `https://safamart.bis24.in/feed.xml`
4. Country: India, Currency: INR, Language: en.
5. Claim website verify karo (same Search Console tag kaam karega).
6. Feed me `g:id`, title, description, image, price, availability sab already
   included hai.

---

## 6. Off-page SEO — backlinks aur brand signals

Google ranking me links aur brand mentions bade factor hain. Ye kaam **regular**
karne padenge:

### A. Apni properties banao (easy links)
- Telegram channel → channel description me website link
- Instagram / Facebook page / X (Twitter) / Pinterest profile — bio me link
- YouTube channel (short deal videos) — description me link
- WhatsApp Channel / WhatsApp status
- Quora profile, Reddit profile (spam nahi, genuine answers)

### B. High-value free listings
- Google Business Profile (agar local pickup/store nahi hai to "online only"
  brand profile bana sakte ho — naam, website, category "Shopping service")
- Bing Places
- Indian startup directories: StartupIndia, YourStory profile, Inc42 directory
- Product/deal communities: DesiDime, IndiaFreeStuff, Telegram deal groups
  (apne channel ka link, spam nahi)

### C. Content jo backlinks laata hai
- **Weekly "Best deals under ₹499 / ₹999" post** — log share karte hain
- **Festival sale pages** (Big Billion Days, End of Reason Sale, Black Friday)
  — sale ke 1 hafte pehle publish karo, ₹price comparison table ke saath
- **Buying guides** ("Best earbuds under ₹1500 in India") — affiliate + info
- Har post me apne product pages ke internal links

### D. Backlink outreach (hafte me 5-10 emails)
Targets:
- Indian deal blogs aur Telegram channels
- College/city lifestyle blogs
- "Best deals India" listicles — unko email karo ki apni site add karein
- Broken link building: purane deal sites ke dead links dhoondo

Email template:
```
Subject: Free deal feed for your readers

Hi <name>,
I run Safa Mart (safamart.bis24.in), a deal discovery site that tracks live
discounts from Flipkart, Myntra, Amazon, Ajio and Shopsy.
Agar aapko apne readers ke liye daily deals chahiye, humara free feed use kar
sakte ho: https://safamart.bis24.in/feed.xml
Koi paid placement nahi chahiye — bas ek genuine resource share kar raha hoon.
Thanks!
```

### E. Social proof
- Telegram members ko encourage karo ki site share karein
- Har product page pe WhatsApp share button already hai — use karao
- Reviews/testimonials maango (Google Business Profile par)

---

## 7. Content calendar (pehle 90 din)

| Week | Kaam |
|---|---|
| 1 | Search Console + sitemap submit, GA4, Merchant Center feed |
| 2 | 6 category landing pages ko social par share karo |
| 3 | "Best deals under ₹499" blog-style page (home ke SEO section ko expand karo) |
| 4 | 5 backlink outreach emails |
| 5-8 | Har hafte 1 buying guide + 5 outreach emails |
| 9-12 | Festival sale landing page, Pinterest pins, YouTube shorts |

---

## 8. Monthly SEO checklist

- [ ] Search Console → Coverage/Indexing errors check karo
- [ ] Sitemap last crawl date aur submitted URLs dekho
- [ ] Top queries report → jis keyword par impressions hain, uska content improve karo
- [ ] Core Web Vitals report (mobile) — LCP 2.5s se kam rakho
- [ ] Broken links / 404s fix karo
- [ ] Naye backlinks count karo (Search Console → Links)
- [ ] Feed errors Merchant Center me dekho
- [ ] Purane products ke titles/descriptions refresh karo

---

## 9. Kya avoid karna hai

- Fake reviews ya fake `aggregateRating` (Google manual action)
- Keyword stuffing (same keyword bar-bar)
- Duplicate product descriptions
- Paid backlink networks / PBN
- Cloaking (Google ko kuch aur, user ko kuch aur)
- Thin pages bina content ke

---

## 10. SEO files map

| File | Kaam |
|---|---|
| `includes/seo.php` | Schema, sitemap, feed, landing copy |
| `sitemap.php` | `/sitemap.xml` |
| `feed.php` | `/feed.xml` (Merchant Center) |
| `browse.php` | `/deals/*` aur `/store/*` landing pages |
| `robots.txt` | Crawl rules |
| `.htaccess` | Pretty URLs + sitemap/feed rewrites |
| `index.php`, `product.php` | Meta tags, schema, internal links |
