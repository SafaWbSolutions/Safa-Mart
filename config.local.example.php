<?php
/**
 * Safa Mart — config.local.php ka EXAMPLE / reference.
 *
 * IMPORTANT: Is file ko server par config.local.php se REPLACE mat karo.
 * Server wali config.local.php me tumhare real Supabase / Telegram / EarnKaro
 * secrets already hain. Bas jo keys chahiye wo us file ke `return [ ... ];`
 * array ke andar add karo.
 *
 * Ye file sirf batati hai ki kaun-kaun se keys supported hain.
 * config.local.php gitignored hai, isliye deploy usko kabhi overwrite nahi karta.
 */

return [
    // ---------------------------------------------------------------
    // Core
    // ---------------------------------------------------------------
    'APP_ENV'   => 'production',
    'APP_DEBUG' => false,
    'SITE_URL'  => 'https://safamart.bis24.in',

    // ---------------------------------------------------------------
    // Supabase (storefront ke liye zaroori)
    // ---------------------------------------------------------------
    'SUPABASE_URL'      => 'https://YOUR-PROJECT.supabase.co',
    'SUPABASE_ANON_KEY' => 'YOUR-ANON-PUBLIC-KEY',
    // 'SUPABASE_SERVICE_KEY' => 'YOUR-SERVICE-ROLE-KEY', // bot/metadata writes
    // 'SUPABASE_TABLE' => 'products',

    // ---------------------------------------------------------------
    // Telegram bot
    // ---------------------------------------------------------------
    // 'TELEGRAM_BOT_TOKEN'        => '123456:ABC...',
    // 'TELEGRAM_WEBHOOK_SECRET'   => 'random-secret',
    // 'TELEGRAM_ALLOWED_CHAT_IDS' => '123456789,987654321',
    'TELEGRAM_CHANNEL_URL' => 'https://t.me/lootlebuddy',

    // ---------------------------------------------------------------
    // EarnKaro
    // ---------------------------------------------------------------
    // 'EARNKARO_TOKEN'   => 'YOUR-JWT',
    // 'EARNKARO_API_URL' => 'https://ekaro-api.affiliaters.in/api/converter/public',

    // ---------------------------------------------------------------
    // Uploads / automation
    // ---------------------------------------------------------------
    // 'UPLOAD_API_SECRET' => 'same-secret-as-github-actions',

    // ---------------------------------------------------------------
    // Storefront behaviour
    // ---------------------------------------------------------------
    // 'HIDE_UNAFFILIATED_PRODUCTS' => true,
    // 'PRODUCTS_PER_PAGE'          => 24,
    // 'BEST_OFFER_MIN_DISCOUNT'    => 40,
    // 'FEATURED_MAX_ITEMS'         => 12,
    // 'HERO_CAROUSEL_ENABLED'      => true,
    // 'HERO_SLIDE_COUNT'           => 5,
    // 'HERO_AUTOPLAY_MS'           => 5500,
    // 'HOME_PRODUCT_ORDER'         => 'newest', // newest|price_asc|price_desc|discount_desc|rating_desc

    // ---------------------------------------------------------------
    // SEO (in me se kuch bhi optional hai)
    // ---------------------------------------------------------------
    // Search Console ka HTML tag: <meta name="google-site-verification" content="XXX">
    // Sirf XXX wala part yahan daalo.
    'GOOGLE_SITE_VERIFICATION' => '',
    // Bing Webmaster Tools ka msvalidate.01 content
    'BING_SITE_VERIFICATION' => '',
    // GA4 measurement id (optional)
    'GOOGLE_ANALYTICS_ID' => '',
    // Sitemap cache seconds (default 1800)
    // 'SEO_SITEMAP_CACHE_TTL' => 1800,

    // ---------------------------------------------------------------
    // Ads (jab live karna ho tab uncomment karo)
    // ---------------------------------------------------------------
    // 'ADS_ENABLED'     => true,
    // 'ADSENSE_CLIENT'  => 'ca-pub-XXXXXXXXXXXXXXXX',
    // 'AD_SLOTS' => [
    //     'home-top'    => ['slot' => '1234567890'],
    //     'home-mid'    => ['slot' => '0987654321'],
    //     'home-bottom' => ['slot' => '1122334455'],
    //     'product-top' => ['slot' => '5566778899'],
    //     'browse-top'  => ['slot' => '6677889900'],
    // ],
];
