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
 * @param array{q?:string,category?:string,platform?:string,offset?:int} $filters
 * @return list<array<string,mixed>>
 */
function fetch_storefront_products(array $filters = [], int $limit = PRODUCTS_PER_PAGE): array
{
    if (!supabase_is_configured()) {
        return [];
    }

    $limit = max(1, min(60, $limit));
    $offset = max(0, (int) ($filters['offset'] ?? 0));
    $query = 'select=*&is_active=eq.true&order=created_at.desc&limit=' . $limit . '&offset=' . $offset;

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '' && in_array($category, PRODUCT_CATEGORIES, true)) {
        $query .= '&category=eq.' . rawurlencode($category);
    }
    $platform = trim((string) ($filters['platform'] ?? ''));
    if ($platform !== '') {
        $query .= '&source_platform=eq.' . rawurlencode($platform);
    }
    $search = clean_search_term((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $pattern = rawurlencode('*' . $search . '*');
        $query .= '&or=(title.ilike.' . $pattern
            . ',category.ilike.' . $pattern
            . ',source_platform.ilike.' . $pattern . ')';
    }

    $result = supabase_request('GET', supabase_table() . '?' . $query . storefront_exclusion_query());
    if (!$result['ok']) {
        // Older PostgREST builds reject the grouped negation; fall back to the
        // unfiltered query and rely on the PHP-side blocklist below.
        $result = supabase_request('GET', supabase_table() . '?' . $query);
    }
    if (!$result['ok']) {
        app_log('error', 'Storefront product fetch failed', [
            'status' => $result['status'],
            'error'  => $result['error'],
        ]);
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
    $limit = max(1, min(12, $limit));
    $query = 'select=*&is_active=eq.true&id=neq.' . max(0, $excludeId)
        . '&order=clicks.desc,created_at.desc&limit=' . $limit;
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
    $rows = array_values(array_filter($result['data'], 'is_array'));
    return array_values(array_filter($rows, static function (array $product): bool {
        return !is_blocked_product($product);
    }));
}
