# AI Recommendations & Smart Search

Safa Mart ke paas do layers hain:

1. **Behaviour-based recommender** — fast, deterministic, bina kisi API ke.
2. **AI query expansion (optional)** — OpenRouter free model se, sirf tab jab
   normal search me kam results mile.

Dono fallback-safe hain: API key na ho, network fail ho, ya localStorage block
ho — site normal chalti rahegi.

---

## 1. Privacy (important)

- User ka behaviour **sirf uske browser me** (localStorage key
  `safa_behaviour_v1`) store hota hai: recently viewed products, last searches,
  category/store interest.
- Server us behaviour ko **save nahi karta**; sirf recommendation calculate
  karke cards wapas bhejta hai.
- Koi account, cookie, IP-based profile ya personal data AI ko nahi jata.
- Search text **hi** OpenRouter ko jata hai (jab AI expansion chale), aur wahi
  cache ho jata hai. Naam/email/phone kabhi nahi.

---

## 2. Setup (2 minutes)

`config.seo.php` me:

```php
'OPENROUTER_API_KEY' => 'sk-or-v1-...',
'OPENROUTER_MODEL'   => 'meta-llama/llama-3.3-70b-instruct:free',
'AI_RECOMMENDATIONS' => true,
'AI_QUERY_EXPANSION' => true,
```

- Free key: <https://openrouter.ai/keys>
- Free models list: <https://openrouter.ai/models?max_price=0>
- Model change karna ho to sirf `OPENROUTER_MODEL` badlo.

Key khali chhodo to AI features off, lekin **behaviour recommender chalta
rahega** (wo AI par depend nahi karta).

---

## 3. Product page — "More deals picked for you"

Server pehle normal related products render karta hai (SEO ke liye). Browser me
2+ signals hone par JS `api/recommend.php` se personalised cards laata hai aur
grid replace kar deta hai.

Scoring ( `includes/recommend.php` ):

| Signal | Points |
|---|---|
| Same category as current product | +45 |
| Category present in viewed products | +12 |
| Title keyword overlap (kurta, cotton, shirt…) | up to +42 |
| Price within ±25% of current/avg viewed | +20 |
| Price within ±50% | +10 |
| Same store | +6 |
| Discount % | up to +22 |
| Rating | up to +12 |
| Already viewed | −20 |
| Small random jitter | 0–5 |

Diversity: max **5 per category**, **4 per store**, phir fill. Isliye "sab
Shopsy" ya "sab kurta" nahi hota — budget aur category ke hisaab se mix aata hai.

Candidate pool 200 products ka hota hai aur 10 minute cache hota hai
(`uploads/recommend-pool.json`).

---

## 4. Home page — "Recommended for you"

- Section sirf tab dikhta hai jab us device par 2+ signals hon (2 viewed
  products ya views + search).
- Pehli visit par section hidden rehta hai — koi khali box nahi.
- Products mix hote hain: recent views + searches + top discounts.

---

## 5. Smart search (AI query expansion)

Search do steps me kaam karta hai:

**Step 1 — built-in synonyms (hamesha on, offline)**

`includes/ai.php` me ek chhota Indian-fashion synonym map hai:

```
salwar  → kurta, palazzo, dupatta
kurti   → kurta, palazzo
shoes   → sneakers, sandals, footwear
earbuds → buds, headphones
...
```

Isliye "salwar suit" search abhi bhi kurta/palazzo products dikhata hai — bina
kisi API ke.

**Step 2 — AI expansion (optional)**

Agar query ke **6 se kam results** mile aur OpenRouter key configured ho, tab
AI se 3–6 extra product words mangaye jate hain aur search dobara chalti hai.

Example:

```
User search: "indo western set"
Catalogue me sirf "kurta", "palazzo", "dupatta" hai
→ AI terms: ["indo western set", "kurta set", "palazzo set", "co-ord"]
→ Supabase OR query in sab par chalti hai
```

- Cache: `uploads/ai-cache/query-<hash>.json`
  - success: 30 din
  - failure: 1 ghanta (taaki har search par retry na ho)
- Timeout: 10 seconds, uske baad built-in synonyms + normal results.
- Original query hamesha terms me first hoti hai — behaviour kabhi kharab nahi
  hota.

> Isliye normal searches (jinme pehle se results hain) par **koi AI latency
> nahi** hoti.

---

## 6. Config knobs

| Setting | File | Default |
|---|---|---|
| `AI_RECOMMENDATIONS` | `config.seo.php` | `true` |
| `AI_QUERY_EXPANSION` | `config.seo.php` | `true` |
| `OPENROUTER_MODEL` | `config.seo.php` | Llama 3.3 70B free |
| Category cap | `includes/recommend.php` | 5 |
| Store cap | `includes/recommend.php` | 4 |
| Pool size | `includes/recommend.php` | 240 candidates |
| Pool cache | `recommendation_pool()` | 10 min |

---

## 7. Limitations (honest)

- Behaviour **device-specific** hai. User phone se dekhe aur laptop se khole to
  recommendation reset ho jayega (privacy by design).
- Incognito / localStorage clear / ad-blocker = no personalisation, but normal
  site chalti rahegi.
- Free OpenRouter models par rate limit ho sakti hai; failure cache + plain
  search fallback handle karta hai.
- Search expansion **sirf tab** hota hai jab results kam hon — isliye normal
  queries par koi extra latency nahi.

---

## 8. Future upgrades (agar chaho)

- Server-side anonymous session (signed cookie) for cross-page continuity
- Embedding-based semantic search (pgvector) — AI se better aur sasta
- "Price drop alert" recommendations using `price_history`
- AI-generated short highlights per product (offline batch, human review)
