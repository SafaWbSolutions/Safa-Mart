<?php
/**
 * Progressive Web App + notification UI.
 *
 * Included on every public page:
 *   pwa_head_tags()        -> manifest, icons, theme and stylesheet
 *   render_pwa_widgets()   -> floating alert bell, install action, JS config
 */

require_once __DIR__ . '/push.php';

function pwa_head_tags(): void
{
    $icon = site_url('assets/icons/icon-192.png');
    ?>
    <link rel="manifest" href="<?= e(site_url('manifest.webmanifest')) ?>">
    <link rel="icon" type="image/png" sizes="96x96" href="<?= e(site_url('assets/icons/favicon-96.png')) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(site_url('assets/icons/apple-touch-icon.png')) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/pwa.css')) ?>">
    <?php
}

/** @return array<string,mixed> */
function pwa_client_config(): array
{
    $scope = '/';
    $path = (string) parse_url(site_url(), PHP_URL_PATH);
    if ($path !== '' && $path !== '/') {
        $scope = rtrim($path, '/') . '/';
    }

    $swFile = SAFA_ROOT . '/sw.js';
    $swVersion = is_file($swFile) ? (string) filemtime($swFile) : APP_VERSION;

    return [
        'swUrl'      => site_url('sw.js') . '?v=' . rawurlencode($swVersion),
        'scope'      => $scope,
        'siteUrl'    => rtrim(site_url(), '/'),
        'pushApi'    => site_url('api/push.php'),
        'alertsApi'  => site_url('api/alerts.php'),
        'publicKey'  => push_is_configured() ? push_public_key() : '',
        'topics'     => push_topics_allowed(),
        'icon'       => site_url('assets/icons/icon-192.png'),
        'badge'      => site_url('assets/icons/favicon-96.png'),
        'appName'    => APP_NAME,
        'installed'  => false,
    ];
}

function render_pwa_widgets(): void
{
    $topics = push_topics_allowed();
    $pushReady = push_is_configured();
    ?>
    <div class="safa-pwa" id="safaPwa" data-pwa-ready="<?= $pushReady ? '1' : '0' ?>">
        <div class="safa-pwa-panel" id="safaPwaPanel" hidden role="dialog" aria-modal="false"
             aria-labelledby="safaPwaTitle" aria-describedby="safaPwaCopy">
            <div class="safa-pwa-head">
                <div>
                    <span class="safa-pwa-eyebrow">Safa Mart alerts</span>
                    <h2 class="safa-pwa-title" id="safaPwaTitle">Never miss a real deal</h2>
                </div>
                <button class="safa-pwa-close" id="safaPwaClose" type="button" aria-label="Close deal alerts">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <p class="safa-pwa-copy" id="safaPwaCopy">
                Get one short ping when a genuine price drop or a new best offer goes live — not for every product.
            </p>

            <p class="safa-pwa-status" id="safaPwaStatus" role="status">Checking this device…</p>

            <div class="safa-pwa-topics" id="safaPwaTopics">
                <span class="safa-pwa-label">Only ping me for <em>(optional)</em></span>
                <div class="safa-pwa-chips">
                    <?php foreach ($topics as $topic): ?>
                        <button type="button" class="safa-pwa-chip" data-pwa-topic="<?= e($topic) ?>" aria-pressed="false">
                            <?= e($topic) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="safa-pwa-actions">
                <button type="button" class="safa-pwa-primary" id="safaPwaEnable">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>
                    </svg>
                    <span>Enable alerts</span>
                </button>
                <button type="button" class="safa-pwa-secondary" id="safaPwaTest" hidden>Send test</button>
            </div>

            <p class="safa-pwa-ios" id="safaPwaIos" hidden>
                On iPhone, first tap <strong>Share → Add to Home Screen</strong>, then open Safa Mart from the new icon to turn on alerts.
            </p>

            <div class="safa-pwa-install" id="safaPwaInstallRow">
                <button type="button" class="safa-pwa-install-btn" data-safa-install>
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 3v11"/><path d="m8 10 4 4 4-4"/><path d="M5 20h14"/>
                    </svg>
                    Install app
                </button>
                <small>Home-screen shortcut, faster loads, works offline.</small>
            </div>

            <button type="button" class="safa-pwa-disable" id="safaPwaDisable" hidden>Turn off alerts</button>

            <p class="safa-pwa-note">
                No account, no spam. Preferences live on this device only.
                <a href="<?= e(site_url('guides/')) ?>">Browse guides</a>
            </p>
        </div>

        <button type="button" class="safa-bell" id="safaBell" aria-label="Deal alerts and app install"
                aria-expanded="false" aria-controls="safaPwaPanel" title="Deal alerts">
            <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>
            </svg>
            <span class="safa-bell-dot" id="safaBellDot" aria-hidden="true"></span>
        </button>
    </div>

    <script>
        window.SAFA_PWA = <?= json_encode(pwa_client_config(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="<?= e(asset_url('assets/js/pwa.js')) ?>" defer></script>
    <?php
}
