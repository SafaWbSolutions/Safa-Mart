# Spotlight & Ads — Control Guide

Yeh guide batati hai ki home page ke **Spotlight / Best offers rails** aur
**ad slots** ko kaise control karna hai. Koi database change nahi chahiye.

---

## 1. Kisi product ko highlight karna (brand deal ke liye)

### Telegram se (sabse easy)

Bot ko ye commands bhejo:

```
/highlight <slug-ya-id> [din] [badge text]
```

Examples:

```
/highlight ftx-solid-men-multicolor-t-shirt-19797cde 10 Brand Deal
/highlight 78 3 Sponsorisé
```

- `din` optional hai — default **7 din**. Uske baad product apne aap spotlight se hat jayega.
- `badge` optional hai — default **Featured deal**.
- Slug product page ke URL me `?slug=...` ke baad milta hai. Bot `/highlight` ke saath
  poora product page URL bhi accept karta hai.

Baaki commands:

| Command | Kaam |
|---|---|
| `/highlight <slug\|id> [din] [badge]` | Product ko spotlight me daalo |
| `/unhighlight <slug\|id>` | Spotlight se hatao |
| `/highlights` | Abhi kaun-kaun highlighted hai, list dekho |

### Highlight ka asar kya hota hai

1. **Home page Spotlight rail** — 1–2 highlighted products = bada wide card
   (image + price + save + `71% OFF` side panel). 3+ = horizontal rail.
2. **Product card badge** — grid me us product ke image par purple `⭐ Brand Deal` ribbon.
3. **Product page** — kicker me `⭐ Brand Deal` chip.
4. **Auto expiry** — date aate hi file se entry hat jati hai, koi manual kaam nahi.

### File se manually (optional)

Data yahan rehta hai: `uploads/featured.json`

```json
{
  "items": [
    {
      "slug": "my-brand-kurta-12ab34cd",
      "id": 123,
      "badge": "Brand Deal",
      "priority": 0,
      "until": "2026-11-30T23:59:59+05:30",
      "added_at": "2026-11-21T10:00:00+00:00"
    }
  ]
}
```

- `until` khali (`""`) rakho to kabhi expire nahi hoga.
- `priority` zyada = spotlight me pehle dikhega.
- Highlight list max 12 items (`FEATURED_MAX_ITEMS`).

---

## 2. Best offers rail

Home page par har waqt top-discount products ka Flipkart-style rail dikhta hai.

- Minimum discount: `config.local.php` me

```php
'BEST_OFFER_MIN_DISCOUNT' => 40,
```

- 40% se kam wale products rail me nahi aate. Agar 40%+ wale 4 se kam hon,
  system automatically bina threshold ke bhar deta hai (rail khali na dikhe).
- `View all` → `index.php?sort=discount_desc`.

---

## 3. Ads lagana (future-ready)

Ad slots pehle se reserved hain, isliye ads on karne par layout jump nahi hoga.

### Slot positions

| Position | Kahan dikhta hai | Suggested size |
|---|---|---|
| `home-top` | Category strip ke neeche, filters se pehle | 970×90 / responsive |
| `home-mid` | Best offers rail ke baad | 970×90 |
| `home-bottom` | Product grid ke baad | 970×90 |
| `product-top` | Product page breadcrumb ke neeche | responsive |
| `product-bottom` | Related products ke baad | 970×90 |

### Google AdSense

`config.local.php`:

```php
'ADSENSE_CLIENT' => 'ca-pub-1234567890123456',
'AD_SLOTS' => [
    'home-top'    => ['slot' => '1234567890'],
    'home-mid'    => ['slot' => '0987654321'],
    'home-bottom' => ['slot' => '1122334455'],
    'product-top' => ['slot' => '5566778899'],
],
```

- `ADSENSE_CLIENT` set hote hi CSP me Google ke domains automatically add ho jate hain.
- `ads.txt` file root me hai — AdSense approval ke baad usme apna publisher line uncomment karo:

```
google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0
```

### Direct banner (brand deal / apna ad)

```php
'AD_SLOTS' => [
    'home-top' => [
        'image' => 'uploads/ads/brand-sale.jpg',
        'link'  => 'https://example.com/offer',
        'alt'   => 'Brand sale — up to 70% off',
        'label' => 'Sponsored',
    ],
],
```

Banner image `uploads/ads/` me daalo (970×90 ya 728×90).

### Custom HTML ad

```php
'AD_SLOTS' => [
    'home-mid' => [
        'html' => '<a href="https://example.com"><img src="https://example.com/banner.jpg" alt="Offer"></a>',
    ],
],
```

### Preview

Kisi bhi page ke URL me `?ads=preview` lagao — saare khali slots dashed box
me dikhenge, taaki ads live karne se pehle layout check kar sako.

```
https://safamart.bis24.in/index.php?ads=preview
```

Preview sirf usi visitor ko dikhta hai; normal users ko khali slots nahi dikhte.

### Ads band karna

```php
'ADS_ENABLED' => false,
```

---

## 4. Naya product add karte hi kya hota hai

- Telegram bot product publish karta hai → normal grid me aata hai.
- Agar usko highlight karna hai to turant `/highlight <slug> 7 Brand Deal` bhej do.
- Highlight expire hone par file se automatically nikal jata hai.

---

## 5. Files

| File | Kaam |
|---|---|
| `includes/featured.php` | Spotlight store, expiry, best-offer fetch |
| `includes/ads.php` | Ad slot renderer (AdSense / banner / HTML / preview) |
| `assets/css/home-v2.css` | Rails, spotlight cards, ad slot styling |
| `uploads/featured.json` | Runtime highlight list (bot isi ko likhta hai) |
| `ads.txt` | AdSense seller file |
