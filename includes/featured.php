<?php
/**
 * Curated highlights ("Spotlight") and the best-offer rail.
 *
 * Highlights are stored in uploads/featured.json so the storefront can be
 * curated without a database migration. The Telegram bot controls the same
 * file with /highlight, /unhighlight and /highlights. Each entry can carry a
 * custom badge (for example "Brand Deal") and an expiry date, so a paid or
 * banner deal can be pushed for a few days and then falls away on its own.
 */

/** Absolute path of the highlight store. */
function featured_store_path(): string
{
    return dirname(UPLOAD_DIR) . '/featured.json';
}

function featured_max_items(): int
{
    return max(1, min(24, (int) safa_config_value('FEATURED_MAX_ITEMS', 12)));
}

function featured_now(): DateTimeImmutable
{
    $zone = APP_TIMEZONE !== '' ? APP_TIMEZONE : 'Asia/Kolkata';
    try {
        return new DateTimeImmutable('now', new DateTimeZone($zone));
    } catch (Throwable $error) {
        return new DateTimeImmutable('now');
    }
}

/**
 * Active highlights, newest priority first. Expired entries are dropped.
 *
 * @return list<array{slug:string,id:int,badge:string,priority:int,until:string,added_at:string}>
 */
function featured_items(): array
{
    if (isset($GLOBALS['SAFA_FEATURED_ITEMS']) && is_array($GLOBALS['SAFA_FEATURED_ITEMS'])) {
        return $GLOBALS['SAFA_FEATURED_ITEMS'];
    }

    $file = featured_store_path();
    if (!is_file($file)) {
        return $GLOBALS['SAFA_FEATURED_ITEMS'] = [];
    }
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return $GLOBALS['SAFA_FEATURED_ITEMS'] = [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !is_array($data['items'] ?? null)) {
        return $GLOBALS['SAFA_FEATURED_ITEMS'] = [];
    }

    $now = time();
    $items = [];
    foreach ($data['items'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $slug = strtolower(trim((string) ($item['slug'] ?? '')));
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9\-_.]{0,190}$/', $slug)) {
            continue;
        }
        $until = trim((string) ($item['until'] ?? ''));
        if ($until !== '') {
            $timestamp = strtotime($until);
            if ($timestamp === false || $timestamp < $now) {
                continue;
            }
        }
        $items[$slug] = [
            'slug'     => $slug,
            'id'       => (int) ($item['id'] ?? 0),
            'badge'    => truncate_text(clean_text((string) ($item['badge'] ?? '')), 28),
            'priority' => (int) ($item['priority'] ?? 0),
            'until'    => $until,
            'added_at' => (string) ($item['added_at'] ?? ''),
        ];
    }

    $items = array_values($items);
    usort($items, static function (array $a, array $b): int {
        if ($a['priority'] !== $b['priority']) {
            return $b['priority'] <=> $a['priority'];
        }
        return strcmp($a['added_at'], $b['added_at']);
    });

    return $GLOBALS['SAFA_FEATURED_ITEMS'] = $items;
}

/**
 * Persist the highlight list.
 *
 * @param list<array<string,mixed>> $items
 */
function featured_save(array $items): bool
{
    $file = featured_store_path();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $payload = [
        'updated_at' => gmdate('c'),
        'items'      => array_slice(array_values($items), 0, featured_max_items()),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    unset($GLOBALS['SAFA_FEATURED_ITEMS']);
    return true;
}

/**
 * @return array{ok:bool,error?:string,entry?:array<string,mixed>}
 */
function featured_add(string $slug, int $id = 0, int $days = 7, string $badge = ''): array
{
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return ['ok' => false, 'error' => 'Slug is empty.'];
    }

    $until = '';
    if ($days > 0) {
        $until = featured_now()
            ->modify('+' . max(1, min(365, $days)) . ' days')
            ->setTime(23, 59, 59)
            ->format('c');
    }

    $items = featured_items();
    $entry = [
        'slug'     => $slug,
        'id'       => max(0, $id),
        'badge'    => truncate_text(clean_text($badge), 28),
        'priority' => 0,
        'until'    => $until,
        'added_at' => gmdate('c'),
    ];

    $replaced = false;
    foreach ($items as $index => $item) {
        if ($item['slug'] === $slug) {
            $entry['added_at'] = $item['added_at'] !== '' ? $item['added_at'] : gmdate('c');
            $entry['priority'] = (int) $item['priority'];
            $items[$index] = $entry;
            $replaced = true;
            break;
        }
    }
    if (!$replaced) {
        $items[] = $entry;
    }

    if (!featured_save($items)) {
        return ['ok' => false, 'error' => 'Could not write uploads/featured.json. Check folder permissions.'];
    }
    return ['ok' => true, 'entry' => $entry];
}

/**
 * @return array{ok:bool,error?:string,removed?:int}
 */
function featured_remove(string $slugOrId): array
{
    $needle = strtolower(trim($slugOrId));
    if ($needle === '') {
        return ['ok' => false, 'error' => 'Give a product slug or ID.'];
    }

    $items = featured_items();
    $kept = [];
    $removed = 0;
    foreach ($items as $item) {
        $matches = $item['slug'] === $needle || ($item['id'] > 0 && (string) $item['id'] === $needle);
        if ($matches) {
            $removed++;
            continue;
        }
        $kept[] = $item;
    }

    if ($removed === 0) {
        return ['ok' => false, 'error' => 'That product is not highlighted.'];
    }
    if (!featured_save($kept)) {
        return ['ok' => false, 'error' => 'Could not write uploads/featured.json.'];
    }
    return ['ok' => true, 'removed' => $removed];
}

/** Active badge per product slug, used on normal product cards. */
function featured_badges(): array
{
    $badges = [];
    foreach (featured_items() as $item) {
        $badges[$item['slug']] = $item['badge'] !== '' ? $item['badge'] : 'Featured';
    }
    return $badges;
}

/**
 * Fetch active products for the given slugs, preserving the given order.
 *
 * @param list<string> $slugs
 * @return list<array<string,mixed>>
 */
function fetch_products_by_slugs(array $slugs): array
{
    $slugs = array_values(array_filter(array_map(static function ($slug): string {
        $slug = strtolower(trim((string) $slug));
        return preg_match('/^[a-z0-9][a-z0-9\-_.]{0,190}$/', $slug) ? $slug : '';
    }, $slugs)));
    if (!$slugs || !supabase_is_configured()) {
        return [];
    }

    $in = implode(',', array_map('rawurlencode', $slugs));
    $query = 'select=*&is_active=eq.true&slug=in.(' . $in . ')';
    $result = supabase_request('GET', supabase_table() . '?' . $query . storefront_exclusion_query());
    if (!$result['ok']) {
        $result = supabase_request('GET', supabase_table() . '?' . $query);
    }
    if (!$result['ok'] || !is_array($result['data'])) {
        return [];
    }

    $map = [];
    foreach ($result['data'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slug = strtolower((string) ($row['slug'] ?? ''));
        if ($slug !== '') {
            $map[$slug] = $row;
        }
    }

    $ordered = [];
    foreach ($slugs as $slug) {
        if (isset($map[$slug]) && !is_blocked_product($map[$slug])) {
            $ordered[] = $map[$slug];
        }
    }
    return $ordered;
}

/**
 * Curated highlights for the home page spotlight rail.
 *
 * @return list<array<string,mixed>>
 */
function fetch_spotlight_products(int $limit = 8): array
{
    $items = featured_items();
    if (!$items) {
        return [];
    }
    $items = array_slice($items, 0, max(1, min(12, $limit)));
    $rows = fetch_products_by_slugs(array_column($items, 'slug'));

    $map = [];
    foreach ($rows as $row) {
        $map[strtolower((string) ($row['slug'] ?? ''))] = $row;
    }

    $out = [];
    foreach ($items as $item) {
        $row = $map[$item['slug']] ?? null;
        if ($row === null) {
            continue;
        }
        $row['_featured_badge'] = $item['badge'] !== '' ? $item['badge'] : 'Featured deal';
        $row['_featured_until'] = $item['until'];
        $out[] = $row;
    }
    return $out;
}

/**
 * Highest-discount deals, used by the Flipkart-style offer rail.
 *
 * @return list<array<string,mixed>>
 */
function fetch_best_offer_products(int $limit = 12, int $minDiscount = 40): array
{
    if (!supabase_is_configured()) {
        return [];
    }

    $limit = max(4, min(24, $limit));
    $minDiscount = max(0, min(95, $minDiscount));

    $run = static function (bool $withThreshold) use ($limit, $minDiscount): array {
        $query = 'select=*&is_active=eq.true&price=gt.0'
            . '&order=discount_percent.desc&limit=' . $limit;
        if ($withThreshold && $minDiscount > 0) {
            $query .= '&discount_percent=gte.' . $minDiscount;
        }
        $result = supabase_request('GET', supabase_table() . '?' . $query . storefront_exclusion_query());
        if (!$result['ok']) {
            $result = supabase_request('GET', supabase_table() . '?' . $query);
        }
        if (!$result['ok'] || !is_array($result['data'])) {
            return [];
        }
        return array_values(array_filter($result['data'], static function ($row): bool {
            return is_array($row) && !is_blocked_product($row);
        }));
    };

    $rows = $run(true);
    if (count($rows) < 4 && $minDiscount > 0) {
        $rows = $run(false);
    }

    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        $slug = (string) ($row['slug'] ?? '');
        if ($slug === '' || isset($seen[$slug])) {
            continue;
        }
        $seen[$slug] = true;
        $out[] = $row;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Tag storefront cards with their highlight badge.
 *
 * @param list<array<string,mixed>> $products
 * @return list<array<string,mixed>>
 */
function attach_featured_badges(array $products): array
{
    $badges = featured_badges();
    if (!$badges) {
        return $products;
    }
    foreach ($products as $index => $product) {
        $slug = strtolower((string) ($product['slug'] ?? ''));
        if ($slug !== '' && isset($badges[$slug])) {
            $products[$index]['_featured_badge'] = $badges[$slug];
        }
    }
    return $products;
}

/**
 * Move highlighted products to the front of the grid, keeping API order for
 * everything else.
 *
 * @param list<array<string,mixed>> $products
 * @return list<array<string,mixed>>
 */
function sort_featured_first(array $products): array
{
    $badges = featured_badges();
    if (!$badges) {
        return $products;
    }
    $first = [];
    $rest = [];
    foreach ($products as $product) {
        $slug = strtolower((string) ($product['slug'] ?? ''));
        if ($slug !== '' && isset($badges[$slug])) {
            $first[] = $product;
        } else {
            $rest[] = $product;
        }
    }
    return array_merge($first, $rest);
}

/** Human-friendly expiry line for Telegram replies. */
function featured_until_label(string $until): string
{
    if ($until === '') {
        return 'no expiry';
    }
    $timestamp = strtotime($until);
    return $timestamp !== false ? 'until ' . date('d M Y, h:i A', $timestamp) : 'no expiry';
}
