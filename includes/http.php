<?php
/**
 * Outbound HTTP client with SSRF protection, plus Telegram and EarnKaro helpers.
 */

function safa_public_ip(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $cgnatStart = ip2long('100.64.0.0');
        $cgnatEnd = ip2long('100.127.255.255');
        if ($cgnatStart !== false && $cgnatEnd !== false && $long >= $cgnatStart && $long <= $cgnatEnd) {
            return false;
        }
        return true;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $normalized = strtolower($ip);
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/', $normalized, $match)) {
            return safa_public_ip($match[1]);
        }
        if ($normalized === '::1' || $normalized === '::' || str_starts_with($normalized, '2001:db8:')) {
            return false;
        }
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    return false;
}

function is_safe_remote_url(string $url): bool
{
    static $cache = [];
    $url = trim($url);
    if ($url === '' || strlen($url) > 4096) {
        return false;
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
        return false;
    }

    $host = strtolower(trim((string) $parts['host'], '[]'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
        || str_ends_with($host, '.home.arpa')) {
        return false;
    }

    if (preg_match('/[^\x20-\x7E]/', $host)) {
        if (!function_exists('idn_to_ascii')) {
            return false;
        }
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false) {
            return false;
        }
        $host = strtolower($ascii);
    }

    if (array_key_exists($host, $cache)) {
        return $cache[$host];
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
    }

    $ips = array_values(array_unique($ips));
    if (!$ips) {
        $cache[$host] = false;
        return false;
    }
    foreach ($ips as $ip) {
        if (!safa_public_ip($ip)) {
            $cache[$host] = false;
            return false;
        }
    }
    $cache[$host] = true;
    return true;
}

function normalize_url_path(string $path): string
{
    $suffix = '';
    if (preg_match('/^([^?#]*)(.*)$/s', $path, $match)) {
        $path = $match[1];
        $suffix = $match[2];
    }
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    $leading = str_starts_with($path, '/') ? '/' : '';
    return $leading . implode('/', $segments) . $suffix;
}

function resolve_url(string $base, string $relative): string
{
    $relative = trim($relative);
    if ($relative === '') {
        return $base;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative)) {
        return $relative;
    }

    $baseParts = parse_url($base);
    if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return $relative;
    }
    $scheme = (string) $baseParts['scheme'];
    $authority = $scheme . '://' . $baseParts['host']
        . (isset($baseParts['port']) ? ':' . (int) $baseParts['port'] : '');

    if (str_starts_with($relative, '//')) {
        return $scheme . ':' . $relative;
    }
    if ($relative[0] === '#') {
        return $authority . ($baseParts['path'] ?? '/')
            . (!empty($baseParts['query']) ? '?' . $baseParts['query'] : '')
            . $relative;
    }
    if ($relative[0] === '?') {
        return $authority . ($baseParts['path'] ?? '/') . $relative;
    }
    if ($relative[0] === '/') {
        return $authority . normalize_url_path($relative);
    }

    $basePath = (string) ($baseParts['path'] ?? '/');
    $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
    return $authority . normalize_url_path($directory . $relative);
}

/**
 * Add the browser fingerprint headers that storefront WAFs (Akamai, PerimeterX)
 * expect. Without them Ajio/Hamara style sites answer with HTTP 403.
 *
 * @param list<string> $headers
 * @return list<string>
 */
function add_browser_fingerprint_headers(array $headers, bool $isImageRequest = false): array
{
    $present = [];
    $userAgent = '';
    foreach ($headers as $header) {
        $header = (string) $header;
        $separator = strpos($header, ':');
        if ($separator === false) {
            continue;
        }
        $name = strtolower(trim(substr($header, 0, $separator)));
        $present[$name] = true;
        if ($name === 'user-agent') {
            $userAgent = trim(substr($header, $separator + 1));
        }
    }

    $chromeMajor = '131';
    if ($userAgent !== '' && preg_match('~Chrome/(\d+)\.~', $userAgent, $match)) {
        $chromeMajor = $match[1];
    }

    $defaults = [
        'accept-language'           => 'en-IN,en;q=0.9',
        'sec-ch-ua'                 => '"Chromium";v="' . $chromeMajor . '", "Not_A Brand";v="24", "Google Chrome";v="' . $chromeMajor . '"',
        'sec-ch-ua-mobile'          => '?0',
        'sec-ch-ua-platform'        => '"Windows"',
        'sec-fetch-dest'            => $isImageRequest ? 'image' : 'document',
        'sec-fetch-mode'            => $isImageRequest ? 'no-cors' : 'navigate',
        'sec-fetch-site'            => 'none',
        'upgrade-insecure-requests' => '1',
    ];

    foreach ($defaults as $name => $value) {
        if (!isset($present[$name])) {
            $headers[] = $name . ': ' . $value;
        }
    }

    return $headers;
}

function origin_of_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $origin = strtolower((string) $parts['scheme']) . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }
    return $origin . '/';
}

/**
 * @param array<string,mixed> $options
 * @return array{ok:bool,status:int,body:string,headers:array<string,string>,content_type:string,error:string,effective_url?:string}
 */
function http_request(string $url, array $options = []): array
{
    $method = strtoupper((string) ($options['method'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
        $method = 'GET';
    }

    $headers = $options['headers'] ?? [];
    if (!is_array($headers)) {
        $headers = [];
    }
    $body = array_key_exists('body', $options) ? (string) $options['body'] : null;

    if (array_key_exists('json', $options)) {
        try {
            $body = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [
                'ok' => false, 'status' => 0, 'body' => '', 'headers' => [],
                'content_type' => '', 'error' => 'Request JSON encoding failed.',
            ];
        }
        $hasContentType = false;
        foreach ($headers as $header) {
            if (stripos((string) $header, 'content-type:') === 0) {
                $hasContentType = true;
                break;
            }
        }
        if (!$hasContentType) {
            $headers[] = 'Content-Type: application/json';
        }
    }

    $hasAccept = false;
    $hasUserAgent = false;
    foreach ($headers as $header) {
        if (stripos((string) $header, 'accept:') === 0) {
            $hasAccept = true;
        }
        if (stripos((string) $header, 'user-agent:') === 0) {
            $hasUserAgent = true;
        }
    }
    $acceptValue = (string) ($options['accept'] ?? 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8');
    if (!$hasAccept) {
        $headers[] = 'Accept: ' . $acceptValue;
    }
    if (!$hasUserAgent) {
        $headers[] = 'User-Agent: ' . (string) ($options['user_agent'] ?? USER_AGENT);
    }

    $isDocumentRequest = stripos($acceptValue, 'text/html') !== false || stripos($acceptValue, 'application/xhtml') !== false;
    $isImageRequest = stripos($acceptValue, 'image/') !== false;
    if ($isDocumentRequest || $isImageRequest) {
        $headers = add_browser_fingerprint_headers($headers, $isImageRequest);
    }

    $referer = trim((string) ($options['referer'] ?? ''));
    if ($referer !== '') {
        $headers[] = 'Referer: ' . $referer;
    }

    $timeout = max(3, min(30, (int) ($options['timeout'] ?? HTTP_TIMEOUT)));
    $maxBytes = max(1024, (int) ($options['max_bytes'] ?? 4194304));

    $cookieFile = @tempnam(sys_get_temp_dir(), 'safa_http_');
    if ($cookieFile === false) {
        $cookieFile = null;
    }

    $current = trim($url);
    $result = [
        'ok' => false, 'status' => 0, 'body' => '', 'headers' => [],
        'content_type' => '', 'error' => 'Request failed.',
    ];

    try {
        for ($hop = 0; $hop <= 5; $hop++) {
            if (!is_safe_remote_url($current)) {
                $result['error'] = 'URL is invalid or resolves to a non-public address.';
                return $result;
            }

            $result = http_request_once($current, $method, $headers, $body, $maxBytes, $timeout, $cookieFile);
            $result['effective_url'] = $current;

            if ($result['status'] === 0) {
                return $result;
            }

            if (in_array($result['status'], [301, 302, 303, 307, 308], true)
                && !empty($result['headers']['location'])) {
                $next = resolve_url($current, $result['headers']['location']);
                if (!is_safe_remote_url($next)) {
                    $result['error'] = 'Redirect target is invalid or non-public.';
                    return $result;
                }
                if ($method === 'POST' && in_array($result['status'], [301, 302, 303], true)) {
                    $method = 'GET';
                    $body = null;
                    $headers = array_values(array_filter($headers, static function (string $header): bool {
                        return stripos($header, 'content-type:') !== 0;
                    }));
                }
                $current = $next;
                continue;
            }

            return $result;
        }

        $result['error'] = 'Too many redirects.';
        return $result;
    } finally {
        if ($cookieFile !== null && is_file($cookieFile)) {
            @unlink($cookieFile);
        }
    }
}

/**
 * @param list<string> $headers
 * @return array{ok:bool,status:int,body:string,headers:array<string,string>,content_type:string,error:string}
 */
function http_request_once(
    string $url,
    string $method,
    array $headers,
    ?string $body,
    int $maxBytes,
    int $timeout,
    ?string $cookieFile
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'content_type' => '', 'error' => 'Unable to initialise cURL.'];
    }

    $responseBody = '';
    $responseHeaders = [];
    $tooLarge = false;

    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => false,
        // Akamai-fronted stores (Ajio, Tata CLiQ) reject cURL's HTTP/2
        // fingerprint with HTTP 403; HTTP/1.1 is served normally.
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ];
    if (defined('CURLOPT_PROTOCOLS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS')) {
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if ($cookieFile !== null) {
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
    }

    if ($method === 'HEAD') {
        $options[CURLOPT_NOBODY] = true;
    } elseif ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body ?? '';
    } else {
        $options[CURLOPT_HTTPGET] = true;
    }

    $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$responseBody, &$tooLarge, $maxBytes): int {
        $length = strlen($chunk);
        if (strlen($responseBody) + $length > $maxBytes) {
            $tooLarge = true;
            return 0;
        }
        $responseBody .= $chunk;
        return $length;
    };
    $options[CURLOPT_HEADERFUNCTION] = static function ($handle, string $line) use (&$responseHeaders): int {
        $length = strlen($line);
        $trimmed = trim($line);
        if (stripos($trimmed, 'HTTP/') === 0) {
            $responseHeaders = [];
            return $length;
        }
        $separator = strpos($trimmed, ':');
        if ($separator !== false) {
            $name = strtolower(trim(substr($trimmed, 0, $separator)));
            $value = trim(substr($trimmed, $separator + 1));
            if ($name !== '') {
                $responseHeaders[$name] = $value;
            }
        }
        return $length;
    };

    curl_setopt_array($ch, $options);
    $executed = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
    $error = curl_error($ch);
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }

    if ($tooLarge) {
        return [
            'ok' => false, 'status' => $status, 'body' => '',
            'headers' => $responseHeaders, 'content_type' => $contentType,
            'error' => 'Response exceeded the size limit.',
        ];
    }
    if ($executed === false) {
        return [
            'ok' => false, 'status' => $status, 'body' => '',
            'headers' => $responseHeaders, 'content_type' => $contentType,
            'error' => $error !== '' ? $error : 'Network request failed.',
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'body' => $responseBody,
        'headers' => $responseHeaders,
        'content_type' => $contentType,
        'error' => '',
    ];
}

/**
 * Store domains we can scrape for product data.
 *
 * @return list<string>
 */
function merchant_domains(): array
{
    return [
        'flipkart.com', 'fkrt.it', 'fkrt.co',
        'myntra.com', 'myntr.it',
        'ajio.com',
        'amazon.in', 'amazon.com', 'amzn.in', 'amzn.to',
        'snapdeal.com', 'tatacliq.com', 'nykaa.com', 'nykaafashion.com',
        'croma.com', 'firstcry.com', 'lifestylestores.com', 'shoppersstop.com',
        'bewakoof.com', 'decathlon.in', 'boat-lifestyle.com', 'mamaearth.in',
        'wakefit.co', 'pepperfry.com', 'ikea.com', 'jiomart.com', 'bigbasket.com',
    ];
}

function is_merchant_url(string $url): bool
{
    return detect_platform($url) !== 'Other';
}

/**
 * Short-link domains that must be expanded even when they map to a known store,
 * because the landing page is not the product page.
 */
function needs_url_resolution(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', rtrim($host, '.')) ?? '';
    if ($host === '') {
        return true;
    }

    $shorteners = [
        'fkrt.it', 'fkrt.co', 'dl.flipkart.com', 'myntr.it', 'amzn.in', 'amzn.to',
        'ekaro.in', 'earnkaro.com', 'bitli.in', 'cutt.ly', 'bit.ly', 'tinyurl.com',
        'rb.gy', 'shorturl.at', 't.co', 'tiny.one', 'linktr.ee',
        'ajjio.in', 'ajio.in', 'ajiio.in', 'ajio.co', 'myntr.in', 'flipk.art',
        'earnkaro.in', 'earnkaro.co', 'ekaro.co',
    ];

    return in_array($host, $shorteners, true);
}

/**
 * Pull the best merchant product URL out of an intermediate page
 * (affiliate landing pages, meta refreshes, JSON payloads).
 */
function find_merchant_url_in_html(string $html, string $baseUrl): string
{
    if (trim($html) === '') {
        return '';
    }

    $domains = implode('|', array_map(static function (string $domain): string {
        return str_replace('.', '\\.', $domain);
    }, merchant_domains()));

    $absolutePattern = '~https?://[a-z0-9.\-]*(?:' . $domains . ')(?::\d+)?/[^\s"\'<>\\\\]*~i';
    $relativePattern = '~//[a-z0-9.\-]*(?:' . $domains . ')(?::\d+)?/[^\s"\'<>\\\\]*~i';

    $views = [$html, urldecode($html), rawurldecode($html)];
    $candidates = [];
    foreach ($views as $view) {
        $view = str_replace(
            ['\\/', '\\u002F', '\\u002f', '&amp;', '&#038;', '&#x26;'],
            ['/', '/', '/', '&', '&', '&'],
            $view
        );
        $view = html_entity_decode($view, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match_all($absolutePattern, $view, $matches)) {
            foreach ($matches[0] as $match) {
                $candidates[] = $match;
            }
        }
        if (preg_match_all($relativePattern, $view, $matches)) {
            foreach ($matches[0] as $match) {
                $candidates[] = 'https:' . $match;
            }
        }
    }

    $candidates = array_unique($candidates);
    if (!$candidates) {
        return '';
    }

    $best = '';
    $bestScore = -100;
    foreach ($candidates as $candidate) {
        $candidate = rtrim(trim((string) $candidate), ".,;:!?)]}'\"");
        if (!preg_match('#^https?://#i', $candidate)) {
            continue;
        }
        $path = strtolower((string) parse_url($candidate, PHP_URL_PATH));
        $score = 0;
        if (preg_match('#/(?:p|dp|pd|buy|product|gp/product)/#', $path)) {
            $score += 4;
        }
        if (preg_match('/(?:-pd-|\/p\/|\/dp\/|itm)/', $path)) {
            $score += 3;
        }
        if (substr_count($path, '/') >= 3) {
            $score += 1;
        }
        if (preg_match('/(?:search|category|collection|sitemap|login|cart|help)/', $path)) {
            $score -= 4;
        }
        if (strlen($candidate) > 60) {
            $score += 1;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }

    return $best;
}

/**
 * Try the redirect/deeplink values carried in a URL's own query string.
 */
function find_merchant_url_in_query(string $url): string
{
    $query = (string) parse_url($url, PHP_URL_QUERY);
    if ($query === '') {
        return '';
    }

    parse_str($query, $params);
    $keys = ['url', 'u', 'r', 'redirect', 'redirect_url', 'target', 'to', 'dest', 'destination', 'link', 'deep_link', 'deeplink', 'dl'];
    foreach ($keys as $key) {
        if (empty($params[$key]) || !is_string($params[$key])) {
            continue;
        }
        $candidate = resolve_url($url, trim($params[$key]));
        if (is_merchant_url($candidate)) {
            return canonicalize_product_url($candidate);
        }
        $found = find_merchant_url_in_html($candidate, $candidate);
        if ($found !== '') {
            return $found;
        }
    }

    return '';
}

/**
 * Follow affiliate short links and JS/refresh redirects until a supported
 * merchant product URL is reached.
 */
function resolve_merchant_url(string $url, int $maxHops = 4): string
{
    $current = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($current === '' || !preg_match('#^https?://#i', $current)) {
        return $current;
    }

    for ($hop = 0; $hop < $maxHops; $hop++) {
        if (is_merchant_url($current) && !needs_url_resolution($current)) {
            return canonicalize_product_url($current);
        }

        $fromQuery = find_merchant_url_in_query($current);
        if ($fromQuery !== '') {
            return canonicalize_product_url($fromQuery);
        }

        if (!is_safe_remote_url($current)) {
            return $current;
        }

        $response = http_request($current, [
            'timeout'   => 12,
            'max_bytes' => 2097152,
            'accept'    => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        ]);

        if (!$response['ok']) {
            $location = trim((string) ($response['headers']['location'] ?? ''));
            if ($location !== '') {
                $next = resolve_url($current, $location);
                if (is_merchant_url($next)) {
                    return canonicalize_product_url($next);
                }
                $found = find_merchant_url_in_html($location, $next);
                if ($found !== '') {
                    return canonicalize_product_url($found);
                }
                $fromQuery = find_merchant_url_in_query($next);
                if ($fromQuery !== '') {
                    return canonicalize_product_url($fromQuery);
                }
            }
            return $current;
        }

        $effective = trim((string) ($response['effective_url'] ?? ''));
        if ($effective !== '' && $effective !== $current && is_merchant_url($effective)) {
            return canonicalize_product_url($effective);
        }

        if ($effective !== '' && $effective !== $current) {
            $fromQuery = find_merchant_url_in_query($effective);
            if ($fromQuery !== '') {
                return canonicalize_product_url($fromQuery);
            }
        }

        $found = find_merchant_url_in_html((string) $response['body'], $effective !== '' ? $effective : $current);
        if ($found !== '') {
            return canonicalize_product_url($found);
        }

        if ($effective === '' || $effective === $current) {
            break;
        }
        $current = $effective;
    }

    return $current;
}

/**
 * Reject obvious logos, icons, sprites and placeholders when picking a product image.
 */
function is_probable_product_image(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $path = strtolower((string) ($parts['path'] ?? ''));
    if ($path === '') {
        return false;
    }
    if (preg_match('~(?:^|[/_.-])(?:logo|logos|sprite|sprites|placeholder|noimage|no-image|default-image|default_image|site-icon|favicon|banner)(?:[/_.-]|$)~i', $path)) {
        return false;
    }
    if (preg_match('/\.(?:jpe?g|png|webp|avif|gif)$/i', $path)) {
        return true;
    }
    if (preg_match('~(?:^|/)(?:image|images|img|media|medias|photo|photos|product|products)(?:/|$)~i', $path)) {
        return true;
    }
    $query = strtolower((string) ($parts['query'] ?? ''));
    return (bool) preg_match('/(?:image|img|photo|media)=/i', $query);
}

function telegram_send_message($chatId, string $text): array
{    if (TELEGRAM_BOT_TOKEN === '') {
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'content_type' => '', 'error' => 'Telegram token is not configured.'];
    }

    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'content_type' => '', 'error' => 'Empty message.'];
    }
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, 3900, 'UTF-8');
    } else {
        $text = substr($text, 0, 3900);
    }

    $endpoint = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $response = http_request($endpoint, [
        'method'    => 'POST',
        'json'      => [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ],
        'timeout'   => 10,
        'max_bytes' => 1048576,
    ]);

    if (!$response['ok'] || $response['status'] !== 200) {
        $plain = trim(strip_tags($text));
        $response = http_request($endpoint, [
            'method'    => 'POST',
            'json'      => [
                'chat_id'                  => $chatId,
                'text'                     => $plain,
                'disable_web_page_preview' => true,
            ],
            'timeout'   => 10,
            'max_bytes' => 1048576,
        ]);
    }

    if (!$response['ok'] || $response['status'] !== 200) {
        app_log('warning', 'Telegram sendMessage failed', [
            'status' => $response['status'],
            'error'  => $response['error'],
        ]);
    }
    return $response;
}

/** @param mixed $data */
function find_affiliate_url_in_response($data, int $depth = 0): ?string
{
    if ($depth > 10) {
        return null;
    }
    if (is_string($data)) {
        $candidate = trim($data);
        return preg_match('#^https?://#i', $candidate) ? $candidate : null;
    }
    if (!is_array($data)) {
        return null;
    }

    $priority = [
        'affiliate_url', 'affiliateUrl', 'affiliate_link', 'affiliateLink',
        'converted_url', 'convertedUrl', 'short_url', 'shortUrl',
        'deeplink', 'deep_link', 'deepLink', 'tracking_url', 'trackingUrl',
    ];
    foreach ($priority as $key) {
        if (isset($data[$key]) && is_string($data[$key]) && preg_match('#^https?://#i', trim($data[$key]))) {
            return trim($data[$key]);
        }
    }
    foreach ($data as $key => $value) {
        if (in_array(strtolower((string) $key), ['url', 'link'], true)
            && is_string($value) && preg_match('#^https?://#i', trim($value))) {
            return trim($value);
        }
    }
    foreach ($data as $value) {
        if (is_array($value)) {
            $found = find_affiliate_url_in_response($value, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

/**
 * Convert a merchant URL into an EarnKaro affiliate URL.
 *
 * @return array{url:string,platform:string,converted:bool,error:?string}
 */
function convert_to_affiliate(string $url, ?string $platform = null): array
{
    $url = trim($url);
    $platform = $platform ?? detect_platform($url);
    if (!preg_match('#^https?://#i', $url)) {
        return ['url' => $url, 'platform' => $platform, 'converted' => false, 'error' => 'Invalid product URL.'];
    }

    if (!in_array($platform, EARNKARO_PLATFORMS, true)) {
        return [
            'url' => $url,
            'platform' => $platform,
            'converted' => false,
            'error' => 'This store is not in the EarnKaro supported list; the original URL was kept.',
        ];
    }

    if (EARNKARO_TOKEN === '' || EARNKARO_API_URL === '') {
        return [
            'url' => $url,
            'platform' => $platform,
            'converted' => false,
            'error' => 'EarnKaro is not configured; the original URL was kept.',
        ];
    }

    $headers = [
        'Authorization: Bearer ' . EARNKARO_TOKEN,
        'Accept: application/json',
    ];

    $response = http_request(EARNKARO_API_URL, [
        'method'    => 'POST',
        'json'      => ['url' => $url],
        'headers'   => $headers,
        'timeout'   => EARNKARO_TIMEOUT,
        'max_bytes' => 1048576,
    ]);

    $found = null;
    if ($response['ok'] && $response['status'] >= 200 && $response['status'] < 300) {
        $decoded = json_decode($response['body'], true);
        $found = find_affiliate_url_in_response($decoded !== null ? $decoded : $response['body']);
    }

    if ($found === null && $response['status'] !== 0) {
        app_log('warning', 'EarnKaro POST returned no affiliate URL', [
            'platform' => $platform,
            'status'   => $response['status'],
            'error'    => $response['error'],
        ]);
    }

    if ($found !== null && preg_match('#^https?://#i', $found)) {
        return ['url' => $found, 'platform' => $platform, 'converted' => true, 'error' => null];
    }

    app_log('warning', 'EarnKaro conversion failed; keeping original URL', [
        'platform' => $platform,
        'status'   => $response['status'],
        'error'    => $response['error'],
    ]);
    return [
        'url' => $url,
        'platform' => $platform,
        'converted' => false,
        'error' => 'EarnKaro conversion failed; the original URL was kept.',
    ];
}
