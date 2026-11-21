<?php
/**
 * Product page metadata extraction (OpenGraph, Twitter cards, JSON-LD).
 */

function ensure_utf8(string $html, string $contentType = ''): string
{
    $charset = '';
    if (preg_match('/charset\s*=\s*["\']?([a-zA-Z0-9._-]+)/i', $contentType, $match)) {
        $charset = $match[1];
    } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?([a-zA-Z0-9._-]+)/i', $html, $match)) {
        $charset = $match[1];
    }

    if ($charset === '' || strcasecmp($charset, 'UTF-8') === 0) {
        return $html;
    }
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
        return $converted === false ? $html : $converted;
    }
    if (function_exists('iconv')) {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $html);
        return $converted === false ? $html : $converted;
    }
    return $html;
}

/** @return array<string,mixed> */
function parse_meta_tags(string $html): array
{
    $meta = ['__all' => []];
    if (!preg_match_all('/<meta\b[^>]*>/i', $html, $tags)) {
        return $meta;
    }

    $attributePattern = '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/s';
    foreach ($tags[0] as $tag) {
        $attributes = [];
        if (!preg_match_all($attributePattern, $tag, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as $match) {
            $value = '';
            if (isset($match[2]) && $match[2] !== '') {
                $value = $match[2];
            } elseif (isset($match[3]) && $match[3] !== '') {
                $value = $match[3];
            } elseif (isset($match[4])) {
                $value = $match[4];
            }
            $attributes[strtolower($match[1])] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $key = $attributes['property'] ?? ($attributes['name'] ?? ($attributes['itemprop'] ?? ''));
        if ($key === '' || !array_key_exists('content', $attributes)) {
            continue;
        }
        $key = strtolower(trim($key));
        $value = trim($attributes['content']);
        if ($key === '') {
            continue;
        }
        if (!isset($meta[$key])) {
            $meta[$key] = $value;
        }
        $meta['__all'][] = ['key' => $key, 'value' => $value];
    }
    return $meta;
}

/** @param array<string,mixed> $meta */
function meta_first(array $meta, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($meta[$key]) && is_string($meta[$key]) && trim($meta[$key]) !== '') {
            return trim($meta[$key]);
        }
    }
    return '';
}

/**
 * @param mixed $node
 * @param list<array<string,mixed>> $products
 */
function walk_json_ld($node, array &$products, int $depth = 0): void
{
    if ($depth > 12 || !is_array($node)) {
        return;
    }
    if (isset($node['@type'])) {
        $types = is_array($node['@type']) ? $node['@type'] : [$node['@type']];
        foreach ($types as $type) {
            if (is_string($type) && strcasecmp($type, 'Product') === 0) {
                $products[] = $node;
                break;
            }
        }
    }
    foreach ($node as $value) {
        if (is_array($value)) {
            walk_json_ld($value, $products, $depth + 1);
        }
    }
}

/** @return list<array<string,mixed>> */
function collect_json_ld_products(string $html): array
{
    $products = [];
    $pattern = '#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is';
    if (!preg_match_all($pattern, $html, $matches)) {
        return [];
    }
    foreach ($matches[1] as $raw) {
        $raw = trim($raw);
        $raw = preg_replace('/^\s*<!--|-->\s*$/s', '', $raw) ?? $raw;
        if ($raw === '') {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            walk_json_ld($decoded, $products);
        }
    }
    return $products;
}

/** @param array<string,mixed> $product */
function json_ld_offer(array $product): array
{
    $offers = $product['offers'] ?? [];
    if (is_array($offers) && (isset($offers['price']) || isset($offers['lowPrice']) || isset($offers['@type']))) {
        $offers = [$offers];
    }
    if (!is_array($offers)) {
        return [];
    }

    $best = [];
    $bestPrice = null;
    foreach ($offers as $offer) {
        if (!is_array($offer)) {
            continue;
        }
        $price = normalize_price($offer['price'] ?? ($offer['lowPrice'] ?? 0));
        if ($price > 0 && ($bestPrice === null || $price < $bestPrice)) {
            $best = $offer;
            $bestPrice = $price;
        } elseif ($best === [] && (isset($offer['availability']) || isset($offer['priceCurrency']))) {
            $best = $offer;
        }
    }
    return $best;
}

/**
 * @param array<string,mixed> $meta
 * @param array<string,mixed> $jsonProduct
 * @return list<string>
 */
function collect_image_urls(array $meta, array $jsonProduct, string $baseUrl, string $html = ''): array
{
    $candidates = [];

    // A 200x200 OpenGraph image is almost always a site logo/share card, not
    // the product photo. JSON-LD images are still allowed.
    $ogWidth = (int) ($meta['og:image:width'] ?? 0);
    $ogHeight = (int) ($meta['og:image:height'] ?? 0);
    $ogTooSmall = $ogWidth > 0 && $ogHeight > 0 && min($ogWidth, $ogHeight) < 300;

    // JSON-LD product images are the most reliable product photos.
    if (isset($jsonProduct['image'])) {
        $image = $jsonProduct['image'];
        if (is_string($image)) {
            $candidates[] = $image;
        } elseif (is_array($image)) {
            foreach ($image as $item) {
                if (is_string($item)) {
                    $candidates[] = $item;
                } elseif (is_array($item)) {
                    foreach (['url', 'contentUrl', 'thumbnailUrl'] as $key) {
                        if (!empty($item[$key]) && is_string($item[$key])) {
                            $candidates[] = $item[$key];
                        }
                    }
                }
            }
        }
    }

    // OpenGraph / Twitter images as a fallback.
    $imageKeys = [
        'og:image:secure_url', 'og:image:url', 'og:image',
        'twitter:image', 'twitter:image:src', 'image',
    ];
    foreach ($meta['__all'] ?? [] as $entry) {
        if (is_array($entry) && isset($entry['key'], $entry['value'])
            && in_array($entry['key'], $imageKeys, true)
            && trim((string) $entry['value']) !== '') {
            if ($ogTooSmall && str_starts_with($entry['key'], 'og:image')) {
                continue;
            }
            $candidates[] = trim((string) $entry['value']);
        }
    }

    // Last resort: photos rendered in the page itself. DigiHaat and a few
    // Next.js stores put only a site logo in og:image while the real product
    // photos sit in <img> tags or the embedded JSON payload.
    if (trim($html) !== '') {
        if (preg_match_all('~<img\b[^>]*>~i', $html, $imgTags)) {
            foreach ($imgTags[0] as $tag) {
                foreach (['src', 'data-src', 'data-lazy-src', 'data-original'] as $attribute) {
                    if (preg_match('~\b' . $attribute . '\s*=\s*["\']([^"\']+)["\']~i', $tag, $attributeMatch)) {
                        $candidates[] = html_entity_decode($attributeMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
                if (preg_match('~\bsrcset\s*=\s*["\']([^"\']+)["\']~i', $tag, $srcsetMatch)) {
                    foreach (explode(',', $srcsetMatch[1]) as $srcsetPart) {
                        $piece = trim((string) (explode(' ', trim($srcsetPart))[0] ?? ''));
                        if ($piece !== '') {
                            $candidates[] = $piece;
                        }
                    }
                }
            }
        }
        if (preg_match_all('~https?://[^\s"\'<>\\\\]+?\.(?:jpe?g|png|webp)(?:\?[^\s"\'<>\\\\]*)?~i', $html, $imgUrls)) {
            foreach ($imgUrls[0] as $imgUrl) {
                $candidates[] = str_replace(['\\/', '&amp;'], ['/', '&'], $imgUrl);
            }
        }
    }

    $urls = [];
    foreach ($candidates as $candidate) {
        $candidate = html_entity_decode(trim((string) $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($candidate === '') {
            continue;
        }
        $resolved = resolve_url($baseUrl, $candidate);
        if (!preg_match('#^https?://#i', $resolved)) {
            continue;
        }
        if (!is_probable_product_image($resolved)) {
            continue;
        }
        if (!in_array($resolved, $urls, true)) {
            $urls[] = $resolved;
        }
        if (count($urls) >= 8) {
            break;
        }
    }
    return $urls;
}

/**
 * Pull product name/photos out of the embedded store JSON.
 *
 * DigiHaat (ONDC) pages publish only the site logo in OpenGraph; the real
 * product lives in a Next.js payload as
 * {"descriptor":{"name":...,"images":[...]}}.
 *
 * @return array{title:string,images:list<string>}
 */
function extract_embedded_product_data(string $html): array
{
    $empty = ['title' => '', 'images' => []];
    if ($html === '' || strpos($html, 'descriptor') === false) {
        return $empty;
    }
    $decoded = str_replace(['\\"', '\\/', '\\u002F', '\\u002f'], ['"', '/', '/', '/'], $html);
    $anchor = strpos($decoded, '"item_details"');
    $position = $anchor !== false ? strpos($decoded, '"descriptor"', $anchor) : false;
    if ($position === false) {
        $position = strpos($decoded, '"descriptor"');
    }
    if ($position === false) {
        return $empty;
    }
    $window = substr($decoded, $position, 5000);

    $title = '';
    if (preg_match('/"name"\s*:\s*"([^"]{2,300})"/', $window, $nameMatch)) {
        $title = clean_text($nameMatch[1]);
    }

    $images = [];
    if (preg_match('/"images"\s*:\s*\[(.*?)\]/s', $window, $imagesMatch)) {
        if (preg_match_all('~"([^"]+?\.(?:jpe?g|png|webp)(?:\?[^"]*)?)"~i', $imagesMatch[1], $imageMatches)) {
            foreach ($imageMatches[1] as $candidate) {
                $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!is_probable_product_image($candidate) || in_array($candidate, $images, true)) {
                    continue;
                }
                $images[] = $candidate;
                if (count($images) >= 6) {
                    break;
                }
            }
        }
    }

    return ['title' => $title, 'images' => $images];
}

function extract_title_from_html(string $html, array $meta, array $jsonProduct): string
{
    $title = meta_first($meta, ['og:title', 'twitter:title', 'title']);
    if ($title === '' && !empty($jsonProduct['name']) && is_string($jsonProduct['name'])) {
        $title = $jsonProduct['name'];
    }
    if ($title === '' && preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $match)) {
        $title = clean_text($match[1]);
    }
    if ($title === '' && preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match)) {
        $title = clean_text($match[1]);
    }
    $title = clean_text($title);
    if (function_exists('mb_substr')) {
        $title = mb_substr($title, 0, 300, 'UTF-8');
    } else {
        $title = substr($title, 0, 300);
    }
    return trim($title);
}

function extract_price_from_text(string $text): float
{
    if (preg_match('/(?:₹|Rs\.?|INR)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $text, $match)) {
        return normalize_price($match[1]);
    }
    if (preg_match('/"(?:price|salePrice|sellingPrice|finalPrice)"\s*:\s*"?([0-9][0-9,]*(?:\.[0-9]{1,2})?)"/i', $text, $match)) {
        return normalize_price($match[1]);
    }
    return 0.0;
}

function extract_mrp_from_text(string $text): float
{
    // Myntra keeps MRP only in JSON, sometimes escaped inside a Next.js payload.
    if (preg_match('/\\\\?"mrp\\\\?"\s*:\s*"?([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $text, $match)) {
        return normalize_price($match[1]);
    }
    if (preg_match('/(?:M\.?\s*R\.?\s*P\.?|originalPrice|listPrice|strikePrice|markedPrice)[^0-9]{0,20}(?:₹|Rs\.?|INR)?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $text, $match)) {
        return normalize_price($match[1]);
    }
    return 0.0;
}

/**
 * Ajio product pages answer HTTP 403 to non-browser clients, but the public
 * search page embeds the full product grid (name, price, MRP, images).
 */
function scrape_ajio_search_fallback(string $url): array
{
    if (!preg_match('~/p/(\d{5,})~', $url, $codeMatch)) {
        return [];
    }
    $code = $codeMatch[1];

    $path = (string) parse_url($url, PHP_URL_PATH);
    $slug = (string) preg_replace('~/p/.*$~', '', $path);
    $query = trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $slug));
    $query = trim((string) preg_replace('/\s+/', ' ', $query));
    if ($query === '') {
        $query = $code;
    }
    $words = array_slice(explode(' ', $query), 0, 10);

    $searchUrl = 'https://www.ajio.com/search/?text=' . rawurlencode(implode(' ', $words));
    $response = http_request($searchUrl, [
        'timeout'   => HTTP_TIMEOUT,
        'max_bytes' => 6291456,
        'accept'    => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        'referer'   => 'https://www.ajio.com/',
    ]);
    if (!$response['ok'] || (int) $response['status'] !== 200) {
        $reader = http_request_via_reader($searchUrl);
        if ($reader['ok']) {
            $response = $reader;
        }
    }
    if (!$response['ok'] || (int) $response['status'] !== 200) {
        return [];
    }

    $html = (string) $response['body'];
    if (!preg_match('~"' . preg_quote($code, '~') . '\d{3}":\{~', $html, $match, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $window = substr($html, (int) $match[0][1], 16000);

    $price = 0.0;
    if (preg_match('~"price":\{"currencyIso":"INR","formattedValue":"[^"]*","displayformattedValue":"[^"]*","value":([0-9.]+)\}~', $window, $priceMatch)) {
        $price = (float) $priceMatch[1];
    }
    if ($price <= 0 && preg_match('~"offerPrice":\{[^}]*"value":([0-9.]+)~', $window, $offerMatch)) {
        $price = (float) $offerMatch[1];
    }

    $mrp = 0.0;
    if (preg_match('~"wasPriceData":\{[^}]*"value":([0-9.]+)~', $window, $mrpMatch)) {
        $mrp = (float) $mrpMatch[1];
    }
    if ($mrp < $price) {
        $mrp = $price;
    }

    $name = '';
    if (preg_match('~"name":"([^"]+)"~', $window, $nameMatch)) {
        $name = clean_text($nameMatch[1]);
    }
    $brand = '';
    if (preg_match('~"brandName":"([^"]+)"~', $window, $brandMatch)) {
        $brand = clean_text($brandMatch[1]);
    }

    $title = $name;
    if ($brand !== '' && $name !== '' && stripos($name, $brand) === false) {
        $title = $brand . ' ' . $name;
    }
    if ($title === '') {
        return [];
    }

    $images = [];
    if (preg_match('~"images":\[(.*?)\]~', $window, $imagesMatch)) {
        if (preg_match_all('~"url":"(https://assets\.ajio\.com/[^"]+)"~i', $imagesMatch[1], $urlMatches)) {
            foreach ($urlMatches[1] as $imageUrl) {
                $imageUrl = str_replace('\\/', '/', $imageUrl);
                if (!in_array($imageUrl, $images, true)) {
                    $images[] = $imageUrl;
                }
            }
        }
    }
    if (!$images) {
        return [];
    }

    $categoryHint = '';
    if (preg_match('~"brickNameText":"([^"]+)"~', $window, $categoryMatch)) {
        $categoryHint = clean_text($categoryMatch[1]);
    }

    return [
        'title'         => $title,
        'description'   => '',
        'image'         => $images[0] ?? null,
        'images'        => array_slice($images, 0, 6),
        'price'         => $price,
        'mrp'           => $mrp,
        'currency'      => 'INR',
        'brand'         => $brand,
        'category_hint' => $categoryHint,
        'availability'  => 'unknown',
        'platform'      => 'Ajio',
        'canonical_url' => $url,
        'effective_url' => $url,
    ];
}

/**
 * Pull affiliate query parameters (affid, tag, ...) out of a link or the
 * affiliate landing page it points to.
 *
 * EarnKaro short links (bitli.in, linkredirect.in) keep the real tracking
 * parameters only inside the landing page, not on the short URL itself.
 *
 * @return array<string,string>
 */
function affiliate_params_from_url(string $url): array
{
    $keys = ['affid', 'affExtParam1', 'affExtParam2', 'affExtParam3', 'cmpid', 'tag', 'linkCode', 'icid'];

    $collect = static function (string $candidate) use ($keys): array {
        $out = [];
        parse_str((string) parse_url($candidate, PHP_URL_QUERY), $query);
        foreach ($keys as $key) {
            if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                $out[$key] = $query[$key];
            }
        }
        return $out;
    };

    $direct = $collect($url);
    if ($direct) {
        return $direct;
    }

    $response = http_request($url, [
        'timeout'   => HTTP_TIMEOUT,
        'max_bytes' => 2097152,
        'accept'    => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        'referer'   => origin_of_url($url),
    ]);
    if (!$response['ok']) {
        return [];
    }

    $html = str_replace(['\\/', '\\u002F', '\\u002f', '&amp;'], ['/', '/', '/', '&'], (string) $response['body']);
    if (preg_match_all('~https?://[^\s"\'<>\\\\]+~i', $html, $matches)) {
        foreach ($matches[0] as $candidate) {
            $params = $collect($candidate);
            if (isset($params['affid']) || isset($params['tag']) || isset($params['linkCode'])) {
                return $params;
            }
        }
    }
    return [];
}

/**
 * Build the simple Ajio search URL for a listing link.
 */
function ajio_simple_search_url(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $text = trim((string) ($query['text'] ?? ''));
    if ($text === '') {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path)));
        $slug = (string) end($segments);
        $text = trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $slug));
    }
    if ($text === '') {
        return '';
    }
    return 'https://www.ajio.com/search/?text=' . rawurlencode($text);
}

/**
 * Expand a merchant collection/store page into individual product URLs.
 *
 * EarnKaro often shares collection links (for example a Shopsy seller store)
 * that contain many products. The storefront can only show single products,
 * so a few product links are pulled out of that page. EarnKaro / Flipkart
 * affiliate query parameters are copied onto every extracted product link so
 * the click stays tracked.
 *
 * @return list<string>
 */
function expand_collection_url(string $url, int $limit = 2, array $affiliateParams = [], ?array &$debug = null): array
{
    $debug = is_array($debug) ? $debug : [];
    $debug['attempts'] = [];
    $limit = max(1, min(8, $limit));
    $platform = detect_platform($url);
    if ($platform === 'Other') {
        return [];
    }

    $found = [];
    $add = static function (string $candidate, string $base) use (&$found, $platform, $limit): void {
        if (count($found) >= $limit) {
            return;
        }
        $candidate = html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($candidate === '' || str_starts_with($candidate, 'data:')) {
            return;
        }
        $absolute = resolve_url($base, $candidate);
        if (!preg_match('#^https?://#i', $absolute) || !is_safe_remote_url($absolute)) {
            return;
        }
        if (detect_platform($absolute) !== $platform || !is_product_page_url($absolute)) {
            return;
        }
        $clean = canonicalize_product_url($absolute);
        if ($clean !== '' && !in_array($clean, $found, true)) {
            $found[] = $clean;
        }
    };

    $grab = static function (string $html, string $base) use ($add, &$found, $limit): void {
        $html = str_replace(['\\/', '\\u002F', '\\u002f', '&amp;'], ['/', '/', '/', '&'], $html);
        if ($html === '') {
            return;
        }
        if (preg_match_all('~https?://[^\s"\'<>\\\\]+~i', $html, $matches)) {
            foreach ($matches[0] as $candidate) {
                $add($candidate, $base);
                if (count($found) >= $limit) {
                    return;
                }
            }
        }
        if (preg_match_all('~href\s*=\s*["\']([^"\']+)["\']~i', $html, $hrefs)) {
            foreach ($hrefs[1] as $href) {
                $add($href, $base);
                if (count($found) >= $limit) {
                    return;
                }
            }
        }
    };

    $fetch = static function (string $target) use (&$debug): string {
        $response = http_request($target, [
            'timeout'   => HTTP_TIMEOUT,
            'max_bytes' => 6291456,
            'accept'    => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            'referer'   => origin_of_url($target),
        ]);
        $via = 'direct';
        if (!$response['ok'] || (int) $response['status'] !== 200) {
            $reader = http_request_via_reader($target);
            if ($reader['ok']) {
                $response = $reader;
                $via = 'reader';
            }
        }
        $debug['attempts'][] = [
            'url'    => $target,
            'status' => (int) $response['status'],
            'bytes'  => strlen((string) $response['body']),
            'via'    => $via,
            'error'  => (string) ($response['error'] ?? ''),
        ];
        if (!$response['ok'] || (int) $response['status'] !== 200) {
            app_log('info', 'Collection fetch failed', [
                'url'    => $target,
                'status' => (int) $response['status'],
                'error'  => (string) ($response['error'] ?? ''),
            ]);
            return '';
        }
        return (string) $response['body'];
    };

    $html = $fetch($url);
    if ($html !== '') {
        $grab($html, $url);
    }

    // Ajio's long search URLs are rejected by its WAF from some hosts. Retry
    // with the simple /search/?text= form the metadata fallback already uses.
    if (!$found && $platform === 'Ajio') {
        $simple = ajio_simple_search_url($url);
        if ($simple !== '') {
            $html = $fetch($simple);
            if ($html !== '') {
                $grab($html, $simple);
            }
        }
    }

    app_log('info', 'Collection expansion', [
        'url'      => $url,
        'platform' => $platform,
        'found'    => count($found),
    ]);
    $debug['found'] = count($found);
    $debug['platform'] = $platform;

    if (!$found) {
        return [];
    }

    // Copy EarnKaro / Flipkart affiliate parameters onto each product link.
    $carry = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    foreach (['affid', 'affExtParam1', 'affExtParam2', 'affExtParam3', 'cmpid', 'tag', 'linkCode', 'icid'] as $key) {
        if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
            $carry[$key] = $query[$key];
        }
    }
    foreach ($affiliateParams as $key => $value) {
        if (!isset($carry[$key]) && is_string($value) && $value !== '') {
            $carry[$key] = $value;
        }
    }
    if (!$carry) {
        return $found;
    }

    $result = [];
    foreach ($found as $productUrl) {
        $parts = parse_url($productUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            continue;
        }
        $existing = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $existing);
        }
        $merged = array_merge($existing, $carry);
        $result[] = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . (string) ($parts['path'] ?? '/')
            . '?' . http_build_query($merged);
    }
    return $result;
}

/**
 * Scrape OpenGraph and JSON-LD product data.
 *
 * @return array<string,mixed>
 */
function scrape_product_metadata(string $url): array
{
    $response = http_request($url, [
        'timeout'   => HTTP_TIMEOUT,
        'max_bytes' => 4194304,
        'accept'    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'referer'   => origin_of_url($url),
    ]);

    if (!$response['ok'] || $response['status'] < 200 || $response['status'] >= 400) {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_contains($host, 'ajio.com')) {
            $fallback = scrape_ajio_search_fallback($url);
            if ($fallback) {
                return $fallback;
            }
        }
        throw new RuntimeException('Could not load the product page (HTTP ' . $response['status'] . ').');
    }

    $contentType = strtolower($response['content_type']);
    if ($contentType !== '' && strpos($contentType, 'text/html') === false && strpos($contentType, 'application/xhtml') === false) {
        throw new RuntimeException('The product URL did not return an HTML page.');
    }

    $html = ensure_utf8($response['body'], $contentType);
    if (trim($html) === '') {
        throw new RuntimeException('The product page was empty.');
    }

    $head = strtolower(substr($html, 0, 15000));
    if (preg_match('/access denied|captcha|unusual traffic|robot check|verify you are (a )?human/i', $head)
        && stripos($html, 'og:title') === false) {
        throw new RuntimeException('The merchant blocked the request. Try again later.');
    }

    $meta = parse_meta_tags($html);
    $jsonProducts = collect_json_ld_products($html);
    $jsonProduct = $jsonProducts[0] ?? [];
    $offer = json_ld_offer($jsonProduct);

    $title = extract_title_from_html($html, $meta, $jsonProduct);
    if ($title === '') {
        throw new RuntimeException('Could not find a product title on the page.');
    }

    $description = meta_first($meta, ['og:description', 'twitter:description', 'description']);
    if ($description === '' && !empty($jsonProduct['description']) && is_string($jsonProduct['description'])) {
        $description = clean_text($jsonProduct['description']);
    }

    $currency = strtoupper(trim((string) ($offer['priceCurrency'] ?? '')));
    $price = 0.0;
    $priceCandidates = [
        $offer['price'] ?? null,
        $offer['lowPrice'] ?? null,
        $meta['product:price:amount'] ?? null,
        $meta['og:price:amount'] ?? null,
        $meta['product:sale_price:amount'] ?? null,
        $meta['twitter:data1'] ?? null,
        $meta['price'] ?? null,
    ];
    foreach ($priceCandidates as $candidate) {
        $candidatePrice = normalize_price($candidate);
        if ($candidatePrice > 0) {
            $price = $candidatePrice;
            break;
        }
    }
    if ($price <= 0) {
        $price = extract_price_from_text($html);
    }

    if ($currency !== '' && $currency !== 'INR' && $price > 0) {
        throw new RuntimeException('This product is not priced in Indian Rupees.');
    }
    $currency = 'INR';

    $mrp = 0.0;
    $mrpCandidates = [
        $offer['highPrice'] ?? null,
        $meta['product:original_price:amount'] ?? null,
        $meta['og:price:standard_amount'] ?? null,
        $meta['product:list_price:amount'] ?? null,
        $meta['product:mrp:amount'] ?? null,
    ];
    foreach ($mrpCandidates as $candidate) {
        $candidateMrp = normalize_price($candidate);
        if ($candidateMrp > 0) {
            $mrp = $candidateMrp;
            break;
        }
    }
    if ($mrp <= 0) {
        $mrp = extract_mrp_from_text($html);
    }
    // Last resort: derive MRP from the "45% OFF" label on the page.
    if ($mrp <= $price && $price > 0 && preg_match('/(\d{1,2})\s*%\s*OFF/i', $html, $offMatch)) {
        $off = (int) $offMatch[1];
        if ($off > 0 && $off < 90) {
            $mrp = round($price / (1 - $off / 100), 2);
        }
    }
    if ($mrp < $price) {
        $mrp = $price;
    }

    $availabilityRaw = strtolower((string) ($offer['availability'] ?? ($meta['product:availability'] ?? '')));
    $availability = 'unknown';
    if (preg_match('/outofstock|soldout|discontinued|out of stock|sold out/i', $availabilityRaw)) {
        $availability = 'out_of_stock';
    } elseif (preg_match('/instock|limitedavailability|in stock/i', $availabilityRaw)) {
        $availability = 'in_stock';
    }

    $brand = meta_first($meta, ['product:brand', 'og:brand']);
    if ($brand === '' && isset($jsonProduct['brand'])) {
        $brandData = $jsonProduct['brand'];
        if (is_string($brandData)) {
            $brand = $brandData;
        } elseif (is_array($brandData) && !empty($brandData['name']) && is_string($brandData['name'])) {
            $brand = $brandData['name'];
        }
    }

    $categoryHint = meta_first($meta, ['product:category', 'article:section', 'category']);
    if ($categoryHint === '' && !empty($jsonProduct['category']) && is_string($jsonProduct['category'])) {
        $categoryHint = clean_text($jsonProduct['category']);
    }

    $images = collect_image_urls($meta, $jsonProduct, $response['effective_url'] ?? $url, $html);

    // DigiHaat / ONDC pages hide the real product in the embedded JSON.
    $embedded = extract_embedded_product_data($html);
    if ($embedded['title'] !== '') {
        $title = $embedded['title'];
    }
    if ($embedded['images']) {
        $images = array_values(array_unique(array_merge($embedded['images'], $images)));
        $images = array_slice($images, 0, 8);
    }

    $canonical = meta_first($meta, ['og:url', 'twitter:url']);
    if ($canonical !== '' && preg_match('#^https?://#i', $canonical)) {
        $canonical = resolve_url($response['effective_url'] ?? $url, $canonical);
    } else {
        $canonical = $response['effective_url'] ?? $url;
    }

    return [
        'title'          => $title,
        'description'    => truncate_text($description, 1800),
        'image'          => $images[0] ?? null,
        'images'         => $images,
        'price'          => $price,
        'mrp'            => $mrp,
        'currency'       => $currency,
        'brand'          => clean_text($brand),
        'category_hint'  => clean_text($categoryHint),
        'availability'   => $availability,
        'platform'       => detect_platform($response['effective_url'] ?? $url),
        'canonical_url'  => $canonical,
        'effective_url'  => $response['effective_url'] ?? $url,
    ];
}
