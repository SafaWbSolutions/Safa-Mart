<?php
/**
 * Telegram bot webhook: convert a merchant URL, scrape it, store the image as
 * WebP and upsert the product into Supabase.
 */

require_once dirname(__DIR__) . '/config.php';
security_headers();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$providedSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (TELEGRAM_WEBHOOK_SECRET === '' || $providedSecret === '' || !hash_equals(TELEGRAM_WEBHOOK_SECRET, $providedSecret)) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 1048576) {
    json_response(['ok' => false, 'error' => 'Invalid payload'], 400);
}
$update = json_decode($rawBody, true);
if (!is_array($update)) {
    json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$updateId = (string) ($update['update_id'] ?? '');
if ($updateId !== '' && !claim_telegram_update($updateId)) {
    json_response(['ok' => true, 'duplicate' => true]);
}

$message = $update['message'] ?? ($update['channel_post'] ?? null);
if (!is_array($message)) {
    json_response(['ok' => true, 'ignored' => 'no message']);
}

$chatId = $message['chat']['id'] ?? null;
if ($chatId === null) {
    json_response(['ok' => true, 'ignored' => 'no chat']);
}
$chatId = (string) $chatId;

if (!telegram_chat_allowed($chatId)) {
    app_log('warning', 'Telegram listing rejected: chat not allowlisted', ['chat_id' => $chatId]);
    json_response(['ok' => true, 'ignored' => 'chat not allowed']);
}

$text = trim((string) ($message['text'] ?? ($message['caption'] ?? '')));
if ($text === '') {
    telegram_send_message($chatId, 'Send me a product link from Flipkart, Myntra or Amazon and I will add it to the store.');
    json_response(['ok' => true, 'ignored' => 'empty message']);
}

$parts = preg_split('/\s+/', $text) ?: [];
$command = strtolower(explode('@', (string) ($parts[0] ?? ''))[0]);
if ($command === '/start' || $command === '/help') {
    $welcome = '🛍️ <b>' . telegram_escape(APP_NAME) . ' listing bot</b>' . "\n\n"
        . 'Send one or more product links (up to 2 per message). I will convert the affiliate link, download the image, optimise it to WebP and publish the product.' . "\n\n"
        . "Supported: Flipkart, Myntra, Amazon and more.\n"
        . "Commands: /id – show this chat ID\n"
        . "/debug <link> – explain why a link fails\n"
        . "/repairlinks – fix old affiliate links and hide non-product pages\n"
        . "/refreshmeta – refresh brand, rating and description of old products\n"
        . "Store: " . telegram_escape(site_url('index.php'));
    telegram_send_message($chatId, $welcome);
    json_response(['ok' => true, 'command' => $command]);
}
if ($command === '/debug') {
    $debugUrl = trim((string) preg_replace('~^/debug(@\S+)?\s*~i', '', $text));
    if ($debugUrl === '' || !preg_match('#^https?://#i', $debugUrl)) {
        telegram_send_message($chatId, 'Usage: <code>/debug https://example.com/link</code>');
        json_response(['ok' => true, 'command' => '/debug']);
    }

    $lines = ['🔧 <b>Link resolution debug</b>'];
    $lines[] = '• URL: ' . telegram_escape($debugUrl);
    $lines[] = '• Safe URL: ' . (is_safe_remote_url($debugUrl) ? 'yes' : 'no');
    $lines[] = '• Shortener: ' . (needs_url_resolution($debugUrl) ? 'yes' : 'no');

    $probe = http_request($debugUrl, ['timeout' => 15, 'max_bytes' => 2097152]);
    $lines[] = '• HTTP: ' . (int) $probe['status'] . ' · '
        . ($probe['content_type'] !== '' ? telegram_escape($probe['content_type']) : 'no content-type')
        . ' · ' . strlen((string) $probe['body']) . ' bytes';
    if (!empty($probe['error'])) {
        $lines[] = '• Error: ' . telegram_escape((string) $probe['error']);
    }
    if (!empty($probe['effective_url'])) {
        $lines[] = '• Effective: ' . telegram_escape((string) $probe['effective_url']);
    }
    if (!empty($probe['headers']['location'])) {
        $lines[] = '• Location header: ' . telegram_escape((string) $probe['headers']['location']);
    }
    $foundInBody = find_merchant_url_in_html((string) $probe['body'], (string) ($probe['effective_url'] ?? $debugUrl));
    $lines[] = '• Found in page: ' . ($foundInBody !== '' ? telegram_escape($foundInBody) : 'none');
    $foundInQuery = find_merchant_url_in_query($debugUrl);
    $lines[] = '• Found in query: ' . ($foundInQuery !== '' ? telegram_escape($foundInQuery) : 'none');

    $resolved = resolve_merchant_url($debugUrl);
    $lines[] = '• Resolved: ' . telegram_escape($resolved);
    $debugPlatform = detect_platform($resolved);
    $lines[] = '• Platform: ' . telegram_escape($debugPlatform);
    $lines[] = '• Single product page: ' . (is_product_page_url($resolved) ? 'yes' : 'no (listing/campaign link)');

    if (!is_product_page_url($resolved) && $debugPlatform !== 'Other') {
        $expandDebug = [];
        $expanded = expand_collection_url($resolved, 2, affiliate_params_from_url($debugUrl), $expandDebug);
        $lines[] = '• Collection products found: ' . count($expanded);
        foreach (array_slice($expandDebug['attempts'] ?? [], 0, 4) as $attempt) {
            $attemptLine = '   – HTTP ' . (int) ($attempt['status'] ?? 0) . ' · ' . (int) ($attempt['bytes'] ?? 0) . ' bytes';
            if (!empty($attempt['via'])) {
                $attemptLine .= ' · ' . telegram_escape((string) $attempt['via']);
            }
            if (!empty($attempt['error'])) {
                $attemptLine .= ' · ' . telegram_escape((string) $attempt['error']);
            }
            $lines[] = $attemptLine;
        }
        foreach (array_slice($expanded, 0, 2) as $expandedItem) {
            $lines[] = '   • ' . telegram_escape($expandedItem);
        }
    }

    if (!in_array($debugPlatform, EARNKARO_PLATFORMS, true)) {
        $lines[] = '• EarnKaro: this store is not in the supported list';
    } else {
        $affiliate = convert_to_affiliate($resolved, $debugPlatform);
        $lines[] = '• EarnKaro: ' . ($affiliate['converted'] ? '✅ converted' : '❌ not converted');
        $lines[] = '• Affiliate URL: ' . telegram_escape($affiliate['url']);
        if (!$affiliate['converted'] && !empty($affiliate['error'])) {
            $lines[] = '• EarnKaro error: ' . telegram_escape($affiliate['error']);
        }
    }

    telegram_send_message($chatId, implode("\n", $lines));
    json_response(['ok' => true, 'command' => '/debug']);
}
if ($command === '/repairlinks') {
    @set_time_limit(120);
    ignore_user_abort(true);
    telegram_send_message($chatId, '🛠️ Repairing product links… isme thoda time lagega.');

    $batch = 8;
    $query = 'select=id,title,original_url,affiliate_url&is_active=eq.true'
        . '&order=created_at.asc&limit=' . $batch;
    $result = supabase_request('GET', supabase_table() . '?' . $query, null, [
        'service' => true,
        'timeout' => 20,
    ]);
    if (!$result['ok'] || !is_array($result['data'])) {
        telegram_send_message($chatId, '❌ Could not read products: ' . telegram_escape((string) $result['error']));
        json_response(['ok' => true, 'command' => '/repairlinks', 'error' => 'read failed']);
    }

    $checked = 0;
    $affiliated = 0;
    $already = 0;
    $hidden = 0;
    $failed = 0;
    $stopped = false;
    $deadline = microtime(true) + 50;

    foreach ($result['data'] as $row) {
        if (!is_array($row) || microtime(true) >= $deadline) {
            $stopped = microtime(true) >= $deadline;
            break;
        }
        $id = (int) ($row['id'] ?? 0);
        $originalUrl = trim((string) ($row['original_url'] ?? ''));
        $affiliateUrl = trim((string) ($row['affiliate_url'] ?? ''));
        if ($id < 1 || $originalUrl === '') {
            continue;
        }
        $checked++;

        $platform = detect_platform($originalUrl);
        if (!is_product_page_url($originalUrl)
            || is_blocked_platform($platform)
            || is_blocked_url($originalUrl)) {
            $patch = supabase_patch('id=eq.' . $id, ['is_active' => false]);
            if ($patch['ok']) {
                $hidden++;
            } else {
                $failed++;
            }
            continue;
        }

        $looksAffiliated = $affiliateUrl !== ''
            && $affiliateUrl !== $originalUrl
            && is_affiliate_url($affiliateUrl);
        if ($looksAffiliated) {
            $already++;
            continue;
        }

        $conversion = convert_to_affiliate($originalUrl, $platform);
        if (!$conversion['converted']) {
            $failed++;
            app_log('warning', 'Repair: EarnKaro conversion failed', [
                'id'       => $id,
                'platform' => $platform,
                'error'    => $conversion['error'],
            ]);
            continue;
        }

        $patch = supabase_patch('id=eq.' . $id, [
            'affiliate_url'  => $conversion['url'],
            'last_synced_at' => gmdate('c'),
        ]);
        if ($patch['ok']) {
            $affiliated++;
        } else {
            $failed++;
        }
    }

    $summary = '✅ <b>Link repair finished</b>' . "\n"
        . '• Checked: ' . $checked . "\n"
        . '• Newly affiliated: ' . $affiliated . "\n"
        . '• Already affiliated: ' . $already . "\n"
        . '• Hidden (not a product page): ' . $hidden . "\n"
        . '• Could not convert: ' . $failed;
    if ($stopped) {
        $summary .= "\n⏱️ Time limit reached — run /repairlinks again for the rest.";
    } elseif ($checked >= $batch) {
        $summary .= "\n🔁 More products may remain — run /repairlinks again.";
    }
    telegram_send_message($chatId, $summary);
    json_response([
        'ok'        => true,
        'command'   => '/repairlinks',
        'checked'   => $checked,
        'affiliated'=> $affiliated,
        'already'   => $already,
        'hidden'    => $hidden,
        'failed'    => $failed,
    ]);
}
if ($command === '/refreshmeta') {
    @set_time_limit(120);
    ignore_user_abort(true);
    telegram_send_message($chatId, '🔄 Refreshing brand, rating and details…');

    $batch = 8;
    $query = 'select=*&is_active=eq.true'
        . '&order=last_checked_at.asc.nullsfirst&limit=' . $batch;
    $result = supabase_request('GET', supabase_table() . '?' . $query, null, [
        'service' => true,
        'timeout' => 20,
    ]);
    if (!$result['ok'] || !is_array($result['data'])) {
        telegram_send_message($chatId, '❌ Could not read products: ' . telegram_escape((string) $result['error']));
        json_response(['ok' => true, 'command' => '/refreshmeta', 'error' => 'read failed']);
    }

    $updated = 0;
    $hidden = 0;
    $failed = 0;
    $deadline = microtime(true) + 80;
    foreach ($result['data'] as $row) {
        if (!is_array($row) || microtime(true) >= $deadline) {
            break;
        }
        $id = (int) ($row['id'] ?? 0);
        $originalUrl = trim((string) ($row['original_url'] ?? ''));
        if ($id < 1) {
            continue;
        }
        if ($originalUrl === '' || !is_product_page_url($originalUrl)) {
            // Listing/campaign rows that slipped in earlier: hide them so the
            // refresh queue keeps moving.
            $row['is_active'] = false;
            $row['last_checked_at'] = gmdate('c');
            $save = supabase_upsert($row, 'original_url');
            if ($save['ok']) {
                $hidden++;
            } else {
                $failed++;
            }
            continue;
        }
        try {
            $meta = scrape_product_metadata($originalUrl);
        } catch (Throwable $error) {
            $failed++;
            app_log('warning', 'Metadata refresh failed', [
                'id'    => $id,
                'error' => $error->getMessage(),
            ]);
            continue;
        }
        // Upsert (not PATCH): some PostgREST/RLS setups answer PATCH with 204
        // while updating nothing, which made the queue repeat forever.
        $row['brand'] = truncate_text(clean_text((string) ($meta['brand'] ?? '')), 120);
        $row['rating'] = (float) ($meta['rating'] ?? 0);
        $row['rating_count'] = (int) ($meta['rating_count'] ?? 0);
        $row['description'] = truncate_text((string) ($meta['description'] ?? ''), 1800);
        $row['last_checked_at'] = gmdate('c');
        $save = supabase_upsert($row, 'original_url');
        if ($save['ok']) {
            $updated++;
        } else {
            $failed++;
        }
    }

    $summary = '✅ <b>Details refresh finished</b>' . "\n"
        . '• Updated: ' . $updated . "\n"
        . '• Hidden (old junk): ' . $hidden . "\n"
        . '• Failed: ' . $failed;
    if ($updated + $hidden >= $batch || microtime(true) >= $deadline) {
        $summary .= "\n🔁 Run /refreshmeta again for the rest.";
    } else {
        $summary .= "\n🎉 All products refreshed.";
    }
    telegram_send_message($chatId, $summary);
    json_response([
        'ok'      => true,
        'command' => '/refreshmeta',
        'updated' => $updated,
        'hidden'  => $hidden,
        'failed'  => $failed,
    ]);
}
if ($command === '/id') {
    $userId = (string) ($message['from']['id'] ?? 'unknown');
    telegram_send_message($chatId, "Chat ID: <code>" . telegram_escape($chatId) . "</code>\nUser ID: <code>" . telegram_escape($userId) . '</code>');
    json_response(['ok' => true, 'command' => '/id']);
}
if (str_starts_with($text, '/')) {
    telegram_send_message($chatId, 'Unknown command. Send a product URL or use /help.');
    json_response(['ok' => true, 'ignored' => 'unknown command']);
}

if (!rate_limit_hit('telegram-list:' . $chatId, 20, 900)) {
    telegram_send_message($chatId, '⏳ Too many links in a short time. Please wait a few minutes and try again.');
    json_response(['ok' => true, 'ignored' => 'rate limited']);
}

if (!supabase_is_configured(true)) {
    telegram_send_message($chatId, '⚠️ The store database is not configured yet. Add SUPABASE_SERVICE_KEY on the server.');
    json_response(['ok' => true, 'ignored' => 'supabase not configured']);
}

$candidateUrls = [];

preg_match_all('~https?://[^\s<>"\']+~i', $text, $urlMatches);
foreach ($urlMatches[0] as $match) {
    $candidateUrls[] = $match;
}

if (preg_match_all('~\[[^\]]*\]\((https?://[^)\s]+)\)~i', $text, $markdownMatches)) {
    foreach ($markdownMatches[1] as $match) {
        $candidateUrls[] = $match;
    }
}

$entities = array_merge(
    is_array($message['entities'] ?? null) ? $message['entities'] : [],
    is_array($message['caption_entities'] ?? null) ? $message['caption_entities'] : []
);
foreach ($entities as $entity) {
    if (!is_array($entity)) {
        continue;
    }
    $type = (string) ($entity['type'] ?? '');
    if ($type === 'text_link' && !empty($entity['url']) && is_string($entity['url'])) {
        $candidateUrls[] = $entity['url'];
    } elseif ($type === 'url' && isset($entity['offset'], $entity['length'])) {
        $entityText = function_exists('mb_substr')
            ? mb_substr($text, (int) $entity['offset'], (int) $entity['length'], 'UTF-8')
            : substr($text, (int) $entity['offset'], (int) $entity['length']);
        if (is_string($entityText) && $entityText !== '') {
            $candidateUrls[] = $entityText;
        }
    }
}

$urls = [];
foreach ($candidateUrls as $url) {
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = rtrim($url, ".,;:!?)]}'\"");
    if ($url !== '' && preg_match('#^https?://#i', $url) && !in_array($url, $urls, true)) {
        $urls[] = $url;
    }
    if (count($urls) >= 4) {
        break;
    }
}
if (!$urls) {
    telegram_send_message($chatId, 'Please include a product URL in the message. Example: https://www.flipkart.com/...');
    json_response(['ok' => true, 'ignored' => 'no urls']);
}

// EarnKaro often shares a collection/store link (many products in one page).
// Single product pages are the only thing the storefront can show, so such a
// link is expanded into a few individual products before listing starts.
$expandedUrls = [];
$expansionDebug = [];
foreach ($urls as $url) {
    $candidate = resolve_merchant_url($url, 4, false);
    if ($candidate !== '' && !is_product_page_url($candidate) && detect_platform($candidate) !== 'Other') {
        $expandDebug = [];
        $expanded = expand_collection_url($candidate, 4, affiliate_params_from_url($url), $expandDebug);
        $expansionDebug[$url] = $expandDebug;
        if ($expanded) {
            foreach ($expanded as $expandedUrl) {
                if (!in_array($expandedUrl, $expandedUrls, true)) {
                    $expandedUrls[] = $expandedUrl;
                }
            }
            continue;
        }
    }
    $expandedUrls[] = $url;
}
$urls = array_slice($expandedUrls, 0, 8);

@set_time_limit(120);
ignore_user_abort(true);
$deadline = microtime(true) + 95;
$timedOut = false;

telegram_send_message($chatId, '⏳ Processing ' . count($urls) . ' product' . (count($urls) === 1 ? '' : 's') . '…');

$listed = 0;
$failed = 0;
$seen = [];

foreach ($urls as $url) {
    if (microtime(true) >= $deadline) {
        $timedOut = true;
        break;
    }
    try {
        $rawUrl = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!is_safe_remote_url($rawUrl)) {
            throw new RuntimeException('That URL is invalid or not publicly reachable.');
        }

        $canonicalUrl = canonicalize_product_url($rawUrl);
        if (isset($seen[$canonicalUrl])) {
            throw new RuntimeException('This link was already included in the message.');
        }
        $seen[$canonicalUrl] = true;

        if (is_blocked_url($canonicalUrl)) {
            throw new RuntimeException('This store is not accepted. Please send a product link from Flipkart, Myntra, Amazon, Snapdeal, Tata CLiQ, Nykaa, Croma or another supported store.');
        }

        $productUrl = resolve_merchant_url($canonicalUrl);
        $platform = detect_platform($productUrl);
        if (is_blocked_url($productUrl) || is_blocked_platform($platform)) {
            throw new RuntimeException('This store is not accepted. Please send a product link from Flipkart, Myntra, Amazon, Snapdeal, Tata CLiQ, Nykaa, Croma or another supported store.');
        }
        if ($platform === 'Other') {
            throw new RuntimeException('This is not a supported store product page. Send a direct product link, for example from Flipkart, Myntra or Amazon.');
        }
        if (!is_product_page_url($productUrl)) {
            throw new RuntimeException('This is a listing or campaign page, not a single product page. Send the direct product page (Myntra: a /buy link, Flipkart: a /p/ link).');
        }

        $affiliate = convert_to_affiliate($productUrl, $platform);
        if (!$affiliate['converted'] && is_affiliate_url($rawUrl) && $rawUrl !== $productUrl) {
            // Sender ne link pehle se converter se convert kiya hua bheja hai.
            // Re-conversion fail ho to unka tracking link hi rakhna hai.
            $affiliate = [
                'url'      => $rawUrl,
                'platform' => $platform,
                'converted' => true,
                'error'    => null,
            ];
            app_log('info', 'Kept sender provided affiliate link', [
                'platform' => $platform,
                'url'      => $rawUrl,
            ]);
        }
        $metadata = scrape_product_metadata($productUrl);

        if (($metadata['availability'] ?? 'unknown') === 'out_of_stock') {
            throw new RuntimeException('This product is out of stock on the merchant page.');
        }

        $price = (float) ($metadata['price'] ?? 0);
        if ($price <= 0 && preg_match('/(?:₹|Rs\.?|INR)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $text, $priceMatch)) {
            $price = normalize_price($priceMatch[1]);
        }
        $mrp = (float) ($metadata['mrp'] ?? 0);
        if ($mrp < $price) {
            $mrp = $price;
        }
        $metadataTitle = clean_text($metadata['title'] ?? '');
        if ($metadataTitle === '') {
            throw new RuntimeException('Could not find a product title.');
        }

        $images = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
        if (!$images) {
            throw new RuntimeException('No product image was found on the merchant page.');
        }

        $slug = build_slug($metadataTitle);
        $primaryImage = null;
        foreach ($images as $candidateImage) {
            try {
                if (!is_safe_remote_url($candidateImage)) {
                    continue;
                }
                $primaryImage = download_image_as_webp($candidateImage, $slug, 10, origin_of_url($productUrl));
                break;
            } catch (Throwable $imageError) {
                app_log('info', 'Primary image candidate skipped', [
                    'image' => $candidateImage,
                    'error' => $imageError->getMessage(),
                ]);
            }
        }
        if ($primaryImage === null) {
            throw new RuntimeException('The product image could not be downloaded from the merchant page.');
        }
        $gallery = [$primaryImage['url']];
        foreach (array_slice($images, 1, 1) as $extraImage) {
            try {
                if (!is_safe_remote_url($extraImage)) {
                    continue;
                }
                $saved = download_image_as_webp($extraImage, $slug, 6, origin_of_url($productUrl));
                if (!in_array($saved['url'], $gallery, true)) {
                    $gallery[] = $saved['url'];
                }
            } catch (Throwable $imageError) {
                app_log('info', 'Extra gallery image skipped', ['error' => $imageError->getMessage()]);
            }
        }

        $category = infer_category(
            $metadataTitle . ' ' . ($metadata['description'] ?? '') . ' ' . ($metadata['brand'] ?? ''),
            (string) ($metadata['category_hint'] ?? '')
        );

        $payload = [
            'slug'             => $slug,
            'title'            => truncate_text($metadataTitle, 300),
            'description'      => truncate_text((string) ($metadata['description'] ?? ''), 1800),
            'price'            => $price,
            'mrp'              => $mrp > 0 ? $mrp : $price,
            'discount_percent' => calculate_discount($mrp, $price),
            'category'         => $category,
            'source_platform'  => $platform,
            'brand'            => truncate_text(clean_text((string) ($metadata['brand'] ?? '')), 120),
            'rating'           => (float) ($metadata['rating'] ?? 0),
            'rating_count'     => (int) ($metadata['rating_count'] ?? 0),
            'thumbnail_url'    => $primaryImage['url'],
            'gallery_urls'     => $gallery,
            'affiliate_url'    => $affiliate['url'],
            'original_url'     => $productUrl,
            'is_active'        => true,
            'last_checked_at'  => gmdate('c'),
            'last_synced_at'   => gmdate('c'),
        ];

        $saved = supabase_upsert($payload, 'original_url');
        if (!$saved['ok']) {
            throw new RuntimeException('Could not save the product: ' . $saved['error']);
        }

        $listed++;
        $priceLabel = $price > 0 ? '₹' . inr_plain($price) : 'Price on request';
        $confirmation = '✅ <b>Listed:</b> ' . telegram_escape(truncate_text($metadataTitle, 120))
            . ' - ' . telegram_escape($priceLabel) . "\n"
            . '🏬 ' . telegram_escape($platform)
            . ' · 🏷️ ' . telegram_escape($category) . "\n"
            . '🔗 ' . telegram_escape(site_url('product.php?slug=' . rawurlencode($slug)));
        if (!$affiliate['converted'] && !empty($affiliate['error'])) {
            $confirmation .= "\n⚠️ " . telegram_escape($affiliate['error']);
        }
        telegram_send_message($chatId, $confirmation);
    } catch (Throwable $error) {
        $failed++;
        $detail = '';
        $attempts = $expansionDebug[$url]['attempts'] ?? [];
        if ($attempts) {
            $last = end($attempts);
            $detail = '🧪 HTTP ' . (int) ($last['status'] ?? 0)
                . ' · ' . (int) ($last['bytes'] ?? 0) . ' bytes'
                . (!empty($last['via']) ? ' · ' . $last['via'] : '') . "\n";
        }
        app_log('error', 'Telegram product listing failed', [
            'url'   => $url,
            'error' => $error->getMessage(),
        ]);
        telegram_send_message(
            $chatId,
            '❌ <b>Could not list this link</b>' . "\n"
            . telegram_escape($error->getMessage()) . "\n"
            . $detail
            . telegram_escape($url)
        );
    }
}

$summary = '📊 Finished: ' . $listed . ' listed, ' . $failed . ' failed.';
if ($timedOut) {
    $summary .= "\n⏱️ Time limit reached — baaki products dobara bhej dena.";
}
if (count($urls) > 1 || $timedOut) {
    telegram_send_message($chatId, $summary);
}

json_response(['ok' => true, 'listed' => $listed, 'failed' => $failed]);

function telegram_chat_allowed(string $chatId): bool
{
    $allowed = trim(TELEGRAM_ALLOWED_CHAT_IDS);
    if ($allowed === '') {
        return true;
    }
    foreach (preg_split('/[\s,;]+/', $allowed) ?: [] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && hash_equals($candidate, $chatId)) {
            return true;
        }
    }
    return false;
}
