<?php
/**
 * Optional AI features through OpenRouter (free models).
 *
 * Used only for semantic search expansion. Recommendations themselves stay
 * deterministic and fast; AI simply improves recall for queries like
 * "salwar suit" when the catalogue uses different words ("kurta", "palazzo").
 * Every call falls back silently when the API key or network is missing.
 */

function ai_cache_dir(): string
{
    $dir = dirname(UPLOAD_DIR) . '/ai-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/** @return mixed|null */
function ai_cache_read(string $key, int $ttl)
{
    $file = ai_cache_dir() . '/' . $key . '.json';
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['ts'])) {
        return null;
    }
    if (time() - (int) $data['ts'] > $ttl) {
        return null;
    }
    return $data;
}

/** @param array<string,mixed> $data */
function ai_cache_write(string $key, array $data): void
{
    $data['ts'] = time();
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    $file = ai_cache_dir() . '/' . $key . '.json';
    @file_put_contents($file, $json, LOCK_EX);
}

function ai_is_configured(): bool
{
    return OPENROUTER_API_KEY !== '' && OPENROUTER_MODEL !== '';
}

/**
 * Ask OpenRouter for a small JSON answer.
 *
 * @return array<string,mixed>|null
 */
function ai_chat_json(string $system, string $user, int $timeout = 15): ?array
{
    if (!ai_is_configured()) {
        return null;
    }

    $response = http_request('https://openrouter.ai/api/v1/chat/completions', [
        'method'  => 'POST',
        'timeout' => max(6, min(25, $timeout)),
        'headers' => [
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: ' . rtrim(site_url(), '/'),
            'X-Title: ' . APP_NAME,
        ],
        'json'    => [
            'model'       => OPENROUTER_MODEL,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0,
            'max_tokens'  => 200,
        ],
    ]);

    if (!$response['ok'] || (int) $response['status'] < 200 || (int) $response['status'] >= 300) {
        app_log('info', 'OpenRouter expansion failed', [
            'status' => (int) ($response['status'] ?? 0),
            'error'  => (string) ($response['error'] ?? ''),
        ]);
        return null;
    }

    $body = json_decode((string) $response['body'], true);
    $content = (string) ($body['choices'][0]['message']['content'] ?? '');
    if ($content === '') {
        return null;
    }

    $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content) ?? $content);
    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Cheap, offline synonyms so search works even without an AI key.
 *
 * @return list<string>
 */
function builtin_query_expansion(string $query): array
{
    $map = [
        'salwar'   => ['kurta', 'palazzo', 'dupatta'],
        'suit'     => ['kurta', 'blazer', 'co-ord'],
        'kurti'    => ['kurta', 'palazzo'],
        'kurta'    => ['kurti', 'palazzo'],
        'saree'    => ['sari', 'blouse'],
        'sari'     => ['saree'],
        'lehenga'  => ['ethnic', 'dupatta'],
        'earbud'   => ['earbuds', 'buds', 'headphones'],
        'earbuds'  => ['buds', 'headphones'],
        'headphone' => ['headphones', 'earbuds'],
        'sneaker'  => ['sneakers', 'shoes'],
        'shoe'     => ['shoes', 'sneakers', 'sandals'],
        'shoes'    => ['sneakers', 'sandals', 'footwear'],
        'shirt'    => ['shirts', 't-shirt'],
        'tshirt'   => ['t-shirt', 'tee'],
        'jeans'    => ['denim', 'trousers'],
        'dress'    => ['dresses', 'gown'],
        'top'      => ['tops', 'blouse'],
        'watch'    => ['watches', 'smartwatch'],
        'bag'      => ['bags', 'handbag', 'backpack'],
        'mobile'   => ['smartphone', 'phone'],
        'smartwatch' => ['watch', 'fitness band'],
    ];

    $lower = strtolower($query);
    $terms = [$query];
    foreach (preg_split('/[^a-z0-9]+/', $lower) ?: [] as $token) {
        if ($token === '') {
            continue;
        }
        $candidates = $map[$token] ?? [];
        if (!$candidates && strlen($token) > 4 && str_ends_with($token, 's')) {
            $singular = substr($token, 0, -1);
            $candidates = array_merge([$singular], $map[$singular] ?? []);
        }
        foreach ($candidates as $candidate) {
            if (!in_array($candidate, $terms, true)) {
                $terms[] = $candidate;
            }
        }
        if (count($terms) >= 6) {
            break;
        }
    }

    return array_slice($terms, 0, 6);
}

/**
 * Expand a search query into related product terms. Always includes the
 * original query so behaviour never gets worse than a plain search.
 *
 * @return list<string>
 */
function ai_expand_query(string $query): array
{
    $query = clean_search_term($query);
    if ($query === '') {
        return [];
    }
    $fallback = builtin_query_expansion($query);
    if (!AI_QUERY_EXPANSION || !ai_is_configured()) {
        return $fallback;
    }

    $cacheKey = 'query-' . md5(strtolower($query));
    $cached = ai_cache_read($cacheKey, 2592000);
    if (is_array($cached) && array_key_exists('terms', $cached)) {
        $cachedTerms = array_values(array_filter(array_map('strval', (array) $cached['terms'])));
        // A failed expansion is cached for one hour only.
        $isFreshNegative = !$cachedTerms && (time() - (int) ($cached['ts'] ?? 0)) <= 3600;
        if ($cachedTerms || $isFreshNegative) {
            return $cachedTerms ?: $fallback;
        }
    }

    $data = ai_chat_json(
        'You expand shopping search queries for an Indian deal site. Reply with ONLY JSON: {"terms":["..."]}. '
        . 'Give 3-6 short lowercase keywords: synonyms and related product types. No brand names, no sentences.',
        $query,
        10
    );

    $terms = [];
    if (is_array($data['terms'] ?? null)) {
        foreach ($data['terms'] as $term) {
            $term = strtolower(trim((string) $term));
            $term = preg_replace('/[^a-z0-9\s\-]+/', ' ', $term) ?? '';
            $term = trim(preg_replace('/\s+/', ' ', $term) ?? '');
            $length = function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
            if ($length < 2 || $length > 30 || in_array($term, $terms, true)) {
                continue;
            }
            $terms[] = $term;
            if (count($terms) >= 6) {
                break;
            }
        }
    }

    if (!$terms) {
        // Cache the failure briefly so a broken key/model does not slow every search.
        ai_cache_write($cacheKey, ['terms' => []]);
        return $fallback;
    }

    $lowerQuery = strtolower($query);
    if (!in_array($lowerQuery, $terms, true)) {
        array_unshift($terms, $lowerQuery);
    }
    $terms = array_values(array_unique(array_merge($fallback, $terms)));
    $terms = array_slice($terms, 0, 7);
    ai_cache_write($cacheKey, ['terms' => $terms]);

    return $terms;
}
