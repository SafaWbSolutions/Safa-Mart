<?php
/**
 * Ad slots: Google AdSense, direct banner images/HTML or a labelled preview.
 *
 * Positions are fixed so the layout always reserves space (no content jump):
 *   home-top, home-mid, home-bottom, product-top, product-bottom
 *
 * Configure with config.local.php, for example:
 *   'ADSENSE_CLIENT' => 'ca-pub-1234567890123456',
 *   'AD_SLOTS' => [
 *       'home-top' => ['slot' => '1234567890'],                 // AdSense unit
 *       'home-mid' => ['image' => 'uploads/ads/brand.jpg',      // direct banner
 *                      'link'  => 'https://example.com/offer',
 *                      'alt'   => 'Brand sale'],
 *       'home-bottom' => ['html' => '<div>Any custom ad HTML</div>'],
 *   ]
 *
 * Visit any page with ?ads=preview to see the reserved boxes.
 */

function ads_enabled(): bool
{
    return (bool) ADS_ENABLED;
}

function adsense_client(): string
{
    $client = trim((string) ADSENSE_CLIENT);
    return preg_match('/^ca-pub-\d{10,20}$/', $client) ? $client : '';
}

function ad_preview_mode(): bool
{
    return isset($_GET['ads']) && strtolower((string) $_GET['ads']) === 'preview';
}

/** @return array<string,array<string,mixed>> */
function ad_slot_config(): array
{
    $slots = AD_SLOTS;
    return is_array($slots) ? $slots : [];
}

/**
 * Render one ad slot. Returns an empty string when the slot is unused, so
 * nothing shows for visitors until ads are actually configured.
 */
function ad_slot(string $position): string
{
    if (!ads_enabled()) {
        return '';
    }

    $config = ad_slot_config()[$position] ?? null;
    $config = is_array($config) ? $config : [];

    $html = trim((string) ($config['html'] ?? ''));
    $image = trim((string) ($config['image'] ?? ''));
    $link = trim((string) ($config['link'] ?? ''));
    $alt = trim((string) ($config['alt'] ?? 'Sponsored offer'));
    $slotId = trim((string) ($config['slot'] ?? ''));
    $label = trim((string) ($config['label'] ?? 'Advertisement'));
    $height = (int) ($config['height'] ?? 0);

    $client = adsense_client();
    $isAdsense = $slotId !== '' && $client !== '';

    if ($html === '' && $image === '' && !$isAdsense) {
        if (!ad_preview_mode()) {
            return '';
        }
        $html = '';
    }

    $style = $height > 0 ? ' style="min-height:' . max(50, min(600, $height)) . 'px"' : '';

    $body = '';
    if ($html !== '') {
        $body = $html;
    } elseif ($image !== '') {
        $imageUrl = preg_match('#^https?://#i', $image) ? $image : site_url($image);
        $inner = '<img src="' . e($imageUrl) . '" alt="' . e($alt) . '" loading="lazy" decoding="async">';
        $body = $link !== ''
            ? '<a class="ad-banner-link" href="' . e($link) . '" target="_blank" rel="nofollow sponsored noopener">' . $inner . '</a>'
            : $inner;
    } elseif ($isAdsense) {
        if (!isset($GLOBALS['SAFA_ADSENSE_LOADER'])) {
            $GLOBALS['SAFA_ADSENSE_LOADER'] = true;
            $body .= '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client='
                . e($client) . '" crossorigin="anonymous"></script>';
        }
        $body .= '<ins class="adsbygoogle ad-unit" style="display:block"'
            . ' data-ad-client="' . e($client) . '"'
            . ' data-ad-slot="' . e($slotId) . '"'
            . ' data-ad-format="auto" data-full-width-responsive="true"></ins>'
            . '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
    } else {
        $body = '<span class="ad-placeholder-text">Ad slot: <code>' . e($position) . '</code> — 970×90 leaderboard</span>';
    }

    return '<aside class="ad-slot ad-slot-' . e($position) . '" data-ad-position="' . e($position) . '"'
        . $style . ' aria-label="Advertisement">'
        . '<span class="ad-label">' . e($label) . '</span>'
        . '<div class="ad-body">' . $body . '</div>'
        . '</aside>';
}
