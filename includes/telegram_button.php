<?php
/**
 * Floating "Join Telegram" button. Links straight to the channel so visitors
 * can subscribe without waiting for the join popup.
 */
if (TELEGRAM_CHANNEL_URL === '') {
    return;
}
?>
<a class="tg-fab" href="<?= e(TELEGRAM_CHANNEL_URL) ?>" target="_blank" rel="noopener"
   aria-label="Join our Telegram channel for instant deal alerts" data-cta="telegram-fab">
    <span class="tg-fab-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 2 11 13"/>
            <path d="M22 2 15 22l-4-9-9-4 20-7Z"/>
        </svg>
    </span>
    <span class="tg-fab-text">
        <strong>Join Telegram</strong>
        <small>Instant deal alerts</small>
    </span>
    <span class="tg-fab-live" aria-hidden="true"></span>
</a>
