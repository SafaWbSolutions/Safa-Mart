<?php
/**
 * Shared helpers: logging, URLs, formatting, security, rate limiting and
 * storefront presentation.
 */

function app_log(string $level, string $message, array $context = []): void
{
    $redacted = [];
    foreach ($context as $key => $value) {
        if (preg_match('/token|secret|key|authorization|password|cookie/i', (string) $key)) {
            $redacted[$key] = '[redacted]';
        } elseif (is_string($value) && strlen($value) > 1500) {
            $redacted[$key] = substr($value, 0, 1500) . '...';
        } else {
            $redacted[$key] = $value;
        }
    }

    $line = json_encode([
        'ts'      => gmdate('c'),
        'level'   => strtolower($level),
        'message' => $message,
        'context' => $redacted,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line === false) {
        $line = gmdate('c') . ' ' . strtoupper($level) . ' ' . $message;
    }

    $file = LOG_DIR . '/app.log';
    clearstatcache(true, $file);
    if (is_file($file) && filesize($file) > 5242880) {
        @rename($file, LOG_DIR . '/app.log.1');
    }
    @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** @param mixed $value */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safa_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function site_url(string $path = ''): string
{
    $base = SITE_URL;
    if ($base === '') {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            $host = 'localhost';
        }
        $base = (safa_is_https() ? 'https' : 'http') . '://' . $host;
    }
    $base = rtrim($base, '/');
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function asset_url(string $path): string
{
    $clean = ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/');
    $file = SAFA_ROOT . '/' . $clean;
    $version = is_file($file) ? (string) filemtime($file) : APP_VERSION;
    return site_url($path) . '?v=' . rawurlencode($version);
}

function security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header_remove('X-Powered-By');

    $supabaseOrigin = '';
    if (SUPABASE_URL !== '') {
        $parts = parse_url(SUPABASE_URL);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $supabaseOrigin = ' ' . $parts['scheme'] . '://' . $parts['host'];
        }
    }

    $csp = "default-src 'self'; "
        . "base-uri 'self'; "
        . "frame-ancestors 'none'; "
        . "form-action 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com; "
        . "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; "
        . "img-src 'self' data: https:; "
        . "font-src 'self' data:; "
        . "connect-src 'self'" . $supabaseOrigin . " https://*.supabase.co; "
        . "object-src 'none'; "
        . "upgrade-insecure-requests";
    header('Content-Security-Policy: ' . $csp);

    if (safa_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** @param mixed $data */
function json_response($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    $json = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        $json = '{"ok":false,"error":"Response encoding failed"}';
    }
    echo $json;
    exit;
}

/** @return list<string> */
function missing_config(array $keys): array
{
    $missing = [];
    foreach ($keys as $key) {
        if (!defined($key) || constant($key) === '' || constant($key) === null) {
            $missing[] = $key;
        }
    }
    return $missing;
}

function supabase_is_configured(bool $service = false): bool
{
    if (SUPABASE_URL === '') {
        return false;
    }
    return $service ? SUPABASE_SERVICE_KEY !== '' : SUPABASE_ANON_KEY !== '';
}

function clean_text($value): string
{
    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return trim($text);
}

function truncate_text(string $text, int $limit): string
{
    $text = clean_text($text);
    if ($limit < 1) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit
            ? rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…'
            : $text;
    }
    return strlen($text) > $limit ? rtrim(substr($text, 0, $limit - 1)) . '…' : $text;
}

/** @param mixed $value */
function normalize_price($value): float
{
    if (is_int($value) || is_float($value)) {
        $number = (float) $value;
    } else {
        $raw = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = preg_replace('/[^\d.,]/u', '', $raw) ?? '';
        if ($raw === '') {
            return 0.0;
        }
        if (strpos($raw, ',') !== false && strpos($raw, '.') !== false) {
            if (strrpos($raw, ',') > strrpos($raw, '.')) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } else {
                $raw = str_replace(',', '', $raw);
            }
        } elseif (preg_match('/^\d{1,3}(,\d{2,3})+$/', $raw)) {
            $raw = str_replace(',', '', $raw);
        } elseif (substr_count($raw, ',') === 1) {
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }
        $number = (float) $raw;
    }

    if (!is_finite($number) || $number < 0 || $number > 100000000) {
        return 0.0;
    }
    return round($number, 2);
}

function calculate_discount(float $mrp, float $price): int
{
    if ($price <= 0 || $mrp <= $price) {
        return 0;
    }
    return max(0, min(99, (int) round((($mrp - $price) / $mrp) * 100)));
}

/** @param mixed $amount */
function inr_plain($amount, bool $forceDecimals = false): string
{
    $amount = (float) $amount;
    if (!is_finite($amount)) {
        $amount = 0.0;
    }
    $decimals = ($forceDecimals || floor($amount) !== $amount) ? 2 : 0;
    $formatted = number_format($amount, $decimals, '.', '');
    $parts = explode('.', $formatted, 2);
    $integer = $parts[0];
    $sign = '';
    if (str_starts_with($integer, '-')) {
        $sign = '-';
        $integer = substr($integer, 1);
    }
    $lastThree = substr($integer, -3);
    $rest = substr($integer, 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',';
    }
    $result = $sign . $rest . $lastThree;
    if (isset($parts[1])) {
        $result .= '.' . $parts[1];
    }
    return $result;
}

/** @param mixed $amount */
function inr($amount, bool $forceDecimals = false): string
{
    return '₹' . inr_plain($amount, $forceDecimals);
}

function clean_search_term(string $term): string
{
    $term = preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $term) ?? '';
    $term = preg_replace('/\s+/u', ' ', $term) ?? '';
    $term = trim($term);
    if (function_exists('mb_substr')) {
        return mb_substr($term, 0, 60, 'UTF-8');
    }
    return substr($term, 0, 60);
}

function slugify_title(string $title): string
{
    $title = clean_text($title);
    $title = str_replace(['&', '+'], [' and ', ' '], $title);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        if ($ascii !== false) {
            $title = $ascii;
        }
    }
    $title = strtolower($title);
    $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
    $title = trim(preg_replace('/-+/', '-', $title) ?? '', '-');
    if ($title === '') {
        $title = 'product';
    }
    if (strlen($title) > 70) {
        $title = rtrim(substr($title, 0, 70), '-');
    }
    return $title;
}

function build_slug(string $title): string
{
    return slugify_title($title) . '-' . substr(bin2hex(random_bytes(5)), 0, 8);
}

function detect_platform(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', rtrim($host, '.')) ?? '';
    if ($host === '') {
        return 'Other';
    }

    $map = [
        'flipkart.com'        => 'Flipkart',
        'fkrt.it'             => 'Flipkart',
        'fkrt.co'             => 'Flipkart',
        'myntra.com'          => 'Myntra',
        'myntr.it'            => 'Myntra',
        'ajio.com'            => 'Ajio',
        'amazon.in'           => 'Amazon',
        'amazon.com'          => 'Amazon',
        'amzn.in'             => 'Amazon',
        'amzn.to'             => 'Amazon',
        'snapdeal.com'        => 'Snapdeal',
        'tatacliq.com'        => 'Tata CLiQ',
        'nykaa.com'           => 'Nykaa',
        'nykaafashion.com'    => 'Nykaa Fashion',
        'croma.com'           => 'Croma',
        'firstcry.com'        => 'FirstCry',
        'lifestylestores.com' => 'Lifestyle',
        'shoppersstop.com'    => 'Shoppers Stop',
        'bewakoof.com'        => 'Bewakoof',
        'decathlon.in'        => 'Decathlon',
        'boat-lifestyle.com'  => 'boAt',
        'mamaearth.in'        => 'Mamaearth',
        'wakefit.co'          => 'Wakefit',
        'pepperfry.com'       => 'Pepperfry',
        'ikea.com'            => 'IKEA',
        'jiomart.com'         => 'JioMart',
        'bigbasket.com'       => 'BigBasket',
    ];

    foreach ($map as $domain => $name) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return $name;
        }
    }
    return 'Other';
}

/**
 * @return list<string>
 */
function blocked_platform_names(): array
{
    $names = [];
    foreach (BLOCKED_PLATFORMS as $platform) {
        $platform = trim((string) $platform);
        if ($platform !== '' && !in_array($platform, $names, true)) {
            $names[] = $platform;
        }
    }
    return $names;
}

/**
 * @return list<string>
 */
function blocked_domains(): array
{
    $domains = [];
    foreach (BLOCKED_DOMAINS as $domain) {
        $domain = trim((string) $domain);
        if ($domain !== '' && !in_array($domain, $domains, true)) {
            $domains[] = $domain;
        }
    }
    return $domains;
}

function is_blocked_platform(string $platform): bool
{
    $platform = normalize_platform_token($platform);
    if ($platform === '') {
        return false;
    }
    foreach (blocked_platform_names() as $blocked) {
        if (normalize_platform_token($blocked) === $platform) {
            return true;
        }
    }
    return false;
}

function normalize_platform_token(string $platform): string
{
    $lower = function_exists('mb_strtolower') ? mb_strtolower(trim($platform)) : strtolower(trim($platform));
    return (string) preg_replace('/[^a-z0-9]/', '', $lower);
}

function host_is_blocked(string $host): bool
{
    $host = strtolower(trim($host));
    $host = preg_replace('/^www\./', '', rtrim($host, '.')) ?? '';
    if ($host === '') {
        return false;
    }
    foreach (blocked_domains() as $domain) {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            continue;
        }
        if (str_contains($domain, '.')) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
            continue;
        }
        if (str_contains($host, $domain)) {
            return true;
        }
    }
    return false;
}

function is_blocked_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (host_is_blocked((string) parse_url($url, PHP_URL_HOST))) {
        return true;
    }
    return is_blocked_platform(detect_platform($url));
}

/**
 * @param array<string,mixed> $product
 */
function product_is_affiliated(array $product): bool
{
    $affiliateUrl = trim((string) ($product['affiliate_url'] ?? ''));
    $originalUrl = trim((string) ($product['original_url'] ?? ''));
    if ($affiliateUrl === '' || $affiliateUrl === $originalUrl) {
        return false;
    }
    return is_affiliate_url($affiliateUrl);
}

/**
 * @param array<string,mixed> $product
 */
function is_blocked_product(array $product): bool
{
    if (is_blocked_platform((string) ($product['source_platform'] ?? ''))) {
        return true;
    }
    foreach (['original_url', 'affiliate_url'] as $key) {
        if (is_blocked_url((string) ($product[$key] ?? ''))) {
            return true;
        }
    }
    // Hide brand/category/campaign pages that were saved as if they were a
    // single product (e.g. a Myntra brand page with the Myntra logo image).
    $originalUrl = trim((string) ($product['original_url'] ?? ''));
    if ($originalUrl !== '' && !is_product_page_url($originalUrl)) {
        return true;
    }
    // Bina EarnKaro tracking link wale products par click hone se commission
    // nahi milta, isliye unhe automatically chhupa dete hain.
    if (HIDE_UNAFFILIATED_PRODUCTS && !product_is_affiliated($product)) {
        return true;
    }
    return false;
}

/**
 * True when a URL already carries affiliate tracking.
 *
 * Such links must never be resolved and converted again, otherwise the
 * tracking is lost and the click earns nothing. EarnKaro's Flipkart output is
 * https://dl.flipkart.com/s/XXXX, its Myntra output is a linkredirect/tracking
 * URL, and direct merchant links may carry affid / tag query parameters.
 */
function is_affiliate_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = strtolower((string) ($parts['path'] ?? ''));

    $affiliateHosts = [
        'earnkaro.com', 'ekaro.in', 'ekaro.co', 'earnkaro.in',
        'bitli.in', 'linkredirect.in', 'myntr.it', 'ajiio.in',
        'fkrt.it', 'fkrt.co', 'shopsy.in', 'amzn.to',
    ];
    foreach ($affiliateHosts as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }

    // Flipkart's affiliate short link.
    if ($host === 'dl.flipkart.com' && str_starts_with($path, '/s/')) {
        return true;
    }

    $query = (string) ($parts['query'] ?? '');
    if ($query !== '') {
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = strtolower(explode('=', $pair, 2)[0]);
            if (preg_match('/^(?:affid|affextparam\d*|tag|linkcode|linkid|icid)$/', $key)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * PostgREST filter that keeps blocked stores out of every storefront query.
 * Applied alongside the PHP-side filter in the Supabase helpers.
 */
function storefront_exclusion_query(): string
{
    $parts = [];

    $platforms = blocked_platform_names();
    if ($platforms) {
        $tokens = [];
        foreach ($platforms as $platform) {
            // PostgREST `in.()` values must stay single tokens; "Hamara Mall"
            // becomes HamaraMall so both spellings block the same store.
            $token = preg_replace('/[^A-Za-z0-9_-]/', '', $platform);
            if ($token !== '' && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }
        if ($tokens) {
            $parts[] = 'source_platform=not.in.(' . implode(',', $tokens) . ')';
        }
    }

    // Skip the complex not.or clause with URL patterns — it can break on older
    // PostgREST builds. The PHP-side filter in fetch_storefront_products()
    // handles domain-based blocking reliably.
    return $parts === [] ? '' : '&' . implode('&', $parts);
}

function canonicalize_product_url(string $url): string
{
    $url = trim($url);
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return $url;
    }

    $host = strtolower((string) $parts['host']);
    $platform = detect_platform($url);
    if ($platform !== 'Other') {
        $scheme = 'https';
    }

    $port = '';
    if (!empty($parts['port']) && !(($scheme === 'https' && (int) $parts['port'] === 443) || ($scheme === 'http' && (int) $parts['port'] === 80))) {
        $port = ':' . (int) $parts['port'];
    }

    $path = (string) ($parts['path'] ?? '/');
    $queryPairs = [];
    if (!empty($parts['query'])) {
        foreach (explode('&', (string) $parts['query']) as $pair) {
            if ($pair === '') {
                continue;
            }
            $chunks = explode('=', $pair, 2);
            $key = urldecode($chunks[0]);
            $value = $chunks[1] ?? '';
            if ($key === '') {
                continue;
            }
            $trackerPattern = '/^(utm_.*|gclid|fbclid|msclkid|yclid|igshid|si|ref|ref_|affid|affExtParam\d+|tag|linkCode|linkId|camp|creative|creativeASIN|ascsubtag|_ga|mc_cid|mc_eid|icid|cid)$/i';
            if (preg_match($trackerPattern, $key)) {
                continue;
            }
            $queryPairs[] = $key . ($value !== '' ? '=' . $value : '');
        }
    }

    $result = $scheme . '://' . $host . $port . $path;
    if ($queryPairs) {
        $result .= '?' . implode('&', $queryPairs);
    }
    return $result;
}

/**
 * True only for a single product page, not a brand/category/campaign listing.
 *
 * Stores whose URL shape is unknown are allowed through so new EarnKaro
 * partners keep working; the known marketplaces get a strict check.
 */
function is_product_page_url(string $url): bool
{
    $platform = detect_platform($url);
    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    if ($path === '' || $path === '/') {
        return false;
    }

    switch ($platform) {
        case 'Myntra':
            // .../product-slug/40504218/buy
            return (bool) preg_match('~/\d{6,}/buy(?:/|$)~', $path);
        case 'Flipkart':
            // .../product-slug/p/itm1234abcd
            return (bool) preg_match('~/p/itm[a-z0-9]+~', $path);
        case 'Ajio':
            return (bool) preg_match('~/p/\d{5,}~', $path);
        case 'Amazon':
            return (bool) preg_match('~/(?:dp|gp/product)/[a-z0-9]{10}~', $path);
        default:
            // Unknown stores keep working; strict checks only where the URL
            // shape is well known.
            return true;
    }
}

function infer_category(string $text, string $hint = ''): string
{
    $hint = strtolower(trim($hint));
    foreach (PRODUCT_CATEGORIES as $category) {
        if ($hint === strtolower($category)) {
            return $category;
        }
    }

    $haystack = strtolower($text . ' ' . $hint);
    $keywords = [
        'Footwear' => [
            'shoe', 'shoes', 'sandal', 'sandals', 'sneaker', 'sneakers', 'footwear', 'boot', 'boots',
            'heel', 'heels', 'slipper', 'slippers', 'loafer', 'loafers', 'flip flop', 'flip-flop', 'crocs',
        ],
        'Beauty' => [
            'beauty', 'makeup', 'make-up', 'cosmetic', 'cosmetics', 'skincare', 'skin care', 'serum',
            'cream', 'shampoo', 'conditioner', 'perfume', 'fragrance', 'lipstick', 'hair oil', 'haircare',
            'grooming', 'nail', 'eyeliner', 'kajal', 'sunscreen', 'moisturizer', 'face wash',
        ],
        'Electronics' => [
            'mobile', 'smartphone', 'phone', 'laptop', 'headphone', 'headphones', 'earphone', 'earphones',
            'earbuds', 'tv', 'television', 'smartwatch', 'smart watch', 'camera', 'speaker', 'charger',
            'tablet', 'electronic', 'electronics', 'power bank', 'powerbank', 'keyboard', 'mouse',
            'monitor', 'printer', 'router', 'trimmer', 'bluetooth',
        ],
        'Home & Kitchen' => [
            'kitchen', 'cookware', 'home', 'furniture', 'bedding', 'bedsheet', 'curtain', 'pillow',
            'cushion', 'decor', 'storage', 'mixer', 'fan', 'lamp', 'light', 'mattress', 'sofa', 'chair',
            'table', 'dinner set', 'pressure cooker', 'cooker', 'bottle', 'flask', 'wallpaper', 'clock',
            'iron', 'vacuum',
        ],
        'Accessories' => [
            'watch', 'bag', 'bags', 'wallet', 'belt', 'jewellery', 'jewelry', 'sunglasses', 'eyewear',
            'accessory', 'accessories', 'handbag', 'backpack', 'sling', 'clutch', 'bracelet', 'necklace',
            'earring', 'ring', 'cap', 'hat', 'scarf', 'tie',
        ],
        'Fashion' => [
            'shirt', 't-shirt', 'tshirt', 'tee', 'kurta', 'kurti', 'saree', 'sari', 'dress', 'jeans',
            'top', 'tops', 'lehenga', 'suit', 'jacket', 'hoodie', 'clothing', 'apparel', 'fashion',
            'shorts', 'trouser', 'pants', 'leggings', 'blouse', 'gown', 'sweater', 'sweatshirt',
            'innerwear', 'lingerie', 'nightwear', 'ethnic', 'western', 'dupatta', 'salwar',
        ],
    ];

    foreach ($keywords as $category => $words) {
        foreach ($words as $word) {
            if (preg_match('/(^|[^a-z0-9])' . preg_quote($word, '/') . '([^a-z0-9]|$)/i', $haystack)) {
                return $category;
            }
        }
    }
    return 'Other';
}

function rate_limit_hit(string $key, int $limit, int $windowSeconds): bool
{
    $dir = LOG_DIR . '/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return true;
    }
    $file = $dir . '/' . hash('sha256', $key) . '.json';
    // Best-effort cleanup of stale rate-limit files so the directory does not
    // grow without bound on long-running shared hosts.
    if (mt_rand(1, 100) === 1) {
        $staleBefore = time() - max($windowSeconds, 3600) * 2;
        $entries = @scandir($dir) ?: [];
        $removed = 0;
        foreach ($entries as $entry) {
            if ($removed >= 200 || !str_ends_with($entry, '.json')) {
                continue;
            }
            $entryPath = $dir . '/' . $entry;
            if (is_file($entryPath)) {
                $mtime = @filemtime($entryPath);
                if ($mtime !== false && $mtime < $staleBefore) {
                    @unlink($entryPath);
                    $removed++;
                }
            }
        }
    }
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        return true;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return true;
    }

    $raw = stream_get_contents($handle);
    $decoded = json_decode($raw ?: '[]', true);
    $now = time();
    $hits = [];
    if (is_array($decoded)) {
        foreach ($decoded as $timestamp) {
            if (is_int($timestamp) && $timestamp > ($now - $windowSeconds)) {
                $hits[] = $timestamp;
            }
        }
    }

    $allowed = count($hits) < max(1, $limit);
    if ($allowed) {
        $hits[] = $now;
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($hits));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $allowed;
}

function claim_telegram_update(string $updateId): bool
{
    if ($updateId === '') {
        return true;
    }
    return rate_limit_hit('telegram-update:' . $updateId, 1, 600);
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function telegram_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return list<string> */
function product_gallery(array $product): array
{
    $urls = [];
    $thumbnail = trim((string) ($product['thumbnail_url'] ?? ''));
    if ($thumbnail !== '') {
        $urls[] = $thumbnail;
    }

    $gallery = $product['gallery_urls'] ?? [];
    if (is_string($gallery)) {
        $decoded = json_decode($gallery, true);
        $gallery = is_array($decoded) ? $decoded : [];
    }
    if (is_array($gallery)) {
        foreach ($gallery as $url) {
            if (is_string($url) && trim($url) !== '') {
                $urls[] = trim($url);
            }
        }
    }

    $final = [];
    foreach ($urls as $url) {
        if (!preg_match('#^https?://#i', $url) || in_array($url, $final, true)) {
            continue;
        }
        $final[] = $url;
        if (count($final) >= 8) {
            break;
        }
    }
    return $final;
}

function render_product_card(array $product): string
{
    $id = (int) ($product['id'] ?? 0);
    if ($id < 1) {
        return '';
    }

    $title = clean_text($product['title'] ?? 'Product');
    if ($title === '') {
        $title = 'Product';
    }
    $slug = (string) ($product['slug'] ?? '');
    $price = (float) ($product['price'] ?? 0);
    $mrp = (float) ($product['mrp'] ?? 0);
    $discount = (int) ($product['discount_percent'] ?? 0);
    if ($discount <= 0) {
        $discount = calculate_discount($mrp, $price);
    }
    $platform = clean_text($product['source_platform'] ?? 'Store');
    if ($platform === '' || $platform === 'Other') {
        $platform = 'Store';
    }
    $platformClass = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($platform)) ?? '', '-');
    if ($platformClass === '') {
        $platformClass = 'store';
    }

    $image = (string) ($product['thumbnail_url'] ?? '');
    if (!preg_match('#^https?://#i', $image)) {
        $image = '';
    }

    $productUrl = site_url('product.php?slug=' . rawurlencode($slug));
    $buyUrl = site_url('go.php?id=' . $id);
    $placeholder = '<div class="product-placeholder" aria-hidden="true">'
        . '<svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6">'
        . '<path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>'
        . '<span>Image coming soon</span></div>';

    $media = $image !== ''
        ? '<img src="' . e($image) . '" alt="' . e($title) . '" loading="lazy" decoding="async" class="product-image js-product-img">'
        : $placeholder;

    $discountHtml = $discount > 0
        ? '<span class="discount-pill">' . $discount . '% OFF</span>'
        : '';
    $mrpHtml = ($mrp > $price && $price > 0)
        ? '<span class="price-mrp">' . e(inr($mrp)) . '</span>'
        : '';
    $priceHtml = $price > 0 ? e(inr($price)) : 'Price on request';

    return '<article class="product-card" data-product-id="' . $id . '">'
        . '<a class="product-media" href="' . e($productUrl) . '" aria-label="' . e($title) . '">'
        . $media
        . $discountHtml
        . '<span class="platform-badge platform-' . e($platformClass) . '">' . e($platform) . '</span>'
        . '</a>'
        . '<div class="product-body">'
        . '<a class="product-title" href="' . e($productUrl) . '">' . e($title) . '</a>'
        . '<div class="product-price-row"><span class="product-price">' . $priceHtml . '</span>' . $mrpHtml . '</div>'
        . '<a class="buy-button" href="' . e($buyUrl) . '" target="_blank" rel="nofollow sponsored noopener" aria-label="Buy ' . e($title) . ' now">'
        . '<span>Buy Now</span>'
        . '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>'
        . '</a>'
        . '</div>'
        . '</article>';
}

function human_date(?string $date): string
{
    if (!$date) {
        return '';
    }
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return '';
    }
    return date('d M Y', $timestamp);
}
