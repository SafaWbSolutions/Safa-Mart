<?php
require_once __DIR__ . '/config.php';
security_headers();
http_response_code(503);
header('Cache-Control: no-store, max-age=0');
?>
<!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,follow">
    <meta name="theme-color" content="#e91e63">
    <title>You are offline | <?= e(APP_NAME) ?></title>
    <link rel="manifest" href="<?= e(site_url('manifest.webmanifest')) ?>">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: radial-gradient(1200px 600px at 15% -10%, #ffe4ed, transparent 60%),
                        radial-gradient(900px 500px at 110% 10%, #ede9fe, transparent 55%),
                        #fff7fa;
            color: #1f2937;
        }
        .card {
            width: min(520px, 100%);
            background: #fff;
            border: 1px solid #f7d7e3;
            border-radius: 22px;
            padding: 34px 28px 28px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(136, 22, 65, .12);
        }
        .mark {
            width: 66px; height: 66px; margin: 0 auto 14px;
            display: grid; place-items: center;
            border-radius: 20px;
            background: linear-gradient(135deg, #ff4d8d, #e91e63 55%, #7c1d6f);
            color: #fff;
            box-shadow: 0 12px 26px rgba(233, 30, 99, .28);
        }
        h1 { margin: 0 0 8px; font-size: 1.5rem; letter-spacing: -.02em; }
        p { margin: 0 0 20px; color: #6b7280; line-height: 1.6; font-size: .95rem; }
        .retry {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 22px;
            border: 0; border-radius: 999px;
            background: linear-gradient(135deg, #ff4d8d, #e91e63 60%, #c2185b);
            color: #fff; font-weight: 800; font-size: .95rem;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(233, 30, 99, .32);
        }
        .links { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin-top: 22px; }
        .links a {
            text-decoration: none;
            font-size: .82rem; font-weight: 700;
            color: #881641;
            background: #fff1f6;
            border: 1px solid #fecddb;
            padding: 8px 13px;
            border-radius: 999px;
        }
        .hint { margin-top: 18px; font-size: .78rem; color: #9ca3af; }
    </style>
</head>
<body>
<div class="card">
    <div class="mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5.5 8.5h13l-1.1 11H6.6l-1.1-11Z"/><path d="M9 8.5a3 3 0 0 1 6 0"/>
        </svg>
    </div>
    <h1>You're offline</h1>
    <p>Your internet dropped for a moment. Deals you already opened are still available, and everything is back as soon as you reconnect.</p>
    <button class="retry" type="button" onclick="window.location.reload()">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
            <path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/>
        </svg>
        Try again
    </button>
    <div class="links">
        <a href="<?= e(site_url('index.php')) ?>">All deals</a>
        <a href="<?= e(site_url('deals/under-499')) ?>">Under ₹499</a>
        <a href="<?= e(site_url('price-drops')) ?>">Price drops</a>
        <a href="<?= e(site_url('guides/')) ?>">Buying guides</a>
    </div>
    <p class="hint">Tip: install the Safa Mart app for one-tap access and deal alerts.</p>
</div>
</body>
</html>
