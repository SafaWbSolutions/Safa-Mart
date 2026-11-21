<?php
/**
 * Lightweight semantic search.
 *
 * The catalogue is small (a few hundred products), so paying for a network
 * round-trip or a pgvector query on every search would be slower than doing the
 * maths in PHP. Each product title is turned into a signed bag of words +
 * character trigrams, weighted with TF-IDF and stored as a sparse vector in
 * /storage. Search compares the query vector with the catalogue by cosine
 * similarity, which catches typos and word variants ("kurti" ↔ "kurta",
 * "sneeker" ↔ "sneakers") that a plain SQL ILIKE misses.
 *
 * If the catalogue grows past a few thousand products this can be swapped for
 * a pgvector query without touching the callers.
 */

function semantic_storage_path(): string
{
    $dir = SAFA_ROOT . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/semantic-index.json';
}

/** @return list<string> */
function semantic_stopwords(): array
{
    static $stop = null;
    if (is_array($stop)) {
        return $stop;
    }
    $stop = [
        'the', 'and', 'for', 'with', 'from', 'this', 'that', 'buy', 'online',
        'best', 'price', 'prices', 'india', 'new', 'offer', 'offers', 'sale',
        'shop', 'store', 'latest', 'trending', 'only', 'more', 'free',
        'shipping',
    ];
    return $stop;
}

/**
 * Convert free text into weighted features.
 *
 * @return array<string,float>
 */
function semantic_feature_weights(string $text): array
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
    $words = array_values(array_filter(explode(' ', $text), static function (string $word): bool {
        return $word !== '';
    }));
    $stop = semantic_stopwords();

    $features = [];
    $meaningful = [];
    foreach ($words as $word) {
        if (strlen($word) < 2 || in_array($word, $stop, true)) {
            continue;
        }
        $features['w:' . $word] = ($features['w:' . $word] ?? 0) + 1.0;
        if (strlen($word) >= 4) {
            $padded = '^' . $word . '$';
            $length = strlen($padded);
            for ($i = 0; $i + 3 <= $length; $i++) {
                $gram = substr($padded, $i, 3);
                $features['g:' . $gram] = ($features['g:' . $gram] ?? 0) + 0.35;
            }
        }
        $meaningful[] = $word;
    }

    for ($i = 0; $i + 1 < count($meaningful); $i++) {
        $features['p:' . $meaningful[$i] . '_' . $meaningful[$i + 1]]
            = ($features['p:' . $meaningful[$i] . '_' . $meaningful[$i + 1]] ?? 0) + 0.5;
    }

    return $features;
}

function semantic_dimension(string $feature): int
{
    return abs((int) crc32($feature)) % 2048;
}

/**
 * @param array<string,float> $features
 * @param array<string,float> $idf
 * @return array<int,float>
 */
function semantic_vectorize(array $features, array $idf, int $topK = 80): array
{
    $dims = [];
    foreach ($features as $feature => $tf) {
        $weight = (1.0 + log(1.0 + $tf)) * ($idf[$feature] ?? 1.0);
        $dim = semantic_dimension($feature);
        $dims[$dim] = ($dims[$dim] ?? 0.0) + $weight;
    }

    if (!$dims) {
        return [];
    }

    arsort($dims);
    $dims = array_slice($dims, 0, $topK, true);

    $norm = 0.0;
    foreach ($dims as $weight) {
        $norm += $weight * $weight;
    }
    $norm = sqrt($norm);
    if ($norm <= 0) {
        return [];
    }

    $vector = [];
    foreach ($dims as $dim => $weight) {
        $vector[$dim] = round($weight / $norm, 6);
    }
    return $vector;
}

/**
 * Build (or load) the catalogue vector index.
 *
 * @return array{signature:string,built:int,idf:array<string,float>,docs:array<int,array<int,float>>}
 */
function semantic_index(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $pool = recommendation_pool();
    $ids = [];
    foreach ($pool as $product) {
        $ids[] = (int) ($product['id'] ?? 0);
    }
    sort($ids);
    $signature = md5(implode(',', $ids));

    $file = semantic_storage_path();
    if (is_file($file) && time() - (int) filemtime($file) < 7200) {
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (is_array($decoded) && ($decoded['signature'] ?? '') === $signature
            && isset($decoded['docs'], $decoded['idf']) && is_array($decoded['docs'])) {
            $cached = $decoded;
            return $cached;
        }
    }

    $docFeatures = [];
    $df = [];
    foreach ($pool as $product) {
        $id = (int) ($product['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $text = implode(' ', [
            (string) ($product['title'] ?? ''),
            (string) ($product['category'] ?? ''),
            (string) ($product['brand'] ?? ''),
            (string) ($product['source_platform'] ?? ''),
        ]);
        $features = semantic_feature_weights($text);
        if (!$features) {
            continue;
        }
        $docFeatures[$id] = $features;
        foreach (array_keys($features) as $feature) {
            $df[$feature] = ($df[$feature] ?? 0) + 1;
        }
    }

    $total = max(1, count($docFeatures));
    $idf = [];
    foreach ($df as $feature => $count) {
        $idf[$feature] = log(($total + 1) / ($count + 1)) + 1.0;
    }

    $docs = [];
    foreach ($docFeatures as $id => $features) {
        $vector = semantic_vectorize($features, $idf);
        if ($vector) {
            $docs[$id] = $vector;
        }
    }

    $index = [
        'signature' => $signature,
        'built'     => time(),
        'idf'       => $idf,
        'docs'      => $docs,
    ];

    $json = json_encode($index, JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @chmod($tmp, 0644);
            @rename($tmp, $file);
        }
    }

    $cached = $index;
    return $cached;
}

/**
 * Meaningful tokens of a query (used for the fuzzy head-noun boost).
 *
 * @return list<string>
 */
function semantic_query_tokens(string $query): array
{
    $query = strtolower($query);
    $query = preg_replace('/[^a-z0-9]+/', ' ', $query) ?? '';
    $stop = semantic_stopwords();
    $tokens = [];
    foreach (explode(' ', $query) as $word) {
        if (strlen($word) < 3 || in_array($word, $stop, true)) {
            continue;
        }
        $tokens[$word] = true;
    }
    return array_slice(array_keys($tokens), 0, 10);
}

/**
 * Rank catalogue products against a free-text query.
 *
 * Cosine similarity handles the bulk of the meaning; a small fuzzy token
 * bonus makes sure the last word of the query (usually the product type:
 * "formal shooes" → shoes) wins over a generic first word.
 *
 * @param list<int> $excludeIds
 * @return list<array<string,mixed>> Product rows ready for render_product_card().
 */
function semantic_search_products(string $query, int $limit = 12, array $excludeIds = []): array
{
    $query = clean_search_term($query);
    if ($query === '') {
        return [];
    }
    $limit = max(1, min(24, $limit));

    $pool = recommendation_pool();
    $index = semantic_index();
    if (!$pool || !$index['docs']) {
        return [];
    }

    $queryFeatures = semantic_feature_weights($query);
    if (!$queryFeatures) {
        return [];
    }
    $queryVector = semantic_vectorize($queryFeatures, $index['idf'], 48);
    if (!$queryVector) {
        return [];
    }

    $queryTokens = semantic_query_tokens($query);
    $lastToken = $queryTokens ? (string) end($queryTokens) : '';

    $exclude = [];
    foreach ($excludeIds as $id) {
        $exclude[(int) $id] = true;
    }

    $byId = [];
    foreach ($pool as $product) {
        $byId[(int) ($product['id'] ?? 0)] = $product;
    }

    $scores = [];
    foreach ($index['docs'] as $id => $vector) {
        $id = (int) $id;
        if (isset($exclude[$id])) {
            continue;
        }

        $score = 0.0;
        foreach ($vector as $dim => $weight) {
            if (isset($queryVector[$dim])) {
                $score += $weight * $queryVector[$dim];
            }
        }
        if ($score < 0.03) {
            continue;
        }

        // Fuzzy token boost (tolerates typos and doubled vowels).
        if ($queryTokens) {
            $titleTokens = semantic_query_tokens((string) ($byId[$id]['title'] ?? ''));
            $bonus = 0.0;
            foreach ($titleTokens as $titleToken) {
                foreach ($queryTokens as $queryToken) {
                    $weight = $queryToken === $lastToken ? 2.0 : 1.0;
                    if ($titleToken === $queryToken) {
                        $bonus += 0.18 * $weight;
                    } elseif (strlen($queryToken) >= 5 && levenshtein($titleToken, $queryToken) <= 1) {
                        $bonus += 0.22 * $weight;
                    } elseif (strlen($queryToken) >= 6 && levenshtein($titleToken, $queryToken) <= 2) {
                        $bonus += 0.11 * $weight;
                    }
                }
            }
            $score += min(0.9, $bonus);
        }

        $scores[$id] = $score;
    }

    if (!$scores) {
        return [];
    }
    arsort($scores);

    // Absolute cut: if the best match is still weak, the catalogue probably
    // has nothing for this query ("saree", "headfone"). Returning nothing is
    // better than filling the grid with unrelated shirts.
    $best = (float) reset($scores);
    if ($best < 0.24) {
        return [];
    }

    // Keep only close contenders: a 0.49 sneaker hit should not be followed by
    // shirts at 0.12.
    $floor = max(0.09, $best * 0.52);

    $results = [];
    $seenTitles = [];
    foreach ($scores as $id => $score) {
        if (count($results) >= $limit || $score < $floor) {
            break;
        }
        if (!isset($byId[$id])) {
            continue;
        }
        $titleKey = strtolower(preg_replace('/[^a-z0-9]+/', '', (string) ($byId[$id]['title'] ?? '')) ?? '');
        $titleKey = substr($titleKey, 0, 70);
        if ($titleKey !== '' && isset($seenTitles[$titleKey])) {
            continue;
        }
        $seenTitles[$titleKey] = true;
        $results[] = $byId[$id];
    }

    return $results;
}
