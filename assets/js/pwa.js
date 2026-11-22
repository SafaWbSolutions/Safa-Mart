/* Safa Mart — PWA install prompt + deal alerts (Web Push). */
(function () {
    'use strict';

    var config = window.SAFA_PWA || {};
    var root = document.getElementById('safaPwa');
    if (!root) {
        return;
    }

    var bell = document.getElementById('safaBell');
    var dot = document.getElementById('safaBellDot');
    var panel = document.getElementById('safaPwaPanel');
    var closeBtn = document.getElementById('safaPwaClose');
    var statusEl = document.getElementById('safaPwaStatus');
    var enableBtn = document.getElementById('safaPwaEnable');
    var testBtn = document.getElementById('safaPwaTest');
    var disableBtn = document.getElementById('safaPwaDisable');
    var installRow = document.getElementById('safaPwaInstallRow');
    var iosHint = document.getElementById('safaPwaIos');
    var topicsWrap = document.getElementById('safaPwaTopics');
    var copyEl = document.getElementById('safaPwaCopy');

    var TOPIC_KEY = 'safa_topics_v1';
    var SYNC_KEY = 'safa_push_sync_v1';
    var DIGEST_KEY = 'safa_digest_v1';
    var LANG_KEY = 'safa_lang_v1';
    var FOLLOW_KEY = 'safa_followed_v1';

    var topics = loadTopics();
    var digestMode = loadDigestMode();
    var lang = loadLang();
    var subscribed = false;
    var busy = false;
    var deferredPrompt = null;
    var pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
    var isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent)
        || (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);

    function loadTopics() {
        try {
            var raw = window.localStorage.getItem(TOPIC_KEY);
            var parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed.filter(function (t) { return typeof t === 'string'; }) : [];
        } catch (error) {
            return [];
        }
    }

    function loadDigestMode() {
        try {
            var raw = window.localStorage.getItem(DIGEST_KEY);
            return raw === 'daily' ? 'daily' : 'instant';
        } catch (error) {
            return 'instant';
        }
    }

    function saveDigestMode(mode) {
        try {
            window.localStorage.setItem(DIGEST_KEY, mode);
        } catch (error) {
            /* ignore */
        }
    }

    function loadLang() {
        try {
            var raw = window.localStorage.getItem(LANG_KEY);
            return raw === 'hi' ? 'hi' : 'en';
        } catch (error) {
            return 'en';
        }
    }

    function saveLang(langCode) {
        try {
            window.localStorage.setItem(LANG_KEY, langCode);
        } catch (error) {
            /* ignore */
        }
    }

    function saveTopics() {
        try {
            window.localStorage.setItem(TOPIC_KEY, JSON.stringify(topics));
        } catch (error) {
            /* private mode */
        }
    }

    function setStatus(text, tone) {
        if (!statusEl) {
            return;
        }
        statusEl.textContent = text;
        statusEl.className = 'safa-pwa-status' + (tone ? ' is-' + tone : '');
    }

    function paintChips() {
        if (!topicsWrap) {
            return;
        }
        Array.prototype.forEach.call(topicsWrap.querySelectorAll('[data-pwa-topic]'), function (chip) {
            var topic = chip.getAttribute('data-pwa-topic');
            chip.setAttribute('aria-pressed', topics.indexOf(topic) !== -1 ? 'true' : 'false');
        });
    }

    function syncUI() {
        paintChips();
        // Digest mode radio buttons
        var digestRadios = document.querySelectorAll('input[name="safa-digest"]');
        digestRadios.forEach(function (radio) {
            radio.checked = (radio.value === digestMode);
        });
        // Language toggle buttons
        var langBtns = document.querySelectorAll('.safa-pwa-lang-btn[data-lang]');
        langBtns.forEach(function (btn) {
            btn.setAttribute('aria-pressed', btn.getAttribute('data-lang') === lang ? 'true' : 'false');
        });

        if (dot) {
            dot.hidden = subscribed;
        }
        if (bell) {
            bell.classList.toggle('is-on', subscribed);
            bell.setAttribute('title', subscribed ? 'Deal alerts are on' : 'Get deal alerts');
        }
        if (disableBtn) {
            disableBtn.hidden = !subscribed;
        }
        if (testBtn) {
            testBtn.hidden = !subscribed;
        }

        if (!pushSupported) {
            setStatus('This browser does not support notifications. You can still install the app.', 'warn');
            if (enableBtn) {
                enableBtn.disabled = true;
                enableBtn.querySelector('span').textContent = 'Not supported here';
            }
            return;
        }

        if (isIos && !isStandalone) {
            if (iosHint) {
                iosHint.hidden = false;
            }
            setStatus('Add Safa Mart to your Home Screen to enable alerts on iPhone.', 'warn');
        } else if (iosHint) {
            iosHint.hidden = true;
        }

        if (Notification.permission === 'denied') {
            setStatus('Notifications are blocked for this site. Allow them in your browser settings, then reload.', 'warn');
            if (enableBtn) {
                enableBtn.disabled = true;
                enableBtn.querySelector('span').textContent = 'Blocked in browser';
            }
            return;
        }

        if (enableBtn) {
            enableBtn.disabled = subscribed || (isIos && !isStandalone);
        }

        if (subscribed) {
            setStatus('Alerts are on for this device. We only ping when a real deal lands.', 'ok');
            if (enableBtn) {
                enableBtn.querySelector('span').textContent = 'Alerts are on';
            }
            if (copyEl) {
                copyEl.textContent = 'You are covered. One short ping for genuine price drops — nothing else.';
            }
        } else {
            setStatus('Alerts are off. One tap to turn them on — no account needed.');
            if (enableBtn) {
                enableBtn.querySelector('span').textContent = 'Enable alerts';
            }
        }

        if (installRow) {
            installRow.hidden = isStandalone && !isIos;
        }
    }

    function urlBase64ToBytes(value) {
        var padding = '='.repeat((4 - (value.length % 4)) % 4);
        var base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var output = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            output[i] = raw.charCodeAt(i);
        }
        return output;
    }

    function post(action, payload) {
        return fetch(config.pushApi + '?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload || {})
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: 'Server error (' + response.status + ')' };
            });
        });
    }

    function registration() {
        return navigator.serviceWorker.ready;
    }

    function currentSubscription() {
        return registration().then(function (reg) {
            return reg.pushManager.getSubscription();
        });
    }

    function syncTopicsToServer(subscription) {
        if (!subscription) {
            return Promise.resolve();
        }
        return post('subscribe', {
            subscription: subscription.toJSON(),
            topics: topics,
            digest_mode: digestMode,
            lang: lang
        }).then(function () {
            try {
                window.localStorage.setItem(SYNC_KEY, String(Date.now()));
            } catch (error) {
                /* ignore */
            }
        });
    }

    function enableAlerts() {
        if (busy) {
            return;
        }
        busy = true;
        if (enableBtn) {
            enableBtn.disabled = true;
        }
        setStatus('Asking your browser for permission…');

        if (!config.publicKey) {
            busy = false;
            setStatus('Alerts are not configured on the server yet. Please try again later.', 'warn');
            syncUI();
            return;
        }

        Notification.requestPermission()
            .then(function (permission) {
                if (permission !== 'granted') {
                    throw new Error('permission-denied');
                }
                return registration();
            })
            .then(function (reg) {
                return reg.pushManager.getSubscription().then(function (existing) {
                    if (existing) {
                        return existing;
                    }
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToBytes(config.publicKey)
                    });
                }).then(function (subscription) {
                    return post('subscribe', {
                        subscription: subscription.toJSON(),
                        topics: topics,
                        digest_mode: digestMode,
                        lang: lang
                    }).then(function (result) {
                        if (!result || !result.ok) {
                            throw new Error((result && result.error) || 'subscribe-failed');
                        }
                        subscribed = true;
                        setStatus('Alerts are on. Sending a quick test…', 'ok');
                        return reg.showNotification('Deal alerts are on 🎉', {
                            body: 'We will ping you only when a genuine price drop lands.',
                            icon: config.icon,
                            badge: config.badge,
                            tag: 'safa-welcome',
                            data: { url: (config.siteUrl || '') + '/index.php?sort=discount_desc' }
                        }).catch(function () { return undefined; }).then(function () {
                            setStatus('Alerts are on for this device. We only ping when a real deal lands.', 'ok');
                        });
                    });
                });
            })
            .catch(function (error) {
                if (error && error.message === 'permission-denied') {
                    setStatus('Permission was not granted. You can enable it from the browser site settings.', 'warn');
                } else if (isIos && !isStandalone) {
                    setStatus('On iPhone, add Safa Mart to your Home Screen first, then open it from there.', 'warn');
                } else {
                    setStatus('Could not enable alerts right now. Please try again.', 'warn');
                }
            })
            .then(function () {
                busy = false;
                syncUI();
            });
    }

    function disableAlerts() {
        if (busy) {
            return;
        }
        busy = true;
        setStatus('Turning alerts off…');
        currentSubscription()
            .then(function (subscription) {
                if (!subscription) {
                    return null;
                }
                var endpoint = subscription.endpoint;
                return post('unsubscribe', { endpoint: endpoint }).then(function () {
                    return subscription.unsubscribe();
                });
            })
            .then(function () {
                subscribed = false;
                setStatus('Alerts are off. You can turn them back on anytime.');
            })
            .catch(function () {
                setStatus('Could not turn alerts off. Please try again.', 'warn');
            })
            .then(function () {
                busy = false;
                syncUI();
            });
    }

    function sendTest() {
        if (busy) {
            return;
        }
        busy = true;
        setStatus('Sending a test push…');
        currentSubscription()
            .then(function (subscription) {
                if (!subscription) {
                    throw new Error('no-subscription');
                }
                return post('test', { endpoint: subscription.endpoint });
            })
            .then(function (result) {
                if (result && result.ok) {
                    setStatus('Test sent. It should appear in a few seconds.', 'ok');
                } else {
                    setStatus((result && result.error) || 'Test failed. Please try again.', 'warn');
                }
            })
            .catch(function () {
                setStatus('Enable alerts first, then send a test.', 'warn');
            })
            .then(function () {
                busy = false;
                syncUI();
            });
    }

    function openPanel() {
        if (!panel) {
            return;
        }
        panel.hidden = false;
        if (bell) {
            bell.setAttribute('aria-expanded', 'true');
        }
        document.addEventListener('keydown', onKeydown);
        document.addEventListener('click', onOutside, true);
        window.setTimeout(function () {
            if (closeBtn) {
                closeBtn.focus({ preventScroll: true });
            }
        }, 40);
    }

    function closePanel() {
        if (!panel) {
            return;
        }
        panel.hidden = true;
        if (bell) {
            bell.setAttribute('aria-expanded', 'false');
        }
        document.removeEventListener('keydown', onKeydown);
        document.removeEventListener('click', onOutside, true);
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            closePanel();
            if (bell) {
                bell.focus();
            }
        }
    }

    function onOutside(event) {
        if (panel && !panel.hidden && root && !root.contains(event.target)) {
            closePanel();
        }
    }

    function triggerInstall() {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                if (choice && choice.outcome === 'accepted') {
                    setStatus('Installing Safa Mart…', 'ok');
                }
                deferredPrompt = null;
                syncUI();
            }).catch(function () { deferredPrompt = null; });
            return;
        }

        openPanel();
        if (isIos) {
            if (iosHint) {
                iosHint.hidden = false;
            }
            setStatus('On iPhone: Share → Add to Home Screen. Then open it from the icon.', 'warn');
        } else if (isStandalone) {
            setStatus('Safa Mart is already installed. 🎉', 'ok');
        } else {
            setStatus('Open your browser menu and choose "Install app" or "Add to Home screen".', 'warn');
        }
    }

    window.safaInstall = triggerInstall;

    if (bell) {
        bell.addEventListener('click', function () {
            if (panel && panel.hidden) {
                openPanel();
            } else {
                closePanel();
            }
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            closePanel();
            if (bell) {
                bell.focus({ preventScroll: true });
            }
        });
    }
    if (enableBtn) {
        enableBtn.addEventListener('click', enableAlerts);
    }
    if (testBtn) {
        testBtn.addEventListener('click', sendTest);
    }
    if (disableBtn) {
        disableBtn.addEventListener('click', disableAlerts);
    }
    if (topicsWrap) {
        topicsWrap.addEventListener('click', function (event) {
            var chip = event.target.closest ? event.target.closest('[data-pwa-topic]') : null;
            if (!chip) {
                return;
            }
            var topic = chip.getAttribute('data-pwa-topic');
            var index = topics.indexOf(topic);
            if (index === -1) {
                topics.push(topic);
            } else {
                topics.splice(index, 1);
            }
            saveTopics();
            paintChips();
            if (subscribed) {
                currentSubscription().then(syncTopicsToServer).catch(function () { return undefined; });
                setStatus('Saved. Your alerts now follow these categories.', 'ok');
            } else {
                setStatus('Saved. These apply as soon as you enable alerts.');
            }
        });
    }

    // Digest mode radio buttons
    var digestRadios = document.querySelectorAll('input[name="safa-digest"]');
    digestRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (this.checked) {
                digestMode = this.value;
                saveDigestMode(digestMode);
                if (subscribed) {
                    currentSubscription().then(syncTopicsToServer).catch(function () { /* ignore */ });
                }
                setStatus('Digest mode: ' + (digestMode === 'daily' ? 'Daily 9 AM' : 'Instant'), 'ok');
            }
        });
    });

    // Language toggle buttons
    var langBtns = document.querySelectorAll('.safa-pwa-lang-btn[data-lang]');
    langBtns.forEach(function (btn) {
        btn.setAttribute('aria-pressed', btn.getAttribute('data-lang') === lang ? 'true' : 'false');
        btn.addEventListener('click', function () {
            var newLang = this.getAttribute('data-lang');
            if (newLang === lang) return;
            lang = newLang;
            saveLang(lang);
            langBtns.forEach(function (b) {
                b.setAttribute('aria-pressed', b.getAttribute('data-lang') === lang ? 'true' : 'false');
            });
            applyLanguage(lang);
            if (subscribed) {
                currentSubscription().then(syncTopicsToServer).catch(function () { /* ignore */ });
            }
            setStatus('Language: ' + (lang === 'hi' ? 'हिंदी (Hinglish)' : 'English'), 'ok');
        });
    });

    function applyLanguage(langCode) {
        var isHindi = langCode === 'hi';
        // Update UI text based on language
        var elements = {
            'safaPwaTitle': isHindi ? 'कभी असली डील मिस न करें' : 'Never miss a real deal',
            'safaPwaCopy': isHindi ? 'असली प्राइस ड्रॉप या नया बेस्ट ऑफर आने पर एक छोटा पिंग मिलेगा — हर प्रोडक्ट के लिए नहीं।' : 'Get one short ping when a genuine price drop or a new best offer goes live — not for every product.',
            'safaPwaEnable span': isHindi ? 'अलर्ट चालू करें' : 'Enable alerts',
            'safaPwaTest': isHindi ? 'टेस्ट भेजें' : 'Send test',
            'safaPwaDisable': isHindi ? 'अलर्ट बंद करें' : 'Turn off alerts',
            'safaPwaIos': isHindi ? 'iPhone पर, पहले <strong>Share → Add to Home Screen</strong> टैप करें, फिर नए आइकन से Safa Mart खोलें अलर्ट चालू करने के लिए।' : 'On iPhone, first tap <strong>Share → Add to Home Screen</strong>, then open Safa Mart from the new icon to turn on alerts.',
            'safaPwaInstallRow button': isHindi ? 'ऐप इंस्टॉल करें' : 'Install app',
            'safaPwaInstallRow small': isHindi ? 'होम-स्क्रीन शॉर्टकट, तेज़ लोड, ऑफलाइन काम करता है।' : 'Home-screen shortcut, faster loads, works offline.',
            'safaPwaNote': isHindi ? 'कोई अकाउंट नहीं, कोई स्पैम नहीं। प्राथमिकताएँ इस डिवाइस पर ही रहती हैं। <a href="' + config.siteUrl + '/guides/">गाइड देखें</a>' : 'No account, no spam. Preferences live on this device only. <a href="' + config.siteUrl + '/guides/">Browse guides</a>',
        };

        for (var id in elements) {
            var el = document.getElementById(id) || document.querySelector(id);
            if (el) {
                el.innerHTML = elements[id];
            }
        }

        // Update digest radio labels
        var digestLabels = {
            'instant': isHindi ? 'तुरंत' : 'Instant',
            'instant_desc': isHindi ? 'डील आते ही तुरंत पिंग करें' : 'Ping immediately when a deal drops',
            'daily': isHindi ? 'दैनिक डाइजेस्ट (सुबह 9 बजे)' : 'Daily digest (9 AM)',
            'daily_desc': isHindi ? 'हर सुबह एक समरी नोटिफिकेशन' : 'One summary notification each morning',
        };
        var instantRadio = document.querySelector('input[name="safa-digest"][value="instant"]');
        var dailyRadio = document.querySelector('input[name="safa-digest"][value="daily"]');
        if (instantRadio) instantRadio.parentElement.querySelector('.safa-pwa-digest-label strong').textContent = digestLabels.instant;
        if (instantRadio) instantRadio.parentElement.querySelector('.safa-pwa-digest-label span').textContent = digestLabels.instant_desc;
        if (dailyRadio) dailyRadio.parentElement.querySelector('.safa-pwa-digest-label strong').textContent = digestLabels.daily;
        if (dailyRadio) dailyRadio.parentElement.querySelector('.safa-pwa-digest-label span').textContent = digestLabels.daily_desc;

        // Update lang button states
        document.querySelectorAll('.safa-pwa-lang-btn[data-lang]').forEach(function (btn) {
            btn.setAttribute('aria-pressed', btn.getAttribute('data-lang') === langCode ? 'true' : 'false');
        });

        // Update digest mode radio checked state
        document.querySelectorAll('input[name="safa-digest"]').forEach(function (r) {
            r.checked = (r.value === digestMode);
        });
    }

    // Initialize language on load
    applyLanguage(lang);

    Array.prototype.forEach.call(document.querySelectorAll('[data-safa-install]'), function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            triggerInstall();
        });
    });

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        if (installRow) {
            installRow.hidden = false;
        }
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        if (installRow) {
            installRow.hidden = true;
        }
        setStatus('App installed. Open it from your home screen for faster deals.', 'ok');
    });

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register(config.swUrl, { scope: config.scope || '/' })
            .then(function () {
                return registration();
            })
            .then(function () {
                // A browser subscription can outlive the server store (cache
                // clear, host migration, restore from backup). Sync it on
                // every visit so the phone never silently falls off the list.
                return refreshSubscription();
            })
            .catch(function () {
                setStatus('Service worker could not start. Alerts work best over HTTPS.', 'warn');
                syncUI();
            });
    } else {
        syncUI();
    }

    function refreshSubscription() {
        if (!pushSupported) {
            return Promise.resolve(false);
        }
        return currentSubscription().then(function (subscription) {
            if (!subscription || Notification.permission !== 'granted') {
                subscribed = false;
                syncUI();
                return false;
            }
            subscribed = true;
            syncUI();
            return post('subscribe', {
                subscription: subscription.toJSON(),
                topics: topics,
                digest_mode: digestMode,
                lang: lang
            }).then(function (result) {
                if (result && result.ok) {
                    try {
                        window.localStorage.setItem(SYNC_KEY, String(Date.now()));
                    } catch (error) {
                        /* ignore */
                    }
                }
                syncUI();
                return !!(result && result.ok);
            }).catch(function () {
                return false;
            });
        }).catch(function () {
            return false;
        });
    }

    // Follow button logic for product cards and product page
    var FOLLOW_KEY = 'safa_followed_v1';

    function loadFollowed() {
        try {
            var raw = window.localStorage.getItem(FOLLOW_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (error) {
            return [];
        }
    }

    function saveFollowed(followed) {
        try {
            window.localStorage.setItem(FOLLOW_KEY, JSON.stringify(followed));
        } catch (error) {
            /* ignore */
        }
    }

    function isFollowed(slug) {
        var followed = loadFollowed();
        return followed.indexOf(slug) !== -1;
    }

    function toggleFollow(slug, button) {
        var followed = loadFollowed();
        var idx = followed.indexOf(slug);
        if (idx === -1) {
            followed.push(slug);
            button.setAttribute('aria-pressed', 'true');
            button.classList.add('followed');
            if (button.querySelector('.follow-text')) {
                button.querySelector('.follow-text').textContent = 'Following';
            }
        } else {
            followed.splice(idx, 1);
            button.setAttribute('aria-pressed', 'false');
            button.classList.remove('followed');
            if (button.querySelector('.follow-text')) {
                button.querySelector('.follow-text').textContent = 'Follow';
            }
        }
        saveFollowed(followed);

        // If push is enabled, sync follows to server
        if (subscribed) {
            currentSubscription().then(function (sub) {
                if (sub) {
                    post('follow-sync', {
                        subscription: sub.toJSON(),
                        followed: followed
                    }).catch(function () { /* ignore */ });
                }
            });
        }
    }

    // Initialize follow buttons on page load
    function initFollowButtons() {
        document.querySelectorAll('.follow-btn[data-slug]').forEach(function (button) {
            var slug = button.getAttribute('data-slug');
            if (!slug) return;
            if (isFollowed(slug)) {
                button.setAttribute('aria-pressed', 'true');
                button.classList.add('followed');
                if (button.querySelector('.follow-text')) {
                    button.querySelector('.follow-text').textContent = 'Following';
                }
            }
            button.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggleFollow(slug, button);
            });
        });
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFollowButtons);
    } else {
        initFollowButtons();
    }

    // Re-init after dynamic content loads (e.g., infinite scroll)
    var origFetchProducts = window.fetchProducts || function () {};
    window.fetchProducts = function () {
        var result = origFetchProducts.apply(this, arguments);
        if (result && typeof result.then === 'function') {
            result.then(initFollowButtons);
        }
        return result;
    };

    syncUI();
})();