<?php
/**
 * First-visit Telegram channel popup.
 *
 * Shows once per browser per day. The join button opens the channel in a new
 * tab; closing it just remembers the visit so it does not nag on every page.
 */
if (!defined('TELEGRAM_CHANNEL_URL') || trim((string) TELEGRAM_CHANNEL_URL) === '') {
    return;
}

$channelUrl = trim((string) TELEGRAM_CHANNEL_URL);
$channelHandle = trim((string) parse_url($channelUrl, PHP_URL_PATH), '/');
$channelHandle = $channelHandle !== '' ? '@' . ltrim($channelHandle, '@') : 'our channel';
?>
<div class="safa-tg" id="safaTgPopup" hidden>
    <div class="safa-tg__backdrop" data-tg-close aria-hidden="true"></div>
    <div class="safa-tg__card" role="dialog" aria-modal="true" aria-labelledby="safaTgTitle" aria-describedby="safaTgText">
        <button class="safa-tg__close" type="button" data-tg-close aria-label="Close">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>

        <div class="safa-tg__badge" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
        </div>

        <p class="safa-tg__eyebrow">Safa Mart · Loot Buddy</p>
        <h2 class="safa-tg__title" id="safaTgTitle">Join our Telegram channel</h2>
        <p class="safa-tg__text" id="safaTgText">
            Stay connected with our Telegram channel for real-time updates, exclusive offers and instant access to our community.
        </p>

        <a class="safa-tg__join" href="<?= e($channelUrl) ?>" target="_blank" rel="noopener" data-tg-join>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
            <span>Join <?= e($channelHandle) ?></span>
        </a>

        <button class="safa-tg__later" type="button" data-tg-close>Maybe later</button>
    </div>
</div>

<style>
    body.safa-tg-open { overflow: hidden; }

    .safa-tg {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        font-family: inherit;
    }
    .safa-tg[hidden] { display: none; }

    .safa-tg__backdrop {
        position: absolute;
        inset: 0;
        background: rgba(10, 18, 30, 0.58);
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
        opacity: 0;
        transition: opacity 0.22s ease;
    }

    .safa-tg__card {
        position: relative;
        z-index: 1;
        width: 100%;
        max-width: 390px;
        padding: 30px 24px 18px;
        border-radius: 26px;
        text-align: center;
        color: #fff;
        background: linear-gradient(160deg, #2aa5e0 0%, #1d90c9 45%, #0d6ea3 100%);
        box-shadow: 0 28px 70px rgba(2, 20, 36, 0.45);
        transform: translateY(18px) scale(0.96);
        opacity: 0;
        transition: transform 0.24s cubic-bezier(0.2, 0.9, 0.3, 1), opacity 0.24s ease;
    }

    .safa-tg.is-visible .safa-tg__backdrop { opacity: 1; }
    .safa-tg.is-visible .safa-tg__card {
        transform: translateY(0) scale(1);
        opacity: 1;
    }

    .safa-tg__close {
        position: absolute;
        top: 14px;
        right: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border: 0;
        border-radius: 50%;
        color: rgba(255, 255, 255, 0.85);
        background: rgba(255, 255, 255, 0.14);
        cursor: pointer;
    }
    .safa-tg__close:hover { background: rgba(255, 255, 255, 0.24); color: #fff; }

    .safa-tg__badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 62px;
        height: 62px;
        margin: 0 auto 14px;
        border-radius: 50%;
        color: #1d90c9;
        background: #fff;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
    }

    .safa-tg__eyebrow {
        margin: 0 0 4px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.72);
    }

    .safa-tg__title {
        margin: 0 0 8px;
        font-size: 22px;
        line-height: 1.25;
        font-weight: 800;
    }

    .safa-tg__text {
        margin: 0 0 20px;
        font-size: 13.5px;
        line-height: 1.55;
        color: rgba(255, 255, 255, 0.9);
    }

    .safa-tg__join {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        width: 100%;
        padding: 14px 18px;
        border-radius: 999px;
        font-size: 15px;
        font-weight: 800;
        text-decoration: none;
        color: #1176a8;
        background: #fff;
        box-shadow: 0 12px 26px rgba(0, 0, 0, 0.2);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .safa-tg__join:hover,
    .safa-tg__join:focus-visible {
        transform: translateY(-1px);
        box-shadow: 0 16px 30px rgba(0, 0, 0, 0.26);
        color: #0b5f8a;
    }

    .safa-tg__later {
        margin-top: 12px;
        padding: 8px 10px;
        border: 0;
        background: transparent;
        font-size: 12.5px;
        color: rgba(255, 255, 255, 0.75);
        text-decoration: underline;
        cursor: pointer;
    }
    .safa-tg__later:hover { color: #fff; }

    @media (prefers-reduced-motion: reduce) {
        .safa-tg__backdrop,
        .safa-tg__card,
        .safa-tg__join { transition: none; }
    }
</style>

<script>
(function () {
    'use strict';

    var STORAGE_KEY = 'safa_tg_popup_v1';
    var TTL = 24 * 60 * 60 * 1000;

    try {
        var seen = parseInt(window.localStorage.getItem(STORAGE_KEY) || '0', 10);
        if (seen && (Date.now() - seen) < TTL) {
            return;
        }
    } catch (error) { /* storage blocked */ }

    var popup = document.getElementById('safaTgPopup');
    if (!popup) {
        return;
    }

    var card = popup.querySelector('.safa-tg__card');
    var join = popup.querySelector('[data-tg-join]');
    var closers = popup.querySelectorAll('[data-tg-close]');
    var lastFocus = null;

    function remember() {
        try { window.localStorage.setItem(STORAGE_KEY, String(Date.now())); } catch (error) {}
    }

    function open() {
        lastFocus = document.activeElement;
        popup.hidden = false;
        document.body.classList.add('safa-tg-open');
        window.requestAnimationFrame(function () {
            popup.classList.add('is-visible');
        });
        if (join && typeof join.focus === 'function') {
            join.focus({ preventScroll: true });
        }
    }

    function close() {
        remember();
        popup.classList.remove('is-visible');
        document.body.classList.remove('safa-tg-open');
        window.setTimeout(function () { popup.hidden = true; }, 220);
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    for (var i = 0; i < closers.length; i++) {
        closers[i].addEventListener('click', close);
    }

    if (join) {
        join.addEventListener('click', function () {
            remember();
            window.setTimeout(close, 120);
        });
    }

    popup.addEventListener('click', function (event) {
        if (event.target === popup) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (popup.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            close();
            return;
        }
        if (event.key === 'Tab' && card) {
            var focusables = card.querySelectorAll('a[href], button:not([disabled])');
            if (!focusables.length) {
                return;
            }
            var first = focusables[0];
            var last = focusables[focusables.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    window.setTimeout(open, 700);
})();
</script>
