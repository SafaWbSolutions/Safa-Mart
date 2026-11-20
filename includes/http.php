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
    if (!$hasAccept) {
        $headers[] = 'Accept: ' . (string) ($options['accept'] ?? 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8');
    }
    if (!$hasUserAgent) {
        $headers[] = 'User-Agent: ' . (string) ($options['user_agent'] ?? USER_AGENT);
    }
    $headers[] = 'Accept-Language: en-IN,en;q=0.9';

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

function telegram_send_message($chatId, string $text): array
{
    if (TELEGRAM_BOT_TOKEN === '') {
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
        'timeout'   => 15,
        'max_bytes' => 1048576,
    ]);

    $found = null;
    if ($response['ok'] && $response['status'] >= 200 && $response['status'] < 300) {
        $decoded = json_decode($response['body'], true);
        $found = find_affiliate_url_in_response($decoded !== null ? $decoded : $response['body']);
    }

    if ($found === null) {
        $separator = strpos(EARNKARO_API_URL, '?') === false ? '?' : '&';
        $getUrl = EARNKARO_API_URL . $separator . 'url=' . rawurlencode($url);
        $getResponse = http_request($getUrl, [
            'headers'   => $headers,
            'timeout'   => 15,
            'max_bytes' => 1048576,
        ]);
        if ($getResponse['ok'] && $getResponse['status'] >= 200 && $getResponse['status'] < 300) {
            $decoded = json_decode($getResponse['body'], true);
            $found = find_affiliate_url_in_response($decoded !== null ? $decoded : $getResponse['body']);
        }
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
