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

    $adsScript = '';
    $adsFrame = '';
    $adsConnect = '';
    if (defined('ADSENSE_CLIENT') && trim((string) ADSENSE_CLIENT) !== '') {
        $googleScripts = ' https://pagead2.googlesyndication.com https://*.googlesyndication.com'
            . ' https://adservice.google.com https://www.googletagservices.com'
            . ' https://ep1.adtrafficquality.google https://ep2.adtrafficquality.google';
        $adsScript = $googleScripts;
        $adsFrame = " frame-src 'self' https://googleads.g.doubleclick.net"
            . ' https://tpc.googlesyndication.com https://www.google.com https://*.googlesyndication.com;';
        $adsConnect = ' https://pagead2.googlesyndication.com https://*.googlesyndication.com'
            . ' https://googleads.g.doubleclick.net https://adservice.google.com'
            . ' https://ep1.adtrafficquality.google https://ep2.adtrafficquality.google';
    }

    if (defined('GOOGLE_ANALYTICS_ID') && trim((string) GOOGLE_ANALYTICS_ID) !== '') {
        $adsScript .= ' https://www.googletagmanager.com https://www.google-analytics.com';
        $adsConnect .= ' https://www.googletagmanager.com https://www.google-analytics.com'
            . ' https://*.google-analytics.com';
    }

    $csp = "default-src 'self'; "
        . "base-uri 'self'; "
        . "frame-ancestors 'none'; "
        . "form-action 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com" . $adsScript . '; '
        . "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; "
        . "img-src 'self' data: https:; "
        . "font-src 'self' data:; "
        . "connect-src 'self'" . $supabaseOrigin . " https://*.supabase.co" . $adsConnect . '; '
        . $adsFrame
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

/**
 * Remove marketplace SEO noise from a product title.
 *
 * Example: "SAM & ROY Grey Shirt - Buy SAM & ROY Grey Shirt Online at
 * Best Prices in India | Flipkart.com" becomes "SAM & ROY Grey Shirt".
 */
function clean_product_title(string $title): string
{
    $original = clean_text($title);
    if ($original === '') {
        return '';
    }

    $cleaned = preg_replace(
        [
            '/^(?:Amazon(?:\.in)?|Flipkart|Myntra|Ajio|Shopsy|DigiHaat)\s*[:\-–—|]\s*/iu',
            '/\s*[-–—|]\s*Buy\b.*\bOnline\b.*$/iu',
            '/\s+Online at Best Prices?\b.*$/iu',
            '/\s+Price in India\b.*$/iu',
            '/\s*\|\s*(?:Flipkart|Myntra|Amazon(?:\.in)?|Ajio|Shopsy|DigiHaat|Tata\s*CLiQ|Nykaa|Meesho)\b.*$/iu',
            '/\s+(?:online\s+)?at\s+(?:Flipkart|Myntra|Amazon(?:\.in)?|Ajio|Shopsy|DigiHaat|Tata\s*CLiQ|Nykaa|Meesho)(?:\.(?:com|in))?\s*$/iu',
            '/\s*[-–—]\s*Apparel for (?:Men|Women|Kids|Boys|Girls)[.!]?\s*$/iu',
            '/^Buy\s+(?=[A-Z0-9])/iu',
        ],
        '',
        $original
    );
    $cleaned = is_string($cleaned) ? trim($cleaned, " \t\n\r\0\x0B-–—|,") : $original;

    return $cleaned !== '' ? $cleaned : $original;
}

/**
 * Split a merchant description into short, scannable highlight lines.
 *
 * @return array{0: list<string>, 1: string} First entry: bullet lines, second: the rest.
 */
function split_description_highlights(string $text, int $max = 3): array
{
    $text = clean_text($text);
    if ($text === '') {
        return [[], ''];
    }

    $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
    $sentences = array_values(array_filter(array_map('trim', $sentences), static function ($sentence): bool {
        return $sentence !== '';
    }));

    // Re-join abbreviation fragments such as "U.S." or "Assn." with the next sentence.
    $joined = [];
    $pending = '';
    foreach ($sentences as $sentence) {
        $pending = $pending === '' ? $sentence : $pending . ' ' . $sentence;
        $length = function_exists('mb_strlen') ? mb_strlen($pending, 'UTF-8') : strlen($pending);
        if ($length < 30) {
            continue;
        }
        $joined[] = $pending;
        $pending = '';
    }
    if ($pending !== '') {
        if ($joined) {
            $joined[count($joined) - 1] .= ' ' . $pending;
        } else {
            $joined[] = $pending;
        }
    }
    $sentences = $joined;

    if (count($sentences) < 2) {
        return [[$text], ''];
    }

    $highlights = array_slice($sentences, 0, max(1, $max));
    $rest = implode(' ', array_slice($sentences, max(1, $max)));

    return [$highlights, $rest];
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
    if (!$forceDecimals) {
        $amount = round($amount);
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
    // Keep letters, combining marks (Devanagari matras), numbers, spaces and
    // hyphens. Marks matter for Hindi words like "जूता".
    $term = preg_replace('/[^\p{L}\p{M}\p{N}\s\-]+/u', ' ', $term) ?? '';
    $term = preg_replace('/\s+/u', ' ', $term) ?? '';
    $term = trim($term);
    if (function_exists('mb_substr')) {
        return mb_substr($term, 0, 60, 'UTF-8');
    }
    return substr($term, 0, 60);
}

/**
 * Expand a search term into fuzzy variations for smarter matching.
 * Uses pg_trgm-friendly patterns: stems, plurals, common synonyms.
 *
 * @return list<string> unique expanded terms (original first)
 */
function expand_search_terms(string $term): array
{
    $term = clean_search_term($term);
    if ($term === '') {
        return [];
    }

    $terms = [$term];
    $lower = mb_strtolower($term);

    // Simple English plural/singular rules (safe, no garbage variants)
    $plurals = [];
    $singulars = [];

    if (preg_match('/^(.{2,})ies$/u', $lower, $m)) {
        $singulars[] = $m[1] . 'y';
    } elseif (preg_match('/^(.{2,})oes$/u', $lower, $m)) {
        $singulars[] = $m[1] . 'oe';
    } elseif (preg_match('/^(.{2,})(ches|shes|sses|xes|zes)$/u', $lower, $m)) {
        $singulars[] = $m[1] . substr($m[2], 0, -2);
    } elseif (preg_match('/^(.{2,}[^s])s$/u', $lower, $m)) {
        $singulars[] = $m[1];
    }

    $hasDevanagari = (bool) preg_match('/\p{Devanagari}/u', $term);

    if (!$hasDevanagari) {
        if (preg_match('/^(.{2,}[^aeiou])y$/u', $lower, $m)) {
            $plurals[] = $m[1] . 'ies';
        } elseif (preg_match('/es$/u', $lower)) {
            // Already looks plural (shoes, watches): keep as-is.
        } elseif (preg_match('/^(.{2,}(?:s|x|z|ch|sh))$/u', $lower)) {
            $plurals[] = $lower . 'es';
        } else {
            $plurals[] = $lower . 's';
        }
    }

    foreach ($singulars as $s) {
        $s = trim($s);
        if (mb_strlen($s) >= 3 && $s !== $lower && !in_array($s, $terms, true)) {
            $terms[] = $s;
        }
    }
    foreach ($plurals as $p) {
        $p = trim($p);
        if (mb_strlen($p) >= 3 && $p !== $lower && !in_array($p, $terms, true)) {
            $terms[] = $p;
        }
    }

    // Common synonyms / variations for Indian e-commerce
    $synonyms = [
        'shoe'   => ['shoes', 'sneaker', 'sneakers', 'footwear', 'chappal', 'sandal', 'sandals'],
        'shoes'  => ['shoe', 'sneaker', 'sneakers', 'footwear', 'chappal', 'sandal', 'sandals'],
        'sneaker'=> ['sneakers', 'shoe', 'shoes', 'running'],
        'sneakers'=> ['sneaker', 'shoe', 'shoes', 'running'],
        'mobile' => ['phone', 'smartphone', 'handset'],
        'phone'  => ['mobile', 'smartphone', 'handset'],
        'laptop' => ['notebook', 'macbook'],
        'watch'  => ['watches', 'smartwatch', 'timepiece'],
        'watches'=> ['watch', 'smartwatch', 'timepiece'],
        'shirt'  => ['shirts', 't-shirt', 'tshirt'],
        'shirts' => ['shirt', 't-shirt', 'tshirt'],
        'tshirt' => ['t-shirt', 't shirt', 'shirt'],
        't-shirt'=> ['tshirt', 't shirt', 'shirt'],
        'kurta'  => ['kurtas', 'kurti', 'kurtis'],
        'kurti'  => ['kurtis', 'kurta', 'kurtas'],
        'saree'  => ['sari', 'sarees', 'saris'],
        'sari'   => ['saree', 'sarees', 'saris'],
        'dress'  => ['dresses', 'gown', 'frock'],
        'jeans'  => ['jean', 'denim', 'pants'],
        'pant'   => ['pants', 'trouser', 'trousers', 'bottom'],
        'pants'  => ['pant', 'trouser', 'trousers', 'bottom'],
        'bag'    => ['bags', 'handbag', 'backpack', 'purse'],
        'bags'   => ['bag', 'handbag', 'backpack', 'purse'],
        'earbud' => ['earbuds', 'earphone', 'earphones', 'headphone', 'headphones'],
        'earbuds'=> ['earbud', 'earphone', 'earphones', 'headphone', 'headphones'],
        'headphone'=> ['headphones', 'earphone', 'earphones', 'earbud', 'earbuds'],
        'headphones'=> ['headphone', 'earphone', 'earphones', 'earbud', 'earbuds'],
        'perfume'=> ['fragrance', 'scent', 'eau de parfum', 'deo', 'deodorant'],
        'deo'    => ['deodorant', 'perfume', 'fragrance', 'body spray'],
        'cream'  => ['creams', 'lotion', 'moisturizer'],
        'lotion' => ['lotions', 'cream', 'moisturizer'],
        'bagpack'=> ['backpack', 'bag', 'rucksack'],
        'backpack'=> ['bagpack', 'bag', 'rucksack'],
        'wallet' => ['wallets', 'purse', 'cardholder'],
        'sunglass'=> ['sunglasses', 'goggles', 'shades', 'eyewear'],
        'sunglasses'=> ['sunglass', 'goggles', 'shades', 'eyewear'],
        'cap'    => ['caps', 'hat', 'hats'],
    ];

    if (isset($synonyms[$lower])) {
        foreach ($synonyms[$lower] as $syn) {
            if (!in_array($syn, $terms, true)) {
                $terms[] = $syn;
            }
        }
    }

    // Hinglish variations (Devanagari → rough ASCII)
    // Check if term contains Devanagari characters
    if (preg_match('/\p{Devanagari}/u', $term)) {
        $hinglish = [
            'जूता'   => ['juta', 'joota', 'shoe', 'shoes'],
            'जूते'   => ['jute', 'joote', 'shoe', 'shoes'],
            'मोबाइल' => ['mobile', 'phone'],
            'कपड़ा'   => ['kapda', 'cloth', 'fabric'],
            'शर्ट'    => ['shirt', 'shirts'],
            'जूती'    => ['juti', 'jutti', 'mojari'],
            'साड़ी'    => ['saree', 'sari'],
            'कुर्ता'   => ['kurta', 'kurtas'],
            'बैग'     => ['bag', 'bags'],
            'घड़ी'    => ['ghadi', 'watch'],
            'चप्पल'   => ['chappal', 'sandal'],
        ];

        foreach ($hinglish as $key => $vals) {
            if (mb_stripos($term, $key) !== false) {
                foreach ($vals as $v) {
                    if (!in_array($v, $terms, true)) {
                        $terms[] = $v;
                    }
                }
            }
        }
    }

    // Unique, keep original first
    return array_values(array_unique($terms));
}

/**
 * Parse Hinglish/English intent query into structured filters.
 * Examples:
 *   "500 se kam kadhai"        → category: "Home & Kitchen", max_price: 500
 *   "saree 1000 ke neeche"     → category: "Fashion", max_price: 1000, search: "saree"
 *   "best phone under 15000"   → category: "Electronics", max_price: 15000
 *   "shoes under 2000"         → category: "Footwear", max_price: 2000
 *   "kadhai 500 ke andar"      → category: "Home & Kitchen", max_price: 500
 *   "watch 5000 se kam"        → category: "Accessories", max_price: 5000
 *   "kurti 800 tak"            → category: "Fashion", max_price: 800
 *
 * @return array{category:string,max_price:float,min_price:float,search:string,original:string}
 */
function parse_intent_query(string $query): array
{
    $original = $query = clean_search_term($query);
    if ($query === '') {
        return ['category' => '', 'max_price' => 0.0, 'min_price' => 0.0, 'search' => '', 'original' => ''];
    }

    $lower = mb_strtolower($query);
    $result = [
        'category'  => '',
        'max_price' => 0.0,
        'min_price' => 0.0,
        'search'    => '',
        'original'  => $original,
    ];

    // Category keywords (Hinglish + English)
    $categoryMap = [
        'Home & Kitchen' => ['kadhai', 'kadai', 'tawa', 'cooker', 'pressure cooker', 'mixer', 'grinder', 'juicer', 'blender', 'utensil', 'bartan', 'कढ़ाई', 'तवा', 'कुकर', 'मिक्सर', 'बर्तन'],
        'Fashion' => ['saree', 'sari', 'kurta', 'kurti', 'shirt', 'tshirt', 't-shirt', 'jeans', 'pant', 'pants', 'trouser', 'dress', 'suit', 'lehenga', 'salwar', 'saree', 'साड़ी', 'कुर्ता', 'कुर्ती', 'शर्ट', 'जींस', 'पैंट', 'ड्रेस'],
        'Electronics' => ['phone', 'mobile', 'smartphone', 'laptop', 'earbud', 'earbuds', 'earphone', 'headphone', 'headphones', 'watch', 'smartwatch', 'tablet', 'charger', 'power bank', 'powerbank', 'मोबाइल', 'फोन', 'लैपटॉप', 'ईयरबड', 'हेडफोन', 'घड़ी'],
        'Footwear' => ['shoe', 'shoes', 'sneaker', 'sneakers', 'sandal', 'sandals', 'chappal', 'floaters', 'loafer', 'jutti', 'जूता', 'जूते', 'चप्पल', 'सैंडल'],
        'Beauty' => ['perfume', 'fragrance', 'deo', 'deodorant', 'cream', 'lotion', 'shampoo', 'face wash', 'serum', 'sunscreen', 'makeup', 'lipstick', 'क्रीम', 'लोशन', 'शैम्पू', 'परफ्यूम'],
        'Grocery' => ['rice', 'dal', 'daal', 'atta', 'oil', 'masala', 'spice', 'sugar', 'tea', 'coffee', 'milk', 'ghee', 'चावल', 'दाल', 'आटा', 'तेल', 'मसाला', 'चीनी', 'चाय'],
        'Accessories' => ['bag', 'bags', 'backpack', 'handbag', 'purse', 'wallet', 'belt', 'sunglass', 'sunglasses', 'cap', 'hat', 'बैग', 'बैगपैक', 'पर्स', 'बेल्ट'],
    ];

    // Detect category from query
    foreach ($categoryMap as $cat => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_stripos($lower, $kw) !== false) {
                $result['category'] = $cat;
                break 2;
            }
        }
    }

    // Price patterns (Hinglish + English)
    // "under X", "under X rs", "X rs under", "X ke neeche", "X ke andar", "X tak", "X se kam", "below X", "less than X"
    $pricePatterns = [
        '/(?:under|below|less\s+than)\s*(\d[\d,]*(?:\.\d{1,2})?)\s*(?:rs|₹|inr)?/iu',
        '/(\d[\d,]*(?:\.\d{1,2})?)\s*(?:rs|₹|inr)?\s*(?:under|below)/iu',
        '/(\d[\d,]*(?:\.\d{1,2})?)\s*(?:ke\s+neeche|ke\s+andar|tak|se\s+kam)/iu',
        '/(?:budget|max|maximum)\s*(?:of|:)?\s*(\d[\d,]*(?:\.\d{1,2})?)/iu',
    ];

    foreach ($pricePatterns as $pattern) {
        if (preg_match($pattern, $lower, $m)) {
            $price = (float) str_replace(',', '', $m[1]);
            if ($price > 0 && $price < 1000000) {
                $result['max_price'] = $price;
                break;
            }
        }
    }

    // Min price patterns: "above X", "over X", "X se upar", "X se zyada", "minimum X"
    $minPricePatterns = [
        '/(?:above|over|more\s+than)\s*(\d[\d,]*(?:\.\d{1,2})?)\s*(?:rs|₹|inr)?/iu',
        '/(\d[\d,]*(?:\.\d{1,2})?)\s*(?:rs|₹|inr)?\s*(?:above|over)/iu',
        '/(\d[\d,]*(?:\.\d{1,2})?)\s*(?:se\s+upar|se\s+zyada|minimum)/iu',
    ];

    foreach ($minPricePatterns as $pattern) {
        if (preg_match($pattern, $lower, $m)) {
            $price = (float) str_replace(',', '', $m[1]);
            if ($price > 0 && $price < 1000000) {
                $result['min_price'] = $price;
                break;
            }
        }
    }

    // Extract search terms (remove price/category keywords from search)
    $search = $lower;
    // Remove price phrases
    $search = preg_replace('/\b(?:under|below|less\s+than|above|over|more\s+than|ke\s+neeche|ke\s+andar|tak|se\s+kam|se\s+upar|se\s+zyada|budget|maximum|minimum)\b\s*\d[\d,]*(?:\.\d{1,2})?/iu', '', $search);
    $search = preg_replace('/\b\d[\d,]*(?:\.\d{1,2})?\s*(?:rs|₹|inr)\b/iu', '', $search);
    // Remove category keywords
    foreach ($categoryMap as $keywords) {
        foreach ($keywords as $kw) {
            $search = preg_replace('/\b' . preg_quote($kw, '/') . '\b/iu', '', $search);
        }
    }
    $search = preg_replace('/\s+/', ' ', $search);
    $search = trim($search, ' ,.');
    $result['search'] = $search;

    return $result;
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
        'shopsy.in'           => 'Shopsy',
        'digihaat.in'         => 'DigiHaat',
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
        'fkrt.it', 'fkrt.co', 'amzn.to',
    ];
    foreach ($affiliateHosts as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }

    // EarnKaro autopost "short links" option inhe use karta hai. Inhe affiliate
    // maan lete hain taaki shortener ki wajah se tracking link na toote.
    $shortLinkHosts = ['bit.ly', 'cutt.ly', 'tinyurl.com', 'rb.gy', 'shorturl.at', 'tiny.one'];
    foreach ($shortLinkHosts as $domain) {
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
        case 'Shopsy':
            // .../product-slug/p/itm1234abcd
            return (bool) preg_match('~/p/~', $path);
        case 'DigiHaat':
            // .../en/product/<slug>  (kabhi /en/product-details/... bhi hota hai)
            return (bool) preg_match('~/product(?:/|-|$)~', $path);
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

/**
 * Weighted keyword rules for category inference.
 *
 * "phrases" are multi-word signals (highest confidence), "strong" are
 * unambiguous product words and "weak" are context words that only help when
 * the title already points at a category. Deliberately ambiguous words such
 * as light, home, table, iron, fan, ring, cap and tie are weak so a light-blue
 * shirt can never land in Home & Kitchen.
 *
 * @return array<string,array{phrases:list<string>,strong:list<string>,weak:list<string>}>
 */
function category_keyword_rules(): array
{
    return [
        'Footwear' => [
            'phrases' => [
                'running shoes', 'sports shoes', 'casual shoes', 'formal shoes', 'walking shoes',
                'flip flops', 'flip-flops', 'high heels', 'block heels', 'wedding shoes',
            ],
            'strong' => [
                'shoe', 'shoes', 'sandal', 'sandals', 'sneaker', 'sneakers', 'footwear', 'boot',
                'boots', 'heel', 'heels', 'slipper', 'slippers', 'loafer', 'loafers', 'crocs',
                'chappal', 'chappals', 'mojari', 'mojaris', 'jutti', 'juttis', 'floater', 'floaters',
            ],
            'weak' => ['sole', 'lace', 'laces', 'walk', 'comfort'],
        ],
        'Grocery' => [
            'phrases' => [
                'kabuli chana', 'whole jeera', 'coriander powder', 'red chilli powder', 'laal mirch',
                'tea leaves', 'green tea', 'black tea', 'herbal tea', 'flower tea', 'tea bags',
                'tea bag', 'ice cream', 'cooking oil', 'mustard oil', 'sunflower oil', 'olive oil',
                'dry fruits', 'gram flour', 'soya chunks', 'peanut butter', 'protein powder',
            ],
            'strong' => [
                'grocery', 'groceries', 'masala', 'masalas', 'spice', 'spices', 'dal', 'dals',
                'lentil', 'lentils', 'chana', 'chickpea', 'chickpeas', 'rajma', 'atta', 'flour',
                'sooji', 'suji', 'rava', 'semolina', 'poha', 'oats', 'muesli', 'cereal', 'cornflakes',
                'rice', 'basmati', 'quinoa', 'sugar', 'jaggery', 'salt', 'honey', 'ghee', 'paneer',
                'coffee', 'snack', 'snacks', 'namkeen', 'biscuit', 'biscuits', 'chocolate',
                'noodles', 'pasta', 'sauce', 'ketchup', 'pickle', 'achar', 'papad', 'almond',
                'almonds', 'cashew', 'cashews', 'raisin', 'raisins', 'dates', 'chia', 'sabja',
                'besan', 'maida', 'jam', 'mayonnaise', 'vinegar', 'jeera', 'cumin', 'cummin',
                'saunf', 'fennel', 'coriander', 'haldi', 'turmeric', 'mirch', 'chilli', 'chili',
            ],
            'weak' => ['powder', 'seeds', 'oil', 'organic', 'natural', 'whole', 'premium', 'food', 'edible'],
        ],
        'Beauty' => [
            'phrases' => [
                'face wash', 'hair oil', 'body lotion', 'body wash', 'eau de parfum', 'eau de toilette',
                'nail polish', 'lip balm', 'hair serum', 'sun screen', 'tea tree', 'body scrub',
                'shaving cream', 'beard oil', 'face serum', 'night cream', 'day cream',
            ],
            'strong' => [
                'beauty', 'makeup', 'make-up', 'cosmetic', 'cosmetics', 'skincare', 'skin care',
                'serum', 'shampoo', 'conditioner', 'perfume', 'parfum', 'fragrance', 'lipstick',
                'kajal', 'eyeliner', 'moisturizer', 'moisturiser', 'sunscreen', 'foundation',
                'mascara', 'deodorant', 'lotion', 'trimmer', 'grooming', 'lip gloss', 'nail',
                'haircare', 'hair care',
            ],
            'weak' => ['cream', 'gel', 'oil', 'soap', 'powder', 'wash', 'scrub', 'mist', 'spray', 'care'],
        ],
        'Electronics' => [
            'phrases' => [
                'power bank', 'powerbank', 'bluetooth speaker', 'wireless earbuds', 'smart watch',
                'mobile cover', 'screen protector', 'memory card', 'pen drive', 'hard disk',
                'gaming mouse', 'mechanical keyboard', 'usb cable', 'charging cable',
            ],
            'strong' => [
                'electronics', 'electronic', 'earbuds', 'earbud', 'earphone', 'earphones',
                'headphone', 'headphones', 'headset', 'bluetooth', 'speaker', 'speakers',
                'charger', 'charging', 'adapter', 'laptop', 'tablet', 'smartphone', 'mobile',
                'phone', 'smartwatch', 'camera', 'webcam', 'monitor', 'printer', 'router',
                'keyboard', 'mouse', 'television', 'tv', 'projector', 'computer', 'airpods',
                'iphone', 'samsung', 'redmi', 'realme', 'oneplus', 'poco',
            ],
            'weak' => ['digital', 'wireless', 'smart', 'portable', 'rechargeable', 'usb', 'battery'],
        ],
        'Home & Kitchen' => [
            'phrases' => [
                'pressure cooker', 'cookware set', 'dinner set', 'non stick', 'non-stick',
                'bed sheet', 'bedsheet', 'bed cover', 'bath towel', 'kitchen tool', 'storage box',
                'wall clock', 'table lamp', 'floor lamp', 'ceiling fan', 'water bottle',
                'vacuum cleaner', 'air fryer', 'mixer grinder', 'chopping board', 'frying pan',
                'home decor', 'wall decor', 'soap dispenser',
            ],
            'strong' => [
                'kitchen', 'cookware', 'furniture', 'bedding', 'bedsheet', 'bedsheets', 'pillow',
                'pillows', 'cushion', 'cushions', 'mattress', 'sofa', 'chair', 'curtain',
                'curtains', 'decor', 'lamp', 'clock', 'bottle', 'flask', 'casserole', 'cooker',
                'kadhai', 'tawa', 'grinder', 'mixer', 'blender', 'juicer', 'toaster', 'kettle',
                'fryer', 'oven', 'microwave', 'refrigerator', 'fridge', 'vacuum', 'mop', 'broom',
                'dustbin', 'hanger', 'organizer', 'shelving', 'rack', 'wallpaper', 'carpet', 'rug',
                'doormat', 'towel', 'towels', 'quilt', 'duvet', 'blanket', 'comforter',
                'pillowcase', 'tablecloth', 'coaster', 'crockery', 'plate', 'plates', 'bowl',
                'bowls', 'mug', 'mugs', 'cutlery', 'spoon', 'spoons', 'fork', 'knife', 'chopper',
                'peeler', 'grater', 'sieve', 'container', 'containers', 'jar', 'jars', 'dispenser',
                'household', 'furnishing',
            ],
            'weak' => ['home', 'table', 'light', 'fan', 'iron', 'steel', 'cotton', 'set', 'pack', 'piece', 'pieces'],
        ],
        'Accessories' => [
            'phrases' => [
                'carry bag', 'laptop bag', 'travel bag', 'sling bag', 'body bag', 'waist bag',
                'wrist watch', 'analog watch', 'digital watch',
            ],
            'strong' => [
                'watch', 'watches', 'bag', 'bags', 'wallet', 'wallets', 'belt', 'belts',
                'jewellery', 'jewelry', 'sunglasses', 'eyewear', 'goggles', 'handbag', 'backpack',
                'sling', 'clutch', 'bracelet', 'necklace', 'earring', 'earrings', 'ring', 'rings',
                'pendant', 'bangle', 'bangles', 'anklet', 'chain', 'purse', 'trolley', 'luggage',
                'suitcase', 'umbrella', 'cufflink', 'cufflinks', 'cap', 'hat', 'scarf', 'tie', 'ties',
            ],
            'weak' => ['accessory', 'accessories', 'leather', 'travel', 'carry'],
        ],
        'Fashion' => [
            'phrases' => [
                't-shirt', 't shirt', 'casual shirt', 'formal shirt', 'polo t-shirt', 'track pants',
                'palazzo pants', 'salwar suit', 'kurta set', 'ethnic wear', 'winter jacket',
                'night suit', 'co-ord set', 'coord set',
            ],
            'strong' => [
                'shirt', 'shirts', 'tshirt', 'tee', 'tees', 'kurta', 'kurti', 'kurtas', 'saree',
                'sari', 'dress', 'dresses', 'jeans', 'jean', 'top', 'tops', 'lehenga', 'suit',
                'suits', 'jacket', 'jackets', 'hoodie', 'hoodies', 'hooded', 'sweatshirt',
                'sweatshirts', 'sweater', 'sweaters', 'clothing', 'apparel', 'fashion', 'shorts',
                'trouser', 'trousers', 'pants', 'leggings', 'blouse', 'gown', 'dupatta', 'salwar',
                'churidar', 'chudidar', 'kameez', 'sherwani', 'dhoti', 'lungi', 'skirt', 'jumpsuit',
                'innerwear', 'lingerie', 'nightwear', 'nightdress', 'pajama', 'pyjama', 'camisole',
                'bra', 'briefs', 'trunk', 'vest', 'socks', 'stockings', 'swimwear', 'bikini',
                'raincoat', 'uniform', 'ethnic', 'western', 'denim',
            ],
            'weak' => [
                'men', 'mens', 'women', 'womens', 'boys', 'girls', 'kids', 'solid', 'printed',
                'checkered', 'casual', 'formal', 'slim', 'regular', 'light', 'dark', 'blue',
                'green', 'black', 'white', 'red', 'pink', 'yellow', 'multicolor', 'design',
                'designer', 'style', 'cotton', 'blend', 'lycra', 'polyester', 'rayon', 'viscose',
                'linen', 'wool', 'warm', 'padded', 'textile', 'textiles',
            ],
        ],
    ];
}

/** Number of whole-word occurrences of a term, capped at two. */
function category_term_hits(string $haystack, string $term): int
{
    if ($haystack === '' || $term === '') {
        return 0;
    }
    $pattern = '/(?<![a-z0-9])' . preg_quote(strtolower($term), '/') . '(?![a-z0-9])/i';
    $count = preg_match_all($pattern, $haystack);
    return min(2, (int) $count);
}

/**
 * Weighted scores for a short piece of text, used for feed/search hints.
 *
 * @param array<string,array{phrases:list<string>,strong:list<string>,weak:list<string>}> $rules
 * @return array{category:string,score:int}
 */
function category_score_text(string $text, array $rules): array
{
    $text = strtolower(trim($text));
    $best = '';
    $bestScore = 0;
    if ($text === '') {
        return ['category' => '', 'score' => 0];
    }

    foreach ($rules as $category => $rule) {
        $score = 0;
        foreach ($rule['phrases'] as $term) {
            $score += 12 * category_term_hits($text, $term);
        }
        foreach ($rule['strong'] as $term) {
            $score += 8 * category_term_hits($text, $term);
        }
        foreach ($rule['weak'] as $term) {
            $score += 2 * category_term_hits($text, $term);
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $category;
        }
    }

    return ['category' => $best, 'score' => $bestScore];
}

/**
 * Best category for a short piece of text (used for feed/search hints).
 *
 * @param array<string,array{phrases:list<string>,strong:list<string>,weak:list<string>}> $rules
 */
function category_best_match(string $text, array $rules): string
{
    $match = category_score_text($text, $rules);
    return $match['score'] >= 4 ? $match['category'] : '';
}

/** Match "home & kitchen", "home-kitchen" or "homeandkitchen" to a category. */
function category_hint_match(string $hint): string
{
    $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower($hint));
    if ($normalized === '') {
        return '';
    }
    foreach (PRODUCT_CATEGORIES as $category) {
        if ($normalized === preg_replace('/[^a-z0-9]+/', '', strtolower($category))) {
            return $category;
        }
    }
    return '';
}

/**
 * Classify a product from its title, feed/search hint and description.
 *
 * Titles carry double weight, so "Men Light Blue Shirt" stays Fashion even
 * though it also contains the weak Home word "light".
 */
function infer_category(string $title, string $hint = '', string $description = ''): string
{
    $rules = category_keyword_rules();
    $title = strtolower(trim($title));
    $description = strtolower(trim($description));
    $hint = trim(strtolower($hint));

    if ($hint !== '') {
        $exact = category_hint_match($hint);
        if ($exact !== '') {
            return $exact;
        }
        $hintMatch = category_best_match($hint, $rules);
        if ($hintMatch !== '') {
            // A broad feed hint must not override a clear product word: a
            // shirt recommended on a shoes search page is still Fashion.
            $titleMatch = category_score_text($title, $rules);
            if ($titleMatch['score'] < 8 || $titleMatch['category'] === $hintMatch) {
                return $hintMatch;
            }
        }
    }

    if ($title === '' && $description === '') {
        return 'Other';
    }

    $best = '';
    $bestScore = 0;
    $secondScore = 0;
    foreach ($rules as $category => $rule) {
        $score = 0;
        foreach ($rule['phrases'] as $term) {
            $score += 12 * category_term_hits($title, $term);
            $score += 5 * category_term_hits($description, $term);
        }
        foreach ($rule['strong'] as $term) {
            $score += 8 * category_term_hits($title, $term);
            $score += 3 * category_term_hits($description, $term);
        }
        foreach ($rule['weak'] as $term) {
            $score += 2 * min(1, category_term_hits($title, $term));
            $score += category_term_hits($description, $term);
        }

        if ($score > $bestScore) {
            $secondScore = $bestScore;
            $bestScore = $score;
            $best = $category;
        } elseif ($score > $secondScore) {
            $secondScore = $score;
        }
    }

    if ($best === '' || $bestScore < 3) {
        return 'Other';
    }
    // When two categories are almost tied the safest place is "Other"; a
    // wrong tab is worse than a product waiting for a clearer title.
    if ($secondScore > 0 && $bestScore < $secondScore * 1.25) {
        return 'Other';
    }

    return $best;
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

/**
 * True when the current request comes from an Android phone/tablet browser.
 * Bots and desktop browsers are excluded so the app bridge only runs where it
 * can actually open a merchant app.
 */
function is_android_mobile_request(): bool
{
    $ua = strtolower(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($ua === '' || !str_contains($ua, 'android')) {
        return false;
    }
    if (preg_match('/bot|spider|crawl|slurp|preview|headless|phantom|curl|wget|python-|java\/|okhttp/', $ua)) {
        return false;
    }
    return true;
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

function compact_count(int $value): string
{
    if ($value >= 1000000) {
        return rtrim(rtrim(number_format($value / 1000000, 1, '.', ''), '0'), '.') . 'M';
    }
    if ($value >= 1000) {
        return rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.') . 'k';
    }
    return (string) $value;
}

function render_product_card(array $product): string
{
    $id = (int) ($product['id'] ?? 0);
    if ($id < 1) {
        return '';
    }

    $title = clean_product_title($product['title'] ?? 'Product');
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

    $brand = clean_text((string) ($product['brand'] ?? ''));
    $rating = (float) ($product['rating'] ?? 0);
    $ratingCount = (int) ($product['rating_count'] ?? 0);
    $brandHtml = $brand !== ''
        ? '<span class="product-brand">' . e($brand) . '</span>'
        : '';
    $ratingHtml = $rating > 0
        ? '<div class="product-meta-row"><span class="product-rating"><span class="rating-star">★</span> '
            . e(number_format($rating, 1))
            . ($ratingCount > 0 ? ' <span class="rating-count">(' . e(compact_count($ratingCount)) . ')</span>' : '')
            . '</span></div>'
        : '';

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
    $featuredBadge = truncate_text(clean_text((string) ($product['_featured_badge'] ?? '')), 24);
    $featuredHtml = $featuredBadge !== ''
        ? '<span class="card-featured">⭐ ' . e($featuredBadge) . '</span>'
        : '';
    $priceDrop = (float) ($product['_price_drop'] ?? 0);
    $dropHtml = $priceDrop > 0
        ? '<span class="card-drop">↓ ' . e(inr($priceDrop)) . '</span>'
        : '';
    $mrpHtml = ($mrp > $price && $price > 0)
        ? '<span class="price-mrp">' . e(inr($mrp)) . '</span>'
        : '';
    $priceHtml = $price > 0 ? e(inr($price)) : 'Price on request';

    return '<article class="product-card" data-product-id="' . $id . '">'
        . '<a class="product-media" href="' . e($productUrl) . '" aria-label="' . e($title) . '">'
        . $media
        . $discountHtml
        . $featuredHtml
        . $dropHtml
        . '<span class="platform-badge platform-' . e($platformClass) . '">' . e($platform) . '</span>'
        . '</a>'
        . '<div class="product-body">'
        . $brandHtml
        . '<div class="product-title-row">'
        . '<a class="product-title" href="' . e($productUrl) . '">' . e($title) . '</a>'
        . '<button type="button" class="follow-btn follow-btn-sm" data-slug="' . e($slug) . '" aria-pressed="false" aria-label="Follow this deal for price-drop alerts">'
        . '<svg class="follow-heart" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'
        . '</button>'
        . '</div>'
        . $ratingHtml
        . '<div class="product-price-row"><span class="product-price">' . $priceHtml . '</span>' . $mrpHtml . '</div>'
        . '<a class="buy-button" href="' . e($buyUrl) . '" target="_blank" rel="nofollow sponsored noopener" aria-label="Buy ' . e($title) . ' now">'
        . '<span>Buy Now</span>'
        . '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>'
        . '</a>'
        . '</div>'
        . '</article>';
}

/**
 * Wide spotlight card used when only a few products are highlighted. This is
 * the "brand deal" layout: large image, badge, discount and a clear CTA.
 */
function render_spotlight_hero_card(array $product): string
{
    $id = (int) ($product['id'] ?? 0);
    if ($id < 1) {
        return '';
    }

    $title = clean_product_title($product['title'] ?? 'Product');
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
    $saving = max(0, (float) round($mrp - $price));
    $platform = clean_text($product['source_platform'] ?? 'Store');
    if ($platform === '' || $platform === 'Other') {
        $platform = 'Store';
    }
    $brand = clean_text((string) ($product['brand'] ?? ''));
    $image = (string) ($product['thumbnail_url'] ?? '');
    if (!preg_match('#^https?://#i', $image)) {
        $image = '';
    }
    $badge = truncate_text(clean_text((string) ($product['_featured_badge'] ?? '')), 24);
    if ($badge === '') {
        $badge = 'Featured deal';
    }
    $productUrl = site_url('product.php?slug=' . rawurlencode($slug));

    $media = $image !== ''
        ? '<img src="' . e($image) . '" alt="' . e($title) . '" loading="lazy" decoding="async" class="js-product-img">'
        : '<span class="offer-placeholder" aria-hidden="true">🛍️</span>';

    $meta = $brand !== '' ? $brand . ' · ' . $platform : $platform;

    return '<a class="spotlight-card" href="' . e($productUrl) . '" aria-label="' . e($title) . '">'
        . '<span class="spotlight-media">' . $media
        . ($discount > 0 ? '<span class="spotlight-off">' . $discount . '% OFF</span>' : '')
        . '</span>'
        . '<span class="spotlight-body">'
        . '<span class="spotlight-badge">⭐ ' . e($badge) . '</span>'
        . '<span class="spotlight-brand">' . e($meta) . '</span>'
        . '<span class="spotlight-title">' . e($title) . '</span>'
        . '<span class="spotlight-price">' . ($price > 0 ? e(inr($price)) : 'Check price')
        . ($mrp > $price && $price > 0 ? '<s>' . e(inr($mrp)) . '</s>' : '')
        . ($discount > 0 ? '<em>' . $discount . '% off</em>' : '')
        . '</span>'
        . ($saving > 0 ? '<span class="spotlight-save">You save ' . e(inr($saving)) . ' — same price as ' . e($platform) . '</span>' : '')
        . '<span class="spotlight-cta">View deal'
        . '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>'
        . '</span>'
        . '</span>'
        . ($discount > 0
            ? '<span class="spotlight-side" aria-hidden="true">'
                . '<span class="spotlight-side-off">' . $discount . '%</span>'
                . '<span class="spotlight-side-label">OFF</span>'
                . '<span class="spotlight-side-note">Limited-time pick</span>'
                . '</span>'
            : '')
        . '</a>';
}

/**
 * Compact card used by the home page offer rails (Flipkart-style strip).
 */
function render_offer_rail_card(array $product, int $rank = 0): string
{
    $id = (int) ($product['id'] ?? 0);
    if ($id < 1) {
        return '';
    }

    $title = clean_product_title($product['title'] ?? 'Product');
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
    $brand = clean_text((string) ($product['brand'] ?? ''));
    $image = (string) ($product['thumbnail_url'] ?? '');
    if (!preg_match('#^https?://#i', $image)) {
        $image = '';
    }
    $saving = max(0, (float) round($mrp - $price));
    $productUrl = site_url('product.php?slug=' . rawurlencode($slug));

    $badge = truncate_text(clean_text((string) ($product['_featured_badge'] ?? '')), 24);
    $badgeHtml = $badge !== ''
        ? '<span class="offer-badge">⭐ ' . e($badge) . '</span>'
        : '';
    $rankHtml = $rank > 0
        ? '<span class="offer-rank" aria-label="Rank ' . $rank . '">#' . $rank . '</span>'
        : '';

    $media = $image !== ''
        ? '<img src="' . e($image) . '" alt="' . e($title) . '" loading="lazy" decoding="async" class="js-product-img">'
        : '<span class="offer-placeholder" aria-hidden="true">🛍️</span>';

    $offHtml = $discount > 0
        ? '<span class="offer-off">' . $discount . '% OFF</span>'
        : '';
    $saveHtml = $saving > 0
        ? '<span class="offer-save">Save ' . e(inr($saving)) . '</span>'
        : '';
    $mrpHtml = ($mrp > $price && $price > 0)
        ? '<s>' . e(inr($mrp)) . '</s>'
        : '';

    return '<a class="offer-card" href="' . e($productUrl) . '" aria-label="' . e($title) . '">'
        . '<span class="offer-media">'
        . $media
        . $rankHtml
        . $offHtml
        . $badgeHtml
        . '</span>'
        . '<span class="offer-body">'
        . ($brand !== '' ? '<span class="offer-brand">' . e($brand) . '</span>' : '<span class="offer-brand">' . e($platform) . '</span>')
        . '<span class="offer-title">' . e($title) . '</span>'
        . '<span class="offer-price">' . ($price > 0 ? e(inr($price)) : 'Check price') . $mrpHtml . '</span>'
        . $saveHtml
        . '</span>'
        . '</a>';
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

/**
 * Android "opening app" screen used by go.php. The affiliate short link is
 * resolved server side to the tracked merchant URL (see
 * resolve_tracked_merchant_url), then handed to the installed app through an
 * intent:// link. When the app is missing Chrome follows the fallback URL and
 * the buyer lands on the website with affiliate tracking intact.
 */
function render_merchant_app_bridge(array $product, string $affiliateUrl, string $platform): void
{
    $title = truncate_text(clean_product_title((string) ($product['title'] ?? '')), 70);
    if ($title === '') {
        $title = 'your product';
    }

    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>Opening ' . e($platform) . ' app...</title>'
        . '<noscript><meta http-equiv="refresh" content="0;url=' . e($affiliateUrl) . '"></noscript>'
        . '<style>'
        . 'html,body{margin:0;padding:0;min-height:100%;background:#fff7fa;'
        . "font-family:'Segoe UI',system-ui,-apple-system,Roboto,Arial,sans-serif;color:#1f2430}"
        . 'body{display:flex;align-items:center;justify-content:center;padding:28px 20px}'
        . '.bridge{width:100%;max-width:420px;background:#fff;border:1px solid #f3d4e0;'
        . 'border-radius:22px;padding:30px 24px;text-align:center;'
        . 'box-shadow:0 18px 45px rgba(233,30,99,.12)}'
        . '.bridge-spinner{width:46px;height:46px;margin:0 auto 18px;border-radius:50%;'
        . 'border:4px solid #ffe0ea;border-top-color:#e91e63;animation:spin .9s linear infinite}'
        . '@keyframes spin{to{transform:rotate(360deg)}}'
        . '.bridge h1{font-size:1.16rem;margin:0 0 8px;line-height:1.35}'
        . '.bridge-product{margin:0 0 22px;font-size:.9rem;color:#6b7280;line-height:1.45}'
        . '.bridge-btn{display:block;padding:15px 18px;border-radius:14px;font-weight:700;'
        . 'font-size:1rem;text-decoration:none;color:#fff;background:linear-gradient(135deg,#e91e63,#c51752);'
        . 'box-shadow:0 10px 24px rgba(233,30,99,.28)}'
        . '.bridge-btn.is-pulse{animation:pulse 1.1s ease-in-out infinite}'
        . '@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.03)}}'
        . '.bridge-link{display:inline-block;margin-top:16px;font-size:.88rem;font-weight:600;'
        . 'color:#881641;text-decoration:none;border-bottom:1px solid rgba(136,22,65,.35)}'
        . '.bridge-hint{margin:16px 0 0;font-size:.82rem;color:#b45309}'
        . '</style>'
        . '</head><body>'
        . '<main class="bridge">'
        . '<div class="bridge-spinner" aria-hidden="true"></div>'
        . '<h1 id="bridge-title">Opening ' . e($platform) . ' app</h1>'
        . '<p class="bridge-product">' . e($title) . '</p>'
        . '<a class="bridge-btn" id="bridge-app" href="' . e($affiliateUrl) . '" rel="nofollow sponsored noopener">'
        . 'Open in ' . e($platform) . ' app</a>'
        . '<a class="bridge-link" id="bridge-web" href="' . e($affiliateUrl) . '" rel="nofollow sponsored noopener">'
        . 'Continue on website</a>'
        . '<p class="bridge-hint" id="bridge-hint" hidden>App did not open? Tap the button above.</p>'
        . '</main>';

    // Paint the bridge before the network round-trips below.
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();

    $target = resolve_tracked_merchant_url($affiliateUrl);
    // Never skip commission: when the resolved URL lost its affiliate
    // parameters we keep the original short link (browser flow) instead.
    $trackingOk = $target !== ''
        && (!is_affiliate_url($affiliateUrl) || has_affiliate_tracking_params($target));
    $package = $trackingOk ? merchant_app_package(detect_platform($target)) : '';
    // The resolved URL already carries the affiliate tracking parameters, so it
    // is the fastest correct fallback for both the app and the website.
    $webUrl = $trackingOk ? $target : $affiliateUrl;
    $intent = $package !== '' ? build_android_intent_url($target, $package, $webUrl) : '';
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    if ($intent === '') {
        app_log('info', 'App bridge fell back to website', [
            'id'          => (int) ($product['id'] ?? 0),
            'platform'    => $platform,
            'target'      => $target,
            'tracking_ok' => $trackingOk,
        ]);
        echo '<script>window.location.replace(' . json_encode($affiliateUrl, $jsonFlags) . ');</script>'
            . '</body></html>';
        return;
    }

    echo '<script>(function(){'
        . 'var intent=' . json_encode($intent, $jsonFlags) . ';'
        . 'var web=' . json_encode($webUrl, $jsonFlags) . ';'
        . 'var app=document.getElementById("bridge-app");'
        . 'var webLink=document.getElementById("bridge-web");'
        . 'var hint=document.getElementById("bridge-hint");'
        . 'var title=document.getElementById("bridge-title");'
        . 'var spinner=document.querySelector(".bridge-spinner");'
        . 'if(app){app.href=intent;}'
        . 'if(webLink){webLink.href=web;}'
        . 'var left=false;'
        . 'function markLeft(){left=true;}'
        . 'document.addEventListener("visibilitychange",function(){if(document.hidden){markLeft();}});'
        . 'window.addEventListener("blur",markLeft);'
        . 'setTimeout(function(){'
        . 'if(left||document.hidden){return;}'
        . 'if(hint){hint.hidden=false;}'
        . 'if(title){title.textContent="Tap to open the app";}'
        . 'if(spinner){spinner.style.display="none";}'
        . 'if(app){app.classList.add("is-pulse");}'
        . '},1400);'
        . 'try{window.location.href=intent;}catch(e){}'
        . '})();</script></body></html>';
}
