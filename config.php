<?php
/**
 * Safa Mart - central configuration.
 *
 * Values are read from, in order:
 *   1. Real environment variables / Apache SetEnv values.
 *   2. config.local.php (recommended on Hostinger; keep it out of version control).
 *   3. Safe defaults below.
 *
 * Never expose SUPABASE_SERVICE_KEY or UPLOAD_API_SECRET to the browser.
 */

$safaLocalConfig = [];
$safaLocalConfigFile = __DIR__ . '/config.local.php';
if (is_file($safaLocalConfigFile)) {
    $safaLoadedConfig = require $safaLocalConfigFile;
    if (is_array($safaLoadedConfig)) {
        $safaLocalConfig = $safaLoadedConfig;
    }
}

// Optional public settings file (safe to commit): verification tags, GA4, ads.
// config.local.php still wins when both define the same key.
$safaSeoConfigFile = __DIR__ . '/config.seo.php';
if (is_file($safaSeoConfigFile)) {
    $safaSeoConfig = require $safaSeoConfigFile;
    if (is_array($safaSeoConfig)) {
        $safaLocalConfig = array_merge($safaSeoConfig, $safaLocalConfig);
    }
}

$GLOBALS['SAFA_LOCAL_CONFIG'] = $safaLocalConfig;
unset($safaLocalConfigFile, $safaLoadedConfig, $safaSeoConfigFile, $safaSeoConfig);

/**
 * Read a configuration value without ever returning an empty string.
 *
 * @param mixed $default
 * @return mixed
 */
function safa_config_value(string $key, $default = null)
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
    }
    if (($value === null || $value === '') && array_key_exists($key, $GLOBALS['SAFA_LOCAL_CONFIG'])) {
        $value = $GLOBALS['SAFA_LOCAL_CONFIG'][$key];
    }
    return ($value === null || $value === '') ? $default : $value;
}

/** @param mixed $value */
function safa_define(string $name, $value): void
{
    if (!defined($name)) {
        define($name, $value);
    }
}

/** @param mixed $value */
function safa_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

$siteUrl = rtrim(trim((string) safa_config_value('SITE_URL', '')), '/');
$supabaseUrl = rtrim(trim((string) safa_config_value('SUPABASE_URL', '')), '/');
if (substr($supabaseUrl, -8) === '/rest/v1') {
    $supabaseUrl = substr($supabaseUrl, 0, -8);
}

safa_define('APP_NAME', trim((string) safa_config_value('APP_NAME', 'Safa Mart')));
safa_define('APP_VERSION', trim((string) safa_config_value('APP_VERSION', '1.0.0')));
safa_define('APP_ENV', trim((string) safa_config_value('APP_ENV', 'production')));
safa_define('APP_DEBUG', safa_bool(safa_config_value('APP_DEBUG', 'false')));
safa_define('APP_TIMEZONE', trim((string) safa_config_value('APP_TIMEZONE', 'Asia/Kolkata')));
safa_define('APP_CURRENCY', 'INR');

safa_define('SITE_URL', $siteUrl);
safa_define('SITE_TAGLINE', trim((string) safa_config_value('SITE_TAGLINE', 'Trending deals at the lowest prices')));
safa_define('SITE_DESCRIPTION', trim((string) safa_config_value(
    'SITE_DESCRIPTION',
    'Handpicked fashion, electronics, home and beauty deals from Flipkart, Myntra and Amazon.'
)));

safa_define('SUPABASE_URL', $supabaseUrl);
safa_define('SUPABASE_ANON_KEY', trim((string) safa_config_value('SUPABASE_ANON_KEY', '')));
safa_define('SUPABASE_SERVICE_KEY', trim((string) safa_config_value('SUPABASE_SERVICE_KEY', '')));
safa_define('SUPABASE_TABLE', trim((string) safa_config_value('SUPABASE_TABLE', 'products')));

safa_define('TELEGRAM_BOT_TOKEN', trim((string) safa_config_value('TELEGRAM_BOT_TOKEN', '')));
safa_define('TELEGRAM_CHANNEL_URL', trim((string) safa_config_value('TELEGRAM_CHANNEL_URL', 'https://t.me/lootlebuddy')));
safa_define('TELEGRAM_WEBHOOK_SECRET', trim((string) safa_config_value('TELEGRAM_WEBHOOK_SECRET', '')));
safa_define('TELEGRAM_ALLOWED_CHAT_IDS', trim((string) safa_config_value('TELEGRAM_ALLOWED_CHAT_IDS', '')));

safa_define('EARNKARO_TOKEN', trim((string) safa_config_value('EARNKARO_TOKEN', '')));
// EarnKaro ka public link-converter endpoint. Purana api.earnkaro.com/v1/convert
// CloudFront par 403 deta tha (wo koi API hi nahi hai), isliye purani value
// automatically yahan migrate ho jati hai.
$safaEarnKaroUrl = trim((string) safa_config_value(
    'EARNKARO_API_URL',
    'https://ekaro-api.affiliaters.in/api/converter/public'
));
if ($safaEarnKaroUrl === '' || stripos($safaEarnKaroUrl, 'api.earnkaro.com') !== false) {
    $safaEarnKaroUrl = 'https://ekaro-api.affiliaters.in/api/converter/public';
}
safa_define('EARNKARO_API_URL', $safaEarnKaroUrl);
unset($safaEarnKaroUrl);
// EarnKaro ki API kabhi-kabhi slow hoti hai; autopost ko block na kare isliye
// timeout chhota rakha hai. Isse EARNKARO_TIMEOUT env/local config se badha-ghata sakte hain.
safa_define('EARNKARO_TIMEOUT', max(3, min(15, (int) safa_config_value('EARNKARO_TIMEOUT', 8))));
safa_define('EARNKARO_PLATFORMS', [
    'Flipkart',
    'Shopsy',
    'DigiHaat',
    'Myntra',
    'Ajio',
    'Amazon',
    'Snapdeal',
    'Tata CLiQ',
    'Nykaa',
    'Nykaa Fashion',
    'Croma',
    'FirstCry',
    'Lifestyle',
    'Shoppers Stop',
    'Bewakoof',
    'Decathlon',
    'boAt',
    'Mamaearth',
    'Wakefit',
    'Pepperfry',
    'IKEA',
    'JioMart',
    'BigBasket',
]);

safa_define('UPLOAD_API_SECRET', trim((string) safa_config_value('UPLOAD_API_SECRET', '')));
safa_define('UPLOAD_MAX_BYTES', max(1048576, (int) safa_config_value('UPLOAD_MAX_BYTES', 10485760)));
safa_define('IMAGE_MAX_DIMENSION', max(600, min(2400, (int) safa_config_value('IMAGE_MAX_DIMENSION', 1400))));
safa_define('WEBP_QUALITY', max(55, min(95, (int) safa_config_value('WEBP_QUALITY', 80))));

safa_define('PRODUCTS_PER_PAGE', max(8, min(60, (int) safa_config_value('PRODUCTS_PER_PAGE', 24))));
// Storefront par sirf wahi products dikhayein jinke EarnKaro tracking link
// ban chuke hain. Isse bina commission wale/purane products automatically
// chhup jate hain — koi manual command nahi chahiye. Band karna ho to
// config.local.php me 'HIDE_UNAFFILIATED_PRODUCTS' => false kar do.
safa_define('HIDE_UNAFFILIATED_PRODUCTS', safa_bool(safa_config_value('HIDE_UNAFFILIATED_PRODUCTS', true)));
safa_define('PRODUCT_CATEGORIES', ['Fashion', 'Electronics', 'Home & Kitchen', 'Beauty', 'Footwear', 'Accessories']);
safa_define('PLATFORM_FILTERS', ['Flipkart', 'Myntra', 'Amazon', 'Ajio', 'Shopsy', 'DigiHaat']);

// ---------------------------------------------------------------------------
// Home page rails and highlights
// ---------------------------------------------------------------------------
// Spotlight cards are controlled from Telegram: /highlight <slug> <days> <badge>.
// Entries live in uploads/featured.json and expire automatically.
safa_define('FEATURED_MAX_ITEMS', max(1, min(24, (int) safa_config_value('FEATURED_MAX_ITEMS', 12))));
safa_define('BEST_OFFER_MIN_DISCOUNT', max(0, min(95, (int) safa_config_value('BEST_OFFER_MIN_DISCOUNT', 40))));

// Home hero carousel. Slides are built from the biggest live discounts.
safa_define('HERO_CAROUSEL_ENABLED', safa_bool(safa_config_value('HERO_CAROUSEL_ENABLED', true)));
safa_define('HERO_SLIDE_COUNT', max(2, min(8, (int) safa_config_value('HERO_SLIDE_COUNT', 5))));
safa_define('HERO_AUTOPLAY_MS', max(3000, min(15000, (int) safa_config_value('HERO_AUTOPLAY_MS', 5500))));

// Default product order on the home grid. One of: newest, price_asc,
// price_desc, discount_desc, rating_desc.
$safaHomeOrder = trim((string) safa_config_value('HOME_PRODUCT_ORDER', 'newest'));
if (!in_array($safaHomeOrder, ['newest', 'price_asc', 'price_desc', 'discount_desc', 'rating_desc'], true)) {
    $safaHomeOrder = 'newest';
}
safa_define('HOME_PRODUCT_ORDER', $safaHomeOrder);
unset($safaHomeOrder);

// ---------------------------------------------------------------------------
// Ads
// ---------------------------------------------------------------------------
// Keep ADS_ENABLED true and fill AD_SLOTS only when ads should go live.
// Empty slots render nothing for visitors. Add ?ads=preview to any URL to see
// the reserved space. AdSense publisher id goes in config.local.php.
safa_define('ADS_ENABLED', safa_bool(safa_config_value('ADS_ENABLED', true)));
safa_define('ADSENSE_CLIENT', trim((string) safa_config_value('ADSENSE_CLIENT', '')));
safa_define('AD_SLOTS', safa_config_value('AD_SLOTS', [
    'home-top'       => ['html' => ''],
    'home-mid'       => ['html' => ''],
    'home-bottom'    => ['html' => ''],
    'product-top'    => ['html' => ''],
    'product-bottom' => ['html' => ''],
    'browse-top'     => ['html' => ''],
    'browse-bottom'  => ['html' => ''],
]));

// ---------------------------------------------------------------------------
// SEO and analytics
// ---------------------------------------------------------------------------
// Search Console / Bing verification values, no HTML tags.
safa_define('GOOGLE_SITE_VERIFICATION', trim((string) safa_config_value('GOOGLE_SITE_VERIFICATION', '')));
safa_define('BING_SITE_VERIFICATION', trim((string) safa_config_value('BING_SITE_VERIFICATION', '')));
// Optional GA4 measurement id, for example G-XXXXXXXXXX.
safa_define('GOOGLE_ANALYTICS_ID', trim((string) safa_config_value('GOOGLE_ANALYTICS_ID', '')));
safa_define('SEO_SITEMAP_CACHE_TTL', max(300, min(86400, (int) safa_config_value('SEO_SITEMAP_CACHE_TTL', 1800))));
safa_define('INDEXNOW_KEY', trim((string) safa_config_value('INDEXNOW_KEY', '')));


/**
 * Stores that are permanently rejected: the Telegram bot never processes them
 * and the storefront never displays them. Domain entries match the host and any
 * subdomain; an entry without a dot is treated as a host substring so a single
 * value such as "hamaramall" covers hamaramall.in / .com / .shop alike.
 */
safa_define('BLOCKED_PLATFORMS', ['Hamara Mall', 'HamaraMall']);
safa_define('BLOCKED_DOMAINS', ['hamaramall']);

safa_define('HTTP_TIMEOUT', max(5, min(30, (int) safa_config_value('HTTP_TIMEOUT', 15))));
// Jab merchant is server ka IP block karta hai (Ajio HTTP 403), tab pages
// r.jina.ai reader ke through fetch hote hain. Off karna ho to
// config.local.php me 'READER_PROXY_ENABLED' => false rakho.
safa_define('READER_PROXY_ENABLED', safa_bool(safa_config_value('READER_PROXY_ENABLED', true)));safa_define('USER_AGENT', trim((string) safa_config_value(
    'USER_AGENT',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
)));

safa_define('SAFA_ROOT', __DIR__);
safa_define('LOG_DIR', __DIR__ . '/logs');
safa_define('UPLOAD_DIR', __DIR__ . '/uploads/products');
safa_define('UPLOAD_WEB_PATH', 'uploads/products');

if (!is_dir(LOG_DIR) && !@mkdir(LOG_DIR, 0755, true) && !is_dir(LOG_DIR)) {
    error_log('Safa Mart: unable to create log directory at ' . LOG_DIR);
}
if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
    error_log('Safa Mart: unable to create upload directory at ' . UPLOAD_DIR);
}

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_DIR . '/php-error.log');
date_default_timezone_set(APP_TIMEZONE);
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/http.php';
require_once __DIR__ . '/includes/metadata.php';
require_once __DIR__ . '/includes/images.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/featured.php';
require_once __DIR__ . '/includes/ads.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/guides.php';
require_once __DIR__ . '/includes/events.php';
require_once __DIR__ . '/includes/footer.php';
