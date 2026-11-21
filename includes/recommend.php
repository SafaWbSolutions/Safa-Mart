<?php
/**
 * Behaviour-aware recommendations.
 *
 * The browser keeps an anonymous footprint (products viewed, searches typed,
 * interest clicks) in localStorage. The product page sends that footprint here
 * and this file scores the candidate pool:
 *
 *   intent keywords + same category + similar price + real discount + rating
 *
 * Results are diversified so the grid never becomes "same store, same category"
 * and are always filled, even with little or no history.
 */

/** Candidate products, cached for 10 minutes. @return list<array<string,mixed>> */
function recommendation_pool(): array
{
    $file = dirname(UPLOAD_DIR) . '/recommend-pool.json';
    if (is_file($file) && time() - (int) filemtime($file) < 600) {
        $cached = json_decode((string) @file_get_contents($file), true);
        if (is_array($cached) && $cached) {
            return $cached;
        }
    }

    if (!supabase_is_configured()) {
        return [];
    }

    $keep = ['id', 'slug', 'title', 'price', 'mrp', 'discount_percent', 'category', 'source_platform', 'brand', 'rating', 'rating_count', 'thumbnail_url'];
    $map = [];
    foreach ([['sort' => 'discount_desc'], []] as $filters) {
        foreach (fetch_storefront_products($filters, 120) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || isset($map[$id])) {
                continue;
            }
            $trimmed = [];
            foreach ($keep as $key) {
                $trimmed[$key] = $row[$key] ?? null;
            }
            $map[$id] = $trimmed;
        }
    }

    $rows = array_values($map);
    $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        @file_put_contents($file, $json, LOCK_EX);
    }
    return $rows;
}

/** @return list<string> */
function recommendation_tokens(string $text): array
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
    $stop = [
        'the', 'and', 'for', 'with', 'from', 'this', 'that', 'buy', 'online',
        'best', 'price', 'prices', 'india', 'new', 'pack', 'set', 'combo',
        'men', 'women', 'kids', 'boys', 'girls', 'apparel', 'free', 'shipping',
        'offer', 'offers', 'sale', 'shop', 'store',
    ];
    $tokens = [];
    foreach (explode(' ', $text) as $word) {
        if (strlen($word) < 3 || in_array($word, $stop, true)) {
            continue;
        }
        $tokens[$word] = true;
        if (count($tokens) >= 14) {
            break;
        }
    }
    return array_keys($tokens);
}

/**
 * @param array<string,mixed> $context
 * @return list<array<string,mixed>>
 */
function recommend_products(array $context, int $limit = 8): array
{
    $pool = recommendation_pool();
    if (!$pool) {
        return [];
    }

    $limit = max(4, min(12, $limit));
    $current = is_array($context['current'] ?? null) ? $context['current'] : [];
    $views = array_slice(array_values(array_filter(is_array($context['views'] ?? null) ? $context['views'] : [], 'is_array')), 0, 10);
    $queries = array_slice(array_values(array_filter(is_array($context['queries'] ?? null) ? $context['queries'] : [], 'is_string')), 0, 6);

    $exclude = [];
    foreach ((array) ($context['exclude'] ?? []) as $excludeId) {
        $exclude[(int) $excludeId] = true;
    }
    $currentId = (int) ($current['id'] ?? 0);
    if ($currentId > 0) {
        $exclude[$currentId] = true;
    }

    $intent = [];
    foreach ($queries as $query) {
        foreach (recommendation_tokens($query) as $token) {
            $intent[$token] = ($intent[$token] ?? 0) + 3;
        }
    }
    foreach ($views as $view) {
        foreach (recommendation_tokens((string) ($view['title'] ?? '')) as $token) {
            $intent[$token] = ($intent[$token] ?? 0) + 2;
        }
    }
    foreach (recommendation_tokens((string) ($current['title'] ?? '')) as $token) {
        $intent[$token] = ($intent[$token] ?? 0) + 4;
    }

    $currentCategory = strtolower(trim((string) ($current['category'] ?? '')));
    $currentPlatform = strtolower(trim((string) ($current['platform'] ?? '')));
    $currentPrice = (float) ($current['price'] ?? 0);

    $intentCategories = [];
    if ($currentCategory !== '') {
        $intentCategories[$currentCategory] = 3;
    }
    foreach ($views as $view) {
        $viewCategory = strtolower(trim((string) ($view['category'] ?? '')));
        if ($viewCategory !== '') {
            $intentCategories[$viewCategory] = ($intentCategories[$viewCategory] ?? 0) + 1;
        }
    }

    $viewedIds = [];
    $viewedPrices = [];
    foreach ($views as $view) {
        $viewedIds[(int) ($view['id'] ?? 0)] = true;
        $viewedPrice = (float) ($view['price'] ?? 0);
        if ($viewedPrice > 0) {
            $viewedPrices[] = $viewedPrice;
        }
    }
    $targetPrice = $currentPrice > 0
        ? $currentPrice
        : ($viewedPrices ? array_sum($viewedPrices) / count($viewedPrices) : 0);

    $scored = [];
    foreach ($pool as $product) {
        $id = (int) ($product['id'] ?? 0);
        if ($id < 1 || isset($exclude[$id])) {
            continue;
        }

        $score = 0.0;
        $productCategory = strtolower((string) ($product['category'] ?? ''));
        if ($currentCategory !== '' && $productCategory === $currentCategory) {
            $score += 45;
        }
        if (isset($intentCategories[$productCategory])) {
            $score += 12;
        }

        $shared = 0;
        foreach (recommendation_tokens((string) ($product['title'] ?? '')) as $token) {
            if (isset($intent[$token])) {
                $shared += min(12, (int) $intent[$token]);
            }
        }
        $score += min(42, $shared);

        $price = (float) ($product['price'] ?? 0);
        if ($targetPrice > 0 && $price > 0) {
            $diff = abs($price - $targetPrice) / max(1.0, $targetPrice);
            if ($diff <= 0.25) {
                $score += 20;
            } elseif ($diff <= 0.5) {
                $score += 10;
            } elseif ($diff > 1.5) {
                $score -= 6;
            }
        }

        if ($currentPlatform !== '' && strtolower((string) ($product['source_platform'] ?? '')) === $currentPlatform) {
            $score += 6;
        }

        $score += min(22, ((int) ($product['discount_percent'] ?? 0)) / 4);
        $score += min(12, ((float) ($product['rating'] ?? 0)) * 2.5);
        if (isset($viewedIds[$id])) {
            $score -= 20;
        }
        $score += mt_rand(0, 5);

        $scored[] = ['score' => $score, 'product' => $product];
    }

    usort($scored, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });

    // Diversify: at most 3 per category and 4 per store, then fill the rest.
    $out = [];
    $outIds = [];
    $categoryCount = [];
    $platformCount = [];
    foreach ($scored as $entry) {
        if (count($out) >= $limit) {
            break;
        }
        $product = $entry['product'];
        $category = strtolower((string) ($product['category'] ?? ''));
        $platform = strtolower((string) ($product['source_platform'] ?? ''));
        if (($categoryCount[$category] ?? 0) >= 5 || ($platformCount[$platform] ?? 0) >= 4) {
            continue;
        }
        $categoryCount[$category] = ($categoryCount[$category] ?? 0) + 1;
        $platformCount[$platform] = ($platformCount[$platform] ?? 0) + 1;
        $out[] = $product;
        $outIds[(int) ($product['id'] ?? 0)] = true;
    }
    foreach ($scored as $entry) {
        if (count($out) >= $limit) {
            break;
        }
        $product = $entry['product'];
        $id = (int) ($product['id'] ?? 0);
        if (isset($outIds[$id])) {
            continue;
        }
        $out[] = $product;
        $outIds[$id] = true;
    }

    return array_slice($out, 0, $limit);
}
