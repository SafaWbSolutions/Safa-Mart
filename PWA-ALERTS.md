# PWA Install + Deal Alerts (Web Push)

Safa Mart ab install ho sakti hai (home-screen app) aur naye best deal par
browser notification bhej sakti hai. **Koi API key, Composer package ya
Supabase table nahi chahiye** — sab kuch automatically server par ban jata hai.

---

## 1. Visitor ke liye kaise dikhta hai

1. Har page ke bottom-right me ek **🔔 bell** hai (Telegram button ke thoda
   upar). Bell par tap karo.
2. Panel khulta hai:
   - **Enable alerts** — browser permission maangta hai, phir ek welcome
     notification dikhata hai.
   - **Only ping me for** — Fashion, Electronics, Home & Kitchen, Beauty,
     Footwear, Accessories. Khali chhodne par sab categories ke alerts aate
     hain.
   - **Install app** — Chrome/Edge par one-tap install, iPhone par
     _"Share → Add to Home Screen"_ hint.
   - **Send test** — apne device par turant ek test notification.
   - **Turn off alerts** — subscription delete ho jati hai.
3. Footer ke bottom bar me bhi **Install app** button hai.

Installed app alag browser tab jaisa khulta hai (standalone), offline hone par
`offline.php` dikhata hai, aur cached pages turant load karta hai.

---

## 2. Zero setup kyon hai

| Cheez | Kahan | Kaun banata hai |
|---|---|---|
| VAPID key pair | `storage/vapid.json` (0600) | Pehli request par `includes/push.php` |
| Subscriptions | `storage/push-subscriptions.json` | `api/push.php` |
| Semantic/push locks | `storage/*.lock` | runtime |
| Service worker | `sw.js` | repo me committed |
| Manifest + icons | `manifest.webmanifest`, `assets/icons/` | repo me committed |

`storage/` folder `.htaccess` se web se blocked hai aur `.gitignore` se git se
bahar hai. Bas itna dhyan rakho ki Hostinger par `storage/` **writable** ho
(`755` folder). Agar nahi hai to `/pushtest` par error aayega.

> Agar VAPID keys kabhi regenerate karni hon: `storage/vapid.json` delete karo.
> Purani subscriptions invalid ho jayengi, isliye ye tabhi karo jab zaroorat ho.

---

## 3. Notification kab jati hai

- **Automatic:** Telegram par jab bhi naya Best Deal list hota hai (discount
  filter pass karne ke baad), webhook response ke baad ek push burst jata hai.
  - Ek burst har **10 minute** me max (spam se bachne ke liye).
  - Batch mixed categories ka ho to sabko jata hai, warna sirf us category ke
    subscribers ko.
  - Notification ka text live API se aata hai — sabse achha deal (discount +
    budget bonus) uthaya jata hai.
- **Manual:** Telegram commands.

| Command | Kaam |
|---|---|
| `/pushstats` | Kitne devices subscribed, active, failing, last delivery |
| `/pushtest` | Max 25 devices ko ek test alert |
| `/push` | Sab active subscribers ko alert |

Sabhi commands sirf allowlisted Telegram chat se chalte hain.

Band karna ho to `config.seo.php` me:

```php
'PUSH_AUTO_ANNOUNCE' => false,
```

---

## 4. Technical notes (debugging ke liye)

- **Payload encryption nahi hai.** Push message ek "tickle" hai (empty body).
  Service worker jagta hai, `/api/alerts.php` se fresh deal fetch karta hai,
  phir notification dikhata hai. Isliye `openssl` ke alawa koi dependency nahi.
- **VAPID JWT:** ES256, `openssl_sign` se DER signature nikal kar raw `r||s`
  (64 bytes) me convert hota hai. Audience endpoint ke host se banti hai
  (`https://fcm.googleapis.com`, `https://updates.push.services.mozilla.com`,
  `https://web.push.apple.com`).
- **Endpoint allowlist:** sirf real push services accept hote hain. Internal
  IP / random URL wali subscription 400 se reject hoti hai (SSRF safe).
- **Subscription store:** file-based, `flock` se locked, max 5000 rows
  (purane automatically trim). Har row: endpoint hash, p256dh, auth, topics,
  UA, created/updated, ok/fails/last_ok.
- **Failure handling:** 404/410 par row delete; 5 consecutive failures par
  skip; ek run me 5 network failures ke baad broadcast ruk jata hai.
- **API rate limits:** subscribe/unsubscribe 60 req / 10 min / IP; test 5 req /
  10 min / IP. Same-origin check bhi hai.

### Files

| File | Kaam |
|---|---|
| `includes/push.php` | VAPID keys, JWT, send, broadcast, storage |
| `includes/pwa.php` | `<head>` tags, bell/panel markup, JS config |
| `api/push.php` | `config`, `subscribe`, `unsubscribe`, `test` |
| `api/alerts.php` | Notification text + latest deals (SW aur bell dono) |
| `assets/js/pwa.js` | SW registration, install prompt, permission flow |
| `assets/css/pwa.css` | Bell, panel, chips, responsive layout |
| `sw.js` | Cache + push + notificationclick + pushsubscriptionchange |
| `manifest.webmanifest` | Install metadata + shortcuts |

---

## 5. Troubleshooting

| Symptom | Reason / Fix |
|---|---|
| Panel me "Notifications are blocked for this site" | Browser site settings me notifications allow karo, phir reload |
| "Alerts are not configured on the server yet" | `storage/` writable nahi hai, ya openssl EC unsupported. `storage/vapid.json` delete karke dobara try karo |
| iPhone par enable button disabled | Safari me pehle **Add to Home Screen** karo, phir us icon se kholo (iOS 16.4+) |
| Bell ke bajaye kuch nahi dikh raha | Page par `pwa_head_tags()` / `render_pwa_widgets()` include nahi hua |
| Test bheja par notification nahi aayi | OS-level Do Not Disturb / battery saver check karo; Chrome background me ho |
| `/api/push.php?action=config` par `ok:false` | openssl key generation fail — hosting support se `openssl` EC enable karwao (Hostinger par by default on hai) |

---

## 6. Scaling (jab subscribers 1000+ ho jayen)

File store chhote store ke liye kaafi fast hai. Bade volume par:

1. `push-subscriptions` ko Supabase table me shift karo (endpoint hash primary
   key, topics jsonb, fails int, last_ok timestamptz).
2. Broadcast ko queue/cron me daalo (ek HTTP request me 150 pushes hi
   practical hain).
3. Tab `PUSH_MAX_PER_RUN` badhao aur multiple runs schedule karo.

Abhi ke code me sirf `push_subscriptions_load()` / `_save()` badalne honge —
baaki logic same rahega.
