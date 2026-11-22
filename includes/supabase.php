<?php
/**
 * Supabase REST API client and product queries.
 */

/**
 * @param mixed $body
 * @param array{service?:bool,prefer?:string,headers?:list<string>,timeout?:int} $options
 * @return array{ok:bool,status:int,data:mixed,error:string}
 */
function supabase_request(string $method, string $path, $body = null, array $options = []): array
{
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Unsupported Supabase method.'];
    }
    if (SUPABASE_URL === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Supabase URL is not configured.'];
    }

    $service = !empty($options['service']);
    $key = $service ? SUPABASE_SERVICE_KEY : SUPABASE_ANON_KEY;
    if ($key === '') {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => $service ? 'Supabase service key is not configured.' : 'Supabase anon key is not configured.',
        ];
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . ltrim($path, '/');
    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
        'User-Agent: SafaMart/1.0',
    ];

    if (array_key_exists('headers', $options) && is_array($options['headers'])) {
        foreach ($options['headers'] as $header) {
            $headers[] = (string) $header;
        }
    }
    if (!empty($options['prefer']) && is_string($options['prefer'])) {
        $headers[] = 'Prefer: ' . $options['prefer'];
    }

    $payload = null;
    if ($body !== null) {
        try {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Request encoding failed.'];
        }
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Unable to initialise cURL.'];
    }

    $timeout = max(3, min(30, (int) ($options['timeout'] ?? HTTP_TIMEOUT)));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $transportError = curl_error($ch);
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => $status,
            'data' => null,
            'error' => $transportError !== '' ? $transportError : 'Supabase request failed.',
        ];
    }

    $decoded = null;
    if (trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $decoded = $raw;
        }
    }

    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status, 'data' => $decoded, 'error' => ''];
    }

    $message = 'Supabase request failed with HTTP ' . $status . '.';
    if (is_array($decoded)) {
        foreach (['message', 'details', 'hint', 'error_description', 'error'] as $keyName) {
            if (!empty($decoded[$keyName]) && is_string($decoded[$keyName])) {
                $message = $decoded[$keyName];
                break;
            }
        }
    } elseif (is_string($decoded) && trim($decoded) !== '') {
        $message = trim(substr($decoded, 0, 300));
    }

    return ['ok' => false, 'status' => $status, 'data' => $decoded, 'error' => $message];
}

function supabase_table(): string
{
    $table = defined('SUPABASE_TABLE') ? SUPABASE_TABLE : 'products';
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
        return 'products';
    }
    return $table;
}

/**
 * Fetch rows from a table.
 *
 * @return list<array<string,mixed>>
 */
function supabase_select(string $table, string $query = '', bool $service = false): array
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
        return [];
    }
    $path = $table . ($query !== '' ? '?' . ltrim($query, '?') : '?select=*');
    $result = supabase_request('GET', $path, null, ['service' => $service]);
    if (!$result['ok']) {
        app_log('error', 'Supabase select failed', [
            'table' => $table,
            'status' => $result['status'],
            'error' => $result['error'],
        ]);
        return [];
    }
    return is_array($result['data']) ? $result['data'] : [];
}

/**
 * @param array<string,mixed>|array<int,array<string,mixed>> $rows
 * @return array{ok:bool,status:int,data:mixed,error:string}
 */
function supabase_upsert(array $rows, string $onConflict = 'original_url'): array
{
    $list = array_is_list($rows) ? $rows : [$rows];
    if (!$list) {
        return ['ok' => true, 'status' => 200, 'data' => [], 'error' => ''];
    }
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $onConflict)) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Invalid upsert conflict column.'];
    }

    $table = supabase_table();
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
        $table = 'products';
    }
    $path = $table . '?on_conflict=' . rawurlencode($onConflict) . '&select=*';
    return supabase_request('POST', $path, $list, [
        'service' => true,
        'prefer'  => 'resolution=merge-duplicates,return=representation',
        'timeout' => 20,
    ]);
}

/**
 * @param array<string,mixed> $values
 * @return array{ok:bool,status:int,data:mixed,error:string}
 */
function supabase_patch(string $query, array $values): array
{
    $query = ltrim(trim($query), '?');
    if ($query === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Refusing to run an unfiltered update.'];
    }
    $table = supabase_table();
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
        $table = 'products';
    }
    return supabase_request('PATCH', $table . '?' . $query, $values, [
        'service' => true,
        'prefer'  => 'return=minimal',
        'timeout' => 15,
    ]);
}

/**
 * @param array<string,mixed> $params
 * @return array{ok:bool,status:int,data:mixed,error:string}
 */
function supabase_rpc(string $function, array $params = [], bool $service = false, int $timeout = 15): array
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $function)) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Invalid RPC function name.'];
    }
    return supabase_request('POST', 'rpc/' . $function, $params, [
        'service' => $service,
        'timeout' => $timeout,
    ]);
}

/**
 * @param array{q?:string,category?:string,platform?:string,offset?:int,sort?:string,min_price?:float,max_price?:float,min_discount?:int,brand?:string,min_rating?:float} $filters
 * @return list<array<string,mixed>>
 */
/**
 * Map a UI sort key (or HOME_PRODUCT_ORDER) to a PostgREST order clause.
 */
function product_order_clause(string $sort): string
{
    return match ($sort) {
        'price_asc'     => 'price.asc',
        'price_desc'    => 'price.desc',
        'discount_desc' => 'discount_percent.desc',
        'rating_desc'   => 'rating.desc',
        'deal_score'    => 'discount_percent.desc', // fallback; real score computed in PHP
        default         => 'created_at.desc',
    };
}

/**
 * Normalise a value to 0..1 with caps.
 */
function deal_score_norm(float $value, float $min, float $max): float
{
    if ($max <= $min) {
        return 0.0;
    }
    $v = ($value - $min) / ($max - $min);
    return max(0.0, min(1.0, $v));
}

/**
 * Compute Smart Deal Score for a product (0..1, higher = better deal).
 *
 * Components (weights from config, sum to 1.0):
 *  - discount_pct_norm: discount% capped at 90 → 0..1
 *  - rating_norm: rating 0..5 → 0..1
 *  - click_heat_norm: clicks in last N days / max clicks in window → 0..1
 *  - drop_bonus: 1 if recent price drop > 5%, else 0
 *  - platform_trust: Myntra/Ajio=1, Shopsy=0.7, DigiHaat=0.6, Flipkart=0.5, Amazon=0.5, other=0.3
 *
 * @param array<string,mixed> $product  Product row (needs discount_percent, rating, clicks, source_platform, price_history optional)
 * @return float  Score 0..1
 */
function compute_deal_score(array $product): float
{
    $discount = max(0, min(90, (int) ($product['discount_percent'] ?? 0)));
    $rating   = max(0.0, min(5.0, (float) ($product['rating'] ?? 0)));
    $clicks   = max(0, (int) ($product['clicks'] ?? 0));
    $platform = strtolower(trim((string) ($product['source_platform'] ?? '')));
    $price    = (float) ($product['price'] ?? 0);

    // Normalised components
    $discountNorm = deal_score_norm($discount, 0, 90);
    $ratingNorm   = deal_score_norm($rating, 0, 5);

    // Click heat: need window max. We approximate with a capped log scale
    // so a few clicks matter but huge numbers don't dominate.
    $clickWindow = defined('DEAL_SCORE_CLICK_WINDOW_DAYS') ? DEAL_SCORE_CLICK_WINDOW_DAYS : 7;
    // Since we don't have per-window clicks in the row, use total clicks with log.
    // 0 clicks → 0, 10 clicks → ~0.5, 100 clicks → ~0.8, 1000+ → ~1.0
    $clickHeatNorm = $clicks > 0 ? min(1.0, log($clicks + 1) / log(101)) : 0.0;

    // Price drop bonus: 1 if we have price_history and latest drop > 5%
    $dropBonus = 0.0;
    if (function_exists('price_history_available') && price_history_available() && function_exists('fetch_price_history')) {
        $history = fetch_price_history((string) ($product['slug'] ?? ''), 2);
        if (count($history) >= 2) {
            $current = $history[0]['price'] ?? 0;
            $previous = $history[1]['price'] ?? 0;
            if ($previous > 0 && $current > 0 && $current < $previous * 0.95) {
                $dropBonus = 1.0;
            }
        }
    }

    // Platform trust score
    $platformTrust = match ($platform) {
        'myntra', 'ajio' => 1.0,
        'shopsy'         => 0.7,
        'digihaat'       => 0.6,
        'flipkart'       => 0.5,
        'amazon'         => 0.5,
        default          => 0.3,
    };

    // Weights from config (already validated to sum ~1.0)
    $wDiscount = defined('DEAL_SCORE_W_DISCOUNT') ? DEAL_SCORE_W_DISCOUNT : 0.40;
    $wRating   = defined('DEAL_SCORE_W_RATING')   ? DEAL_SCORE_W_RATING   : 0.20;
    $wClicks   = defined('DEAL_SCORE_W_CLICKS')   ? DEAL_SCORE_W_CLICKS   : 0.20;
    $wDrop     = defined('DEAL_SCORE_W_DROP')     ? DEAL_SCORE_W_DROP     : 0.15;
    $wPlatform = defined('DEAL_SCORE_W_PLATFORM') ? DEAL_SCORE_W_PLATFORM : 0.05;

    $score = $wDiscount * $discountNorm
           + $wRating   * $ratingNorm
           + $wClicks   * $clickHeatNorm
           + $wDrop     * $dropBonus
           + $wPlatform * $platformTrust;

    return max(0.0, min(1.0, $score));
}

function fetch_storefront_products(array $filters = [], int $limit = PRODUCTS_PER_PAGE): array
{
    if (!supabase_is_configured()) {
        return [];
    }

    $limit = max(1, min(60, $limit));
    $offset = max(0, (int) ($filters['offset'] ?? 0));

    $sort = (string) ($filters['sort'] ?? '');
    if ($sort === '') {
        $sort = defined('HOME_PRODUCT_ORDER') ? (string) HOME_PRODUCT_ORDER : 'newest';
    }
    $order = product_order_clause($sort);

    $query = 'select=*&is_active=eq.true&order=' . $order . '&limit=' . $limit . '&offset=' . $offset;

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '' && in_array($category, PRODUCT_CATEGORIES, true)) {
        $query .= '&category=eq.' . rawurlencode($category);
    }
    $platform = trim((string) ($filters['platform'] ?? ''));
    if ($platform !== '') {
        $query .= '&source_platform=eq.' . rawurlencode($platform);
    }
    $search = clean_search_term((string) ($filters['q'] ?? ''));
    $searchTerms = [];
    // Expand the main search term with fuzzy variations (plurals, synonyms, hinglish)
    if ($search !== '') {
        foreach (expand_search_terms($search) as $expanded) {
            $searchTerms[$expanded] = true;
            if (count($searchTerms) >= 12) {
                break;
            }
        }
    }
    // Also include any AI-expanded terms (from ai_expand_query)
    foreach ((array) ($filters['q_terms'] ?? []) as $term) {
        $term = clean_search_term((string) $term);
        if ($term === '') {
            continue;
        }
        $searchTerms[$term] = true;
        if (count($searchTerms) >= 12) {
            break;
        }
    }
    $searchTerms = array_keys($searchTerms);
    if ($searchTerms) {
        $ors = [];
        foreach ($searchTerms as $term) {
            $pattern = rawurlencode('*' . $term . '*');
            // Search product identity only. Platform/category matching made
            // short words ("sho") match every Shopsy row.
            $ors[] = 'title.ilike.' . $pattern;
            $ors[] = 'brand.ilike.' . $pattern;
            $ors[] = 'category.ilike.' . $pattern;
        }
        $query .= '&or=(' . implode(',', $ors) . ')';
    }

    $minPrice = (float) ($filters['min_price'] ?? 0);
    if ($minPrice > 0) {
        $query .= '&price=gte.' . rawurlencode((string) $minPrice);
    }
    $maxPrice = (float) ($filters['max_price'] ?? 0);
    if ($maxPrice > 0) {
        $query .= '&price=lte.' . rawurlencode((string) $maxPrice);
    }
    $minDiscount = (int) ($filters['min_discount'] ?? 0);
    if ($minDiscount > 0) {
        $query .= '&discount_percent=gte.' . max(1, min(99, $minDiscount));
    }
    $brand = trim((string) ($filters['brand'] ?? ''));
    if ($brand !== '') {
        $query .= '&brand=eq.' . rawurlencode($brand);
    }
    $minRating = (float) ($filters['min_rating'] ?? 0);
    if ($minRating > 0) {
        $query .= '&rating=gte.' . rawurlencode((string) $minRating);
    }

    $result = supabase_request('GET', supabase_table() . '?' . $query . storefront_exclusion_query());
    if (!$result['ok']) {
        // Older PostgREST builds reject the grouped negation; fall back to the
        // unfiltered query and rely on the PHP-side blocklist below.
        $result = supabase_request('GET', supabase_table() . '?' . $query);
    }
    if (!$result['ok']) {
        // Installations without the brand/rating columns yet: drop those
        // filters so the storefront keeps working until database.sql is run.
        $fallback = 'select=*&is_active=eq.true&order='
            . ($order === 'rating.desc' ? 'created_at.desc' : $order)
            . '&limit=' . $limit . '&offset=' . $offset;
        if ($category !== '' && in_array($category, PRODUCT_CATEGORIES, true)) {
            $fallback .= '&category=eq.' . rawurlencode($category);
        }
        if ($platform !== '') {
            $fallback .= '&source_platform=eq.' . rawurlencode($platform);
        }
        if ($minPrice > 0) {
            $fallback .= '&price=gte.' . rawurlencode((string) $minPrice);
        }
        if ($maxPrice > 0) {
            $fallback .= '&price=lte.' . rawurlencode((string) $maxPrice);
        }
        if ($minDiscount > 0) {
            $fallback .= '&discount_percent=gte.' . max(1, min(99, $minDiscount));
        }
        if ($searchTerms) {
            $fallbackOrs = [];
            foreach ($searchTerms as $term) {
                $fallbackPattern = rawurlencode('*' . $term . '*');
                $fallbackOrs[] = 'title.ilike.' . $fallbackPattern;
                $fallbackOrs[] = 'category.ilike.' . $fallbackPattern;
                $fallbackOrs[] = 'source_platform.ilike.' . $fallbackPattern;
            }
            $fallback .= '&or=(' . implode(',', $fallbackOrs) . ')';
        }
        $result = supabase_request('GET', supabase_table() . '?' . $fallback);
    }
    if (!$result['ok']) {
        app_log('error', 'Storefront product fetch failed', [
            'status' => $result['status'],
            'error'  => $result['error'],
        ]);
        return [];
    }
    $rows = is_array($result['data']) ? array_values(array_filter($result['data'], 'is_array')) : [];
    $rows = array_values(array_filter($rows, static function (array $product): bool {
        return !is_blocked_product($product);
    }));

    // Drop exact duplicate listings (same title + store) created by repeated
    // feed upserts with slightly different URLs.
    $deduped = [];
    $seenKeys = [];
    foreach ($rows as $product) {
        $key = strtolower(trim((string) ($product['title'] ?? '')))
            . '|' . strtolower(trim((string) ($product['source_platform'] ?? '')));
        if ($key !== '|') {
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;
        }
        $deduped[] = $product;
    }
    return $deduped;
}

/**
 * Interleave products so the first screen never shows one category only.
 * Order inside each category is preserved; categories take turns.
 *
 * @param list<array<string,mixed>> $products
 * @return list<array<string,mixed>>
 */
function mix_products_by_category(array $products, int $limit = PRODUCTS_PER_PAGE): array
{
    $limit = max(1, $limit);
    $buckets = [];
    $order = [];
    $seen = [];
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $id = (string) ($product['slug'] ?? $product['id'] ?? '');
        if ($id !== '' && isset($seen[$id])) {
            continue;
        }
        if ($id !== '') {
            $seen[$id] = true;
        }
        $category = trim((string) ($product['category'] ?? ''));
        if ($category === '') {
            $category = 'Other';
        }
        if (!isset($buckets[$category])) {
            $buckets[$category] = [];
            $order[] = $category;
        }
        $buckets[$category][] = $product;
    }

    $positions = array_fill_keys($order, 0);
    $mixed = [];
    while (count($mixed) < $limit) {
        $added = false;
        foreach ($order as $category) {
            $index = $positions[$category];
            if ($index >= count($buckets[$category])) {
                continue;
            }
            $mixed[] = $buckets[$category][$index];
            $positions[$category] = $index + 1;
            $added = true;
            if (count($mixed) >= $limit) {
                break;
            }
        }
        if (!$added) {
            break;
        }
    }

    return $mixed;
}

/**
 * Brands and price range for the storefront filter panel.
 *
 * @return array{brands:list<string>,price_min:float,price_max:float,has_rating:bool}
 */
function fetch_filter_facets(int $limit = 800): array
{
    $empty = ['brands' => [], 'price_min' => 0.0, 'price_max' => 0.0, 'has_rating' => false];
    if (!supabase_is_configured()) {
        return $empty;
    }

    $query = 'select=brand,price,rating&is_active=eq.true&order=created_at.desc&limit=' . max(1, min(2000, $limit));
    $result = supabase_request('GET', supabase_table() . '?' . $query . storefront_exclusion_query());
    if (!$result['ok']) {
        $result = supabase_request('GET', supabase_table() . '?' . $query);
    }
    if (!$result['ok'] || !is_array($result['data'])) {
        return $empty;
    }

    $brands = [];
    $priceMin = 0.0;
    $priceMax = 0.0;
    $hasRating = false;
    foreach ($result['data'] as $row) {
        if (!is_array($row) || is_blocked_product($row)) {
            continue;
        }
        $brand = trim((string) ($row['brand'] ?? ''));
        if ($brand !== '') {
            $brands[$brand] = ($brands[$brand] ?? 0) + 1;
        }
        $price = (float) ($row['price'] ?? 0);
        if ($price > 0) {
            $priceMin = $priceMin > 0 ? min($priceMin, $price) : $price;
            $priceMax = max($priceMax, $price);
        }
        if ((float) ($row['rating'] ?? 0) >= 4) {
            $hasRating = true;
        }
    }
    arsort($brands);

    return [
        'brands'     => array_slice(array_keys($brands), 0, 40),
        'price_min'  => $priceMin,
        'price_max'  => $priceMax,
        'has_rating' => $hasRating,
    ];
}

/**
 * Trending products by click count (total clicks as proxy for popularity).
 * Later we can add a daily_clicks table for true time-window trending.
 *
 * @param int $limit
 * @param string $category Optional category filter
 * @return list<array<string,mixed>>
 */
function fetch_trending_products(int $limit = 10, string $category = ''): array
{
    if (!supabase_is_configured()) {
        return [];
    }
    $limit = max(1, min(24, $limit));

    $query = 'select=*&is_active=eq.true&clicks=gt.0&order=clicks.desc&limit=' . $limit;
    if ($category !== '' && in_array($category, PRODUCT_CATEGORIES, true)) {
        $query .= '&category=eq.' . rawurlencode($category);
    }

    $result = supabase_request('GET', supabase_table() . '?' . $query . storefront_exclusion_query());
    if (!$result['ok']) {
        $result = supabase_request('GET', supabase_table() . '?' . $query);
    }
    if (!$result['ok'] || !is_array($result['data'])) {
        return [];
    }
    $rows = is_array($result['data']) ? array_values(array_filter($result['data'], 'is_array')) : [];
    return array_values(array_filter($rows, static function (array $product): bool {
        return !is_blocked_product($product);
    }));
}

/** @return array<string,mixed>|null */
function find_product_by_slug(string $slug): ?array
{
    $slug = trim($slug);
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,140}$/', $slug)) {
        return null;
    }
    $query = 'select=*&slug=eq.' . rawurlencode($slug) . '&is_active=eq.true&limit=1';
    $result = supabase_request('GET', supabase_table() . '?' . $query);
    if (!$result['ok'] || !is_array($result['data']) || empty($result['data'][0]) || !is_array($result['data'][0])) {
        if (!$result['ok']) {
            app_log('error', 'Product lookup by slug failed', [
                'status' => $result['status'],
                'error'  => $result['error'],
            ]);
        }
        return null;
    }
    if (is_blocked_product($result['data'][0])) {
        return null;
    }
    return $result['data'][0];
}

/** @return array<string,mixed>|null */
function find_product_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $query = 'select=*&id=eq.' . $id . '&is_active=eq.true&limit=1';
    $result = supabase_request('GET', supabase_table() . '?' . $query);
    if (!$result['ok'] || !is_array($result['data']) || empty($result['data'][0]) || !is_array($result['data'][0])) {
        return null;
    }
    if (is_blocked_product($result['data'][0])) {
        return null;
    }
    return $result['data'][0];
}

/** @return list<array<string,mixed>> */
function fetch_related_products(string $category, int $excludeId, int $limit = 8): array
{
    if (!supabase_is_configured()) {
        return [];
    }

    $limit = max(4, min(12, $limit));
    $excludeId = max(0, $excludeId);
    $out = [];
    $seen = $excludeId > 0 ? [$excludeId => true] : [];

    $collect = static function (array $rows) use (&$out, &$seen, $limit): void {
        foreach ($rows as $row) {
            if (count($out) >= $limit) {
                return;
            }
            $rowId = (int) ($row['id'] ?? 0);
            if ($rowId < 1 || isset($seen[$rowId])) {
                continue;
            }
            $seen[$rowId] = true;
            $out[] = $row;
        }
    };

    // 1) Same category first.
    if ($category !== '' && in_array($category, PRODUCT_CATEGORIES, true)) {
        $collect(fetch_storefront_products(['category' => $category], $limit + 2));
    }

    // 2) Fill with the biggest live discounts from other categories.
    if (count($out) < $limit) {
        $collect(fetch_storefront_products(['sort' => 'discount_desc'], 24));
    }

    // 3) Last resort: newest products.
    if (count($out) < $limit) {
        $collect(fetch_storefront_products([], 24));
    }

    return array_slice($out, 0, $limit);
}

/* -------------------------------------------------------------------------
 * Price history (optional table: see database.sql)
 * ---------------------------------------------------------------------- */

function price_history_available(): bool
{
    if (array_key_exists('SAFA_PRICE_HISTORY_AVAILABLE', $GLOBALS)) {
        return (bool) $GLOBALS['SAFA_PRICE_HISTORY_AVAILABLE'];
    }
    $result = supabase_request('GET', 'price_history?select=slug&limit=1', null, ['service' => true, 'timeout' => 8]);
    $GLOBALS['SAFA_PRICE_HISTORY_AVAILABLE'] = $result['ok'];
    return (bool) $result['ok'];
}

function record_price_history(string $slug, float $price, float $mrp, int $productId = 0): bool
{
    $slug = strtolower(trim($slug));
    if ($slug === '' || $price <= 0) {
        return false;
    }
    $result = supabase_request('POST', 'price_history', [[
        'product_id'  => max(0, $productId),
        'slug'        => $slug,
        'price'       => $price,
        'mrp'         => max(0, $mrp),
        'recorded_at' => gmdate('c'),
    ]], ['service' => true, 'timeout' => 8, 'prefer' => 'return=minimal']);
    return (bool) $result['ok'];
}

/**
 * @return list<array{price:float,mrp:float,recorded_at:string}>
 */
function fetch_price_history(string $slug, int $limit = 30): array
{
    $slug = strtolower(trim($slug));
    if ($slug === '' || !price_history_available()) {
        return [];
    }
    $query = 'select=price,mrp,recorded_at&slug=eq.' . rawurlencode($slug)
        . '&order=recorded_at.desc&limit=' . max(2, min(120, $limit));
    $result = supabase_request('GET', 'price_history?' . $query, null, ['service' => true, 'timeout' => 10]);
    if (!$result['ok'] || !is_array($result['data'])) {
        return [];
    }
    return array_values(array_filter(array_map(static function ($row): array {
        return [
            'price'       => (float) ($row['price'] ?? 0),
            'mrp'         => (float) ($row['mrp'] ?? 0),
            'recorded_at' => (string) ($row['recorded_at'] ?? ''),
        ];
    }, $result['data']), static function (array $row): bool {
        return $row['price'] > 0;
    }));
}

/**
 * Latest price change per product, biggest drops first.
 *
 * @return list<array{product:array<string,mixed>,drop:float,drop_percent:int,previous:float,current:float,recorded_at:string}>
 */
function fetch_recent_price_drops(int $limit = 16): array
{
    if (!price_history_available()) {
        return [];
    }
    $query = 'select=slug,price,recorded_at&order=recorded_at.desc&limit=1500';
    $result = supabase_request('GET', 'price_history?' . $query, null, ['service' => true, 'timeout' => 20]);
    if (!$result['ok'] || !is_array($result['data'])) {
        return [];
    }

    $bySlug = [];
    foreach ($result['data'] as $row) {
        $slug = strtolower((string) ($row['slug'] ?? ''));
        if ($slug === '') {
            continue;
        }
        if (!isset($bySlug[$slug])) {
            $bySlug[$slug] = [];
        }
        if (count($bySlug[$slug]) >= 2) {
            continue;
        }
        $bySlug[$slug][] = [
            'price'       => (float) ($row['price'] ?? 0),
            'recorded_at' => (string) ($row['recorded_at'] ?? ''),
        ];
    }

    $drops = [];
    foreach ($bySlug as $slug => $entries) {
        if (count($entries) < 2) {
            continue;
        }
        $current = $entries[0]['price'];
        $previous = $entries[1]['price'];
        if ($current <= 0 || $previous <= 0 || $current >= $previous - 0.5) {
            continue;
        }
        $drop = $previous - $current;
        $drops[] = [
            'slug'         => $slug,
            'drop'         => $drop,
            'drop_percent' => (int) round(($drop / $previous) * 100),
            'previous'     => $previous,
            'current'      => $current,
            'recorded_at'  => $entries[0]['recorded_at'],
        ];
    }

    usort($drops, static function (array $a, array $b): int {
        return $b['drop_percent'] <=> $a['drop_percent'] ?: $b['drop'] <=> $a['drop'];
    });
    $drops = array_slice($drops, 0, max(1, min(40, $limit)));

    $products = fetch_products_by_slugs(array_column($drops, 'slug'));
    $productMap = [];
    foreach ($products as $product) {
        $productMap[strtolower((string) ($product['slug'] ?? ''))] = $product;
    }

    $out = [];
    foreach ($drops as $drop) {
        $product = $productMap[$drop['slug']] ?? null;
        if ($product === null) {
            continue;
        }
        $product['_price_drop'] = (float) $drop['drop'];
        $out[] = [
            'product'      => $product,
            'drop'         => (float) $drop['drop'],
            'drop_percent' => (int) $drop['drop_percent'],
            'previous'     => (float) $drop['previous'],
            'current'      => (float) $drop['current'],
            'recorded_at'  => (string) $drop['recorded_at'],
        ];
    }
    return $out;
}
