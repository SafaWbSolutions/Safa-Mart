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
$GLOBALS['SAFA_LOCAL_CONFIG'] = $safaLocalConfig;
unset($safaLocalConfigFile, $safaLoadedConfig);

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
safa_define('PLATFORM_FILTERS', ['Flipkart', 'Myntra', 'Amazon']);

/**
 * Stores that are permanently rejected: the Telegram bot never processes them
 * and the storefront never displays them. Domain entries match the host and any
 * subdomain; an entry without a dot is treated as a host substring so a single
 * value such as "hamaramall" covers hamaramall.in / .com / .shop alike.
 */
safa_define('BLOCKED_PLATFORMS', ['Hamara Mall', 'HamaraMall']);
safa_define('BLOCKED_DOMAINS', ['hamaramall']);

safa_define('HTTP_TIMEOUT', max(5, min(30, (int) safa_config_value('HTTP_TIMEOUT', 15))));
safa_define('USER_AGENT', trim((string) safa_config_value(
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
