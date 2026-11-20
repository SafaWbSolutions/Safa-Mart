/* Safa Mart storefront interactions: search, filters, pagination, gallery. */
(function () {
    'use strict';

    var cfg = window.SAFA_CONFIG || {};
    var current = cfg.current || {};
    var grid = document.getElementById('productGrid');
    var searchForm = document.getElementById('searchForm');
    var searchInput = document.getElementById('searchInput');
    var resultCount = document.getElementById('resultCount');
    var emptyState = document.getElementById('emptyState');
    var emptyTitle = document.getElementById('emptyTitle');
    var emptyText = document.getElementById('emptyText');
    var loadMoreWrap = document.getElementById('loadMoreWrap');
    var loadMoreBtn = document.getElementById('loadMoreBtn');
    var sentinel = document.getElementById('infiniteSentinel');
    var toast = document.getElementById('toast');
    var header = document.getElementById('siteHeader');
    var pageSize = Number(cfg.pageSize) || 24;

    var state = {
        q: current.q || '',
        category: current.category || '',
        platform: current.platform || '',
        offset: grid ? (Number(grid.dataset.offset) || 0) : 0,
        loaded: grid ? grid.querySelectorAll('.product-card').length : 0,
        hasMore: grid ? grid.dataset.hasMore === '1' : false,
        loading: false,
        requestId: 0,
        controller: null
    };

    function $(selector, root) {
        return (root || document).querySelector(selector);
    }

    function $all(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[char];
        });
    }

    function formatINR(value) {
        var number = Number(value);
        if (!isFinite(number) || number < 0) {
            number = 0;
        }
        var decimals = Math.floor(number) === number ? 0 : 2;
        return '₹' + number.toLocaleString('en-IN', {
            minimumFractionDigits: 0,
            maximumFractionDigits: decimals
        });
    }

    function calculateDiscount(mrp, price) {
        if (!price || !mrp || mrp <= price) {
            return 0;
        }
        return Math.max(0, Math.min(99, Math.round(((mrp - price) / mrp) * 100)));
    }

    function platformClass(platform) {
        var value = String(platform || 'store').toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        return value || 'store';
    }

    function safeImageUrl(url) {
        var value = String(url || '').trim();
        return /^https?:\/\//i.test(value) ? value : '';
    }

    function sanitizeSearch(value) {
        return String(value || '')
            .replace(/[,()*%\\"'`]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 60);
    }

    function placeholderMarkup() {
        return '<div class="product-placeholder" aria-hidden="true">'
            + '<svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6">'
            + '<path d="M6 8h12l-1 12H7L6 8Z"></path><path d="M9 8a3 3 0 0 1 6 0"></path></svg>'
            + '<span>Image coming soon</span></div>';
    }

    function productCard(product) {
        var id = Number(product.id) || 0;
        if (id < 1) {
            return '';
        }
        var title = String(product.title || 'Product').trim();
        var price = Number(product.price) || 0;
        var mrp = Number(product.mrp) || 0;
        var discount = Number(product.discount_percent) || calculateDiscount(mrp, price);
        var platform = String(product.source_platform || 'Store');
        if (platform === 'Other' || platform === '') {
            platform = 'Store';
        }
        var image = safeImageUrl(product.thumbnail_url);
        var slug = String(product.slug || '');
        var productUrl = 'product.php?slug=' + encodeURIComponent(slug);
        var buyUrl = 'go.php?id=' + encodeURIComponent(id);
        var media = image
            ? '<img src="' + escapeHtml(image) + '" alt="' + escapeHtml(title) + '" loading="lazy" decoding="async" class="product-image js-product-img">'
            : placeholderMarkup();
        var discountHtml = discount > 0
            ? '<span class="discount-pill">' + discount + '% OFF</span>'
            : '';
        var mrpHtml = (mrp > price && price > 0)
            ? '<span class="price-mrp">' + formatINR(mrp) + '</span>'
            : '';

        return '<article class="product-card" data-product-id="' + id + '">'
            + '<a class="product-media" href="' + escapeHtml(productUrl) + '" aria-label="' + escapeHtml(title) + '">'
            + media
            + discountHtml
            + '<span class="platform-badge platform-' + escapeHtml(platformClass(platform)) + '">' + escapeHtml(platform) + '</span>'
            + '</a>'
            + '<div class="product-body">'
            + '<a class="product-title" href="' + escapeHtml(productUrl) + '" title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</a>'
            + '<div class="product-price-row"><span class="product-price">' + (price > 0 ? formatINR(price) : 'Price on request') + '</span>' + mrpHtml + '</div>'
            + '<a class="buy-button" href="' + escapeHtml(buyUrl) + '" target="_blank" rel="nofollow sponsored noopener" aria-label="Buy ' + escapeHtml(title) + ' now">'
            + '<span>Buy Now</span>'
            + '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M7 17 17 7"></path><path d="M8 7h9v9"></path></svg>'
            + '</a></div></article>';
    }

    function skeletonMarkup(count) {
        var output = '';
        for (var i = 0; i < count; i += 1) {
            output += '<div class="skeleton-card" aria-hidden="true">'
                + '<div class="skeleton-media"></div>'
                + '<div class="skeleton-body">'
                + '<div class="skeleton-line"></div><div class="skeleton-line short"></div>'
                + '<div class="skeleton-line price"></div><div class="skeleton-line button"></div>'
                + '</div></div>';
        }
        return output;
    }

    function showToast(message) {
        if (!toast) {
            return;
        }
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 2600);
    }

    function updateCount() {
        if (!resultCount) {
            return;
        }
        if (state.loaded > 0) {
            resultCount.textContent = state.loaded + (state.loaded === 1 ? ' deal loaded' : ' deals loaded');
        } else {
            resultCount.textContent = 'Fresh deals are on the way';
        }
    }

    function updateLoadMore() {
        if (!loadMoreWrap) {
            return;
        }
        var show = state.hasMore && state.loaded > 0;
        loadMoreWrap.classList.toggle('is-hidden', !show);
        if (grid) {
            grid.dataset.offset = String(state.offset);
            grid.dataset.hasMore = state.hasMore ? '1' : '0';
        }
    }

    function updateUrl() {
        if (!grid || !window.history || !history.replaceState) {
            return;
        }
        var params = new URLSearchParams();
        if (state.q.trim() !== '') {
            params.set('q', state.q.trim());
        }
        if (state.category !== '') {
            params.set('category', state.category);
        }
        if (state.platform !== '') {
            params.set('platform', state.platform);
        }
        var query = params.toString();
        var next = window.location.pathname + (query ? '?' + query : '');
        history.replaceState(null, '', next);
    }

    function applyFilterUI() {
        $all('[data-category]').forEach(function (chip) {
            chip.classList.toggle('is-active', (chip.dataset.category || '') === state.category);
        });
        $all('[data-platform]').forEach(function (chip) {
            chip.classList.toggle('is-active', (chip.dataset.platform || '') === state.platform);
        });
        if (searchInput && searchInput.value !== state.q) {
            searchInput.value = state.q;
        }
    }

    function showEmpty(title, text) {
        if (!emptyState) {
            return;
        }
        if (emptyTitle) {
            emptyTitle.textContent = title;
        }
        if (emptyText) {
            emptyText.textContent = text;
        }
        emptyState.classList.add('is-visible');
    }

    function hideEmpty() {
        if (emptyState) {
            emptyState.classList.remove('is-visible');
        }
    }

    function buildQuery(offset) {
        var params = new URLSearchParams();
        params.set('select', '*');
        params.set('is_active', 'eq.true');
        params.set('order', 'created_at.desc');
        params.set('limit', String(pageSize));
        params.set('offset', String(offset));

        if (state.category !== '') {
            params.set('category', 'eq.' + state.category);
        }
        if (state.platform !== '') {
            params.set('source_platform', 'eq.' + state.platform);
        }
        var search = sanitizeSearch(state.q);
        if (search !== '') {
            var pattern = '*' + search + '*';
            params.set('or', '(title.ilike.' + pattern + ',category.ilike.' + pattern + ',source_platform.ilike.' + pattern + ')');
        }
        return params.toString().replace(/\+/g, '%20');
    }

    function fetchProducts(offset) {
        if (!cfg.supabaseUrl || !cfg.supabaseKey) {
            return Promise.reject(new Error('The store is still being configured.'));
        }
        if (state.controller) {
            state.controller.abort();
        }
        var controller = new AbortController();
        state.controller = controller;
        var timeout = window.setTimeout(function () {
            controller.abort();
        }, 15000);

        var url = String(cfg.supabaseUrl).replace(/\/+$/, '') + '/rest/v1/'
            + encodeURIComponent(cfg.table || 'products') + '?' + buildQuery(offset);

        return fetch(url, {
            method: 'GET',
            headers: {
                apikey: cfg.supabaseKey,
                Authorization: 'Bearer ' + cfg.supabaseKey,
                Accept: 'application/json'
            },
            signal: controller.signal,
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) {
                return response.text().then(function (body) {
                    throw new Error('Store request failed (' + response.status + '). ' + (body || '').slice(0, 120));
                });
            }
            return response.json();
        }).then(function (data) {
            if (!Array.isArray(data)) {
                throw new Error('Unexpected store response.');
            }
            return data;
        }).finally(function () {
            window.clearTimeout(timeout);
        });
    }

    function loadProducts(reset) {
        if (!grid || state.loading) {
            return;
        }
        state.loading = true;
        var requestId = ++state.requestId;

        if (reset) {
            state.offset = 0;
            state.loaded = 0;
            state.hasMore = true;
            grid.innerHTML = skeletonMarkup(10);
            hideEmpty();
            if (loadMoreWrap) {
                loadMoreWrap.classList.add('is-hidden');
            }
        } else if (loadMoreBtn) {
            loadMoreBtn.classList.add('is-loading');
        }

        fetchProducts(state.offset).then(function (data) {
            if (requestId !== state.requestId) {
                return;
            }
            if (reset) {
                grid.innerHTML = '';
            }
            var html = data.map(productCard).join('');
            grid.insertAdjacentHTML('beforeend', html);

            state.offset += data.length;
            state.loaded += data.length;
            state.hasMore = data.length >= pageSize;

            if (state.loaded === 0) {
                showEmpty('No deals found', 'Try a different search or clear the filters to see every deal.');
            } else {
                hideEmpty();
            }
            updateCount();
            updateLoadMore();
            updateUrl();
        }).catch(function (error) {
            if (requestId !== state.requestId) {
                return;
            }
            if (reset) {
                grid.innerHTML = '';
            }
            var message = error && error.name === 'AbortError'
                ? 'The store took too long to respond. Please try again.'
                : (error && error.message ? error.message : 'Could not load deals.');
            showToast(message);
            if (state.loaded === 0) {
                showEmpty('Could not load deals', 'Check your connection and try again.');
            }
            updateCount();
            updateLoadMore();
        }).finally(function () {
            if (requestId === state.requestId) {
                state.loading = false;
                if (loadMoreBtn) {
                    loadMoreBtn.classList.remove('is-loading');
                }
            }
        });
    }

    function resetAndLoad() {
        applyFilterUI();
        state.requestId += 1;
        if (state.controller) {
            state.controller.abort();
            state.controller = null;
        }
        state.loading = false;
        window.clearTimeout(resetAndLoad.timer);
        resetAndLoad.timer = window.setTimeout(function () {
            loadProducts(true);
        }, 20);
    }

    /* ---------- Index page: search, filters, pagination ---------- */
    if (grid) {
        if (searchForm) {
            searchForm.addEventListener('submit', function (event) {
                event.preventDefault();
                state.q = searchInput ? searchInput.value.trim() : '';
                resetAndLoad();
            });
        }

        if (searchInput) {
            var searchTimer = null;
            searchInput.addEventListener('input', function () {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function () {
                    state.q = searchInput.value.trim();
                    resetAndLoad();
                }, 450);
            });
        }

        $all('[data-category]').forEach(function (chip) {
            chip.addEventListener('click', function (event) {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                event.preventDefault();
                state.category = chip.dataset.category || '';
                resetAndLoad();
            });
        });

        $all('[data-platform]').forEach(function (chip) {
            chip.addEventListener('click', function (event) {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                event.preventDefault();
                state.platform = chip.dataset.platform || '';
                resetAndLoad();
            });
        });

        $all('[data-clear-filters]').forEach(function (button) {
            button.addEventListener('click', function () {
                state.q = '';
                state.category = '';
                state.platform = '';
                if (searchInput) {
                    searchInput.value = '';
                }
                resetAndLoad();
            });
        });

        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function () {
                loadProducts(false);
            });
        }

        if (sentinel && 'IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                if (entries[0] && entries[0].isIntersecting && state.hasMore && !state.loading && state.loaded > 0) {
                    loadProducts(false);
                }
            }, { rootMargin: '700px 0px' });
            observer.observe(sentinel);
        }

        applyFilterUI();
        updateCount();
        updateLoadMore();
    }

    /* ---------- Product page: gallery ---------- */
    var galleryRoot = $('[data-gallery]');
    var mainImage = document.getElementById('mainProductImage');
    if (galleryRoot && mainImage) {
        $all('[data-gallery-thumb]', galleryRoot).forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                var next = thumb.dataset.src || '';
                if (!safeImageUrl(next)) {
                    return;
                }
                mainImage.src = next;
                $all('[data-gallery-thumb]', galleryRoot).forEach(function (item) {
                    var isActive = item === thumb;
                    item.classList.toggle('is-active', isActive);
                    item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
            });
        });
    }

    /* ---------- Broken images fall back gracefully ---------- */
    document.addEventListener('error', function (event) {
        var image = event.target;
        if (!image || !image.classList || !image.classList.contains('js-product-img')) {
            return;
        }
        var media = image.closest('.product-media');
        if (media) {
            image.remove();
            media.classList.add('is-fallback');
            media.insertAdjacentHTML('beforeend', placeholderMarkup());
            return;
        }
        var gallery = image.closest('.gallery-main');
        if (gallery) {
            image.remove();
            gallery.insertAdjacentHTML('beforeend', '<div class="gallery-placeholder" aria-hidden="true">'
                + '<svg viewBox="0 0 24 24" width="52" height="52" fill="none" stroke="currentColor" stroke-width="1.4">'
                + '<path d="M6 8h12l-1 12H7L6 8Z"></path><path d="M9 8a3 3 0 0 1 6 0"></path></svg>'
                + '<span>Image coming soon</span></div>');
        }
    }, true);

    /* ---------- Copy / native share ---------- */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                var copied = document.execCommand('copy');
                textarea.remove();
                if (copied) {
                    resolve();
                } else {
                    reject(new Error('Copy failed'));
                }
            } catch (error) {
                textarea.remove();
                reject(error);
            }
        });
    }

    $all('[data-copy-link]').forEach(function (button) {
        button.addEventListener('click', function () {
            copyText(window.location.href).then(function () {
                showToast('Link copied');
            }).catch(function () {
                showToast('Could not copy the link');
            });
        });
    });

    $all('[data-share-button]').forEach(function (button) {
        button.addEventListener('click', function () {
            var shareData = {
                title: document.title,
                text: document.title,
                url: window.location.href
            };
            if (navigator.share) {
                navigator.share(shareData).catch(function () {
                    /* The user cancelled the share sheet. */
                });
                return;
            }
            copyText(window.location.href).then(function () {
                showToast('Link copied — ready to share');
            }).catch(function () {
                showToast('Could not copy the link');
            });
        });
    });

    /* ---------- Small UX niceties ---------- */
    if (header) {
        var onScroll = function () {
            header.classList.toggle('is-scrolled', window.scrollY > 8);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    document.addEventListener('keydown', function (event) {
        if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }
        var tag = (event.target && event.target.tagName ? event.target.tagName : '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || (event.target && event.target.isContentEditable)) {
            return;
        }
        if (searchInput) {
            event.preventDefault();
            searchInput.focus();
        }
    });

    if (grid && !cfg.supabaseUrl) {
        showToast('Store setup is incomplete.');
    }

    /* ---------- 3D Effects & Modern Interactions ---------- */
    
    // Scroll Reveal Observer
    function initScrollReveal() {
        var revealElements = document.querySelectorAll('.reveal');
        if (!revealElements.length || !('IntersectionObserver' in window)) return;
        
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    // Stagger animation for siblings
                    var siblings = Array.prototype.slice.call(entry.target.parentNode.querySelectorAll('.reveal'));
                    siblings.forEach(function(sibling, index) {
                        if (sibling === entry.target) return;
                        setTimeout(function() {
                            sibling.classList.add('visible');
                        }, index * 60);
                    });
                    observer.unobserve(entry.target);
                }
            });
        }, { 
            threshold: 0.1, 
            rootMargin: '50px 0px -50px 0px' 
        });
        
        revealElements.forEach(function(el) { observer.observe(el); });
    }
    
    // 3D Tilt Effect for Product Cards
    function init3DTilt() {
        var cards = document.querySelectorAll('.product-card');
        if (!cards.length) return;
        
        var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReduced) return;
        
        cards.forEach(function(card) {
            var media = card.querySelector('.product-media');
            var image = card.querySelector('.product-image');
            var badges = card.querySelectorAll('.discount-pill, .platform-badge');
            var body = card.querySelector('.product-body');
            var title = card.querySelector('.product-title');
            var price = card.querySelector('.product-price');
            var buyBtn = card.querySelector('.buy-button');
            var badgesArray = Array.prototype.slice.call(badges);
            
            var bounds = null;
            var rafId = null;
            var currentX = 0;
            var currentY = 0;
            var targetX = 0;
            var targetY = 0;
            
            function updateTransform() {
                var dx = targetX - currentX;
                var dy = targetY - currentY;
                currentX += dx * 0.15;
                currentY += dy * 0.15;
                
                var rotateY = (currentX / bounds.width) * 20 - 10; // -10 to 10 deg
                var rotateX = -(currentY / bounds.height) * 20 + 10; // 10 to -10 deg
                
                card.style.transform = 'perspective(1000px) translateZ(30px) translateY(-8px) rotateX(' + rotateX + 'deg) rotateY(' + rotateY + 'deg)';
                
                // Parallax layers
                if (media) media.style.transform = 'translateZ(25px) scale(1.05) rotateZ(' + (rotateY * 0.1) + 'deg)';
                if (image) image.style.transform = 'translateZ(25px) scale(1.05) rotateZ(' + (rotateY * 0.1) + 'deg)';
                
                badgesArray.forEach(function(badge, i) {
                    badge.style.transform = 'translateZ(' + (30 + i * 5) + 'px) translateY(-2px) rotateY(' + (rotateY * 0.3) + 'deg) rotateX(' + (-rotateX * 0.3) + 'deg)';
                });
                
                if (body) body.style.transform = 'translateZ(15px) rotateY(' + (rotateY * 0.2) + 'deg) rotateX(' + (-rotateX * 0.2) + 'deg)';
                if (title) title.style.transform = 'translateZ(10px) rotateY(' + (rotateY * 0.1) + 'deg)';
                if (price) price.style.transform = 'translateZ(25px) rotateY(' + (rotateY * 0.1) + 'deg)';
                
                rafId = requestAnimationFrame(updateTransform);
            }
            
            function onMouseMove(e) {
                if (!bounds) bounds = card.getBoundingClientRect();
                var rect = card.getBoundingClientRect();
                targetX = e.clientX - rect.left;
                targetY = e.clientY - rect.top;
            }
            
            function onMouseEnter(e) {
                bounds = card.getBoundingClientRect();
                card.style.transition = 'none';
                if (!rafId) rafId = requestAnimationFrame(updateTransform);
            }
            
            function onMouseLeave() {
                card.style.transition = 'transform 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94), box-shadow 0.3s ease';
                card.style.transform = 'perspective(1000px) translateZ(0) translateY(0) rotateX(0) rotateY(0)';
                
                if (media) media.style.transition = 'transform 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                if (image) image.style.transition = 'transform 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                
                badgesArray.forEach(function(badge) {
                    badge.style.transition = 'transform 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                    badge.style.transform = '';
                });
                
                if (body) body.style.transition = 'transform 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                if (title) title.style.transition = 'transform 0.3s ease';
                if (price) price.style.transition = 'transform 0.3s ease';
            }
            
            card.addEventListener('mousemove', onMouseMove);
            card.addEventListener('mouseenter', onMouseEnter);
            card.addEventListener('mouseleave', onMouseLeave);
            
            // Touch support for mobile
            card.addEventListener('touchstart', function(e) {
                bounds = card.getBoundingClientRect();
                var touch = e.touches[0];
                targetX = touch.clientX - bounds.left;
                targetY = touch.clientY - bounds.top;
                if (!rafId) rafId = requestAnimationFrame(updateTransform);
            }, { passive: true });
            
            card.addEventListener('touchmove', function(e) {
                if (!bounds) return;
                var touch = e.touches[0];
                targetX = touch.clientX - bounds.left;
                targetY = touch.clientY - bounds.top;
            }, { passive: true });
            
            card.addEventListener('touchend', function() {
                card.style.transition = 'transform 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94), box-shadow 0.3s ease';
                card.style.transform = 'perspective(1000px) translateZ(0) translateY(0) rotateX(0) rotateY(0)';
                if (media) media.style.transform = '';
                if (image) image.style.transform = '';
                badgesArray.forEach(function(badge) { badge.style.transform = ''; });
                if (body) body.style.transform = '';
                if (title) title.style.transform = '';
                if (price) price.style.transform = '';
            });
        });
    }
    
    // Magnetic Button Effect
    function initMagneticButtons() {
        var magneticButtons = document.querySelectorAll('.buy-button, .load-more-btn, .search-submit, .platform-pill, .category-chip, .brand-mark');
        if (!magneticButtons.length) return;
        
        var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReduced) return;
        
        magneticButtons.forEach(function(btn) {
            var rafId = null;
            var currentX = 0;
            var currentY = 0;
            var targetX = 0;
            var targetY = 0;
            var bounds = null;
            
            function updateMagnetic() {
                var dx = targetX - currentX;
                var dy = targetY - currentY;
                currentX += dx * 0.2;
                currentY += dy * 0.2;
                
                var moveX = currentX * 0.3;
                var moveY = currentY * 0.3;
                
                btn.style.transform = 'translateZ(10px) translate(' + moveX + 'px, ' + moveY + 'px)';
                
                rafId = requestAnimationFrame(updateMagnetic);
            }
            
            function onMouseMove(e) {
                if (!bounds) bounds = btn.getBoundingClientRect();
                var rect = btn.getBoundingClientRect();
                targetX = e.clientX - rect.left - rect.width / 2;
                targetY = e.clientY - rect.top - rect.height / 2;
                
                if (!rafId) rafId = requestAnimationFrame(function animate() {
                    currentX += (targetX - currentX) * 0.2;
                    currentY += (targetY - currentY) * 0.2;
                    btn.style.transform = 'translateZ(10px) translate(' + (currentX * 0.3) + 'px, ' + (currentY * 0.3) + 'px)';
                    rafId = requestAnimationFrame(animate);
                });
            }
            
            function onMouseEnter(e) {
                bounds = btn.getBoundingClientRect();
                btn.style.transition = 'none';
            }
            
            function onMouseLeave() {
                btn.style.transition = 'transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                btn.style.transform = 'translateZ(0) translate(0, 0)';
            }
            
            btn.addEventListener('mousemove', onMouseMove);
            btn.addEventListener('mouseenter', onMouseEnter);
            btn.addEventListener('mouseleave', onMouseLeave);
        });
    }
    
    // Parallax Scroll Effect
    function initParallaxScroll() {
        var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReduced) return;
        
        var parallaxElements = document.querySelectorAll('.hero-banner, .hero-copy h1, .hero-deal-card, .brand-mark');
        if (!parallaxElements.length) return;
        
        var ticking = false;
        
        function updateParallax() {
            var scrollY = window.scrollY;
            
            parallaxElements.forEach(function(el) {
                var speed = el.dataset.parallaxSpeed || 0.3;
                var yPos = -(scrollY * speed);
                var rotate = scrollY * 0.02;
                
                if (el.classList.contains('hero-banner')) {
                    el.style.transform = 'translateY(' + (scrollY * 0.2) + 'px) scale(' + (1 + scrollY * 0.0001) + ')';
                } else if (el.classList.contains('hero-copy') || el.tagName === 'H1') {
                    el.style.transform = 'translateZ(30px) translateY(' + (scrollY * 0.15) + 'px)';
                } else if (el.classList.contains('hero-deal-card')) {
                    el.style.transform = 'translateZ(20px) rotateY(-5deg) translateY(' + (scrollY * 0.1) + 'px)';
                } else if (el.classList.contains('brand-mark')) {
                    el.style.transform = 'translateZ(0) translateY(' + (scrollY * 0.05) + 'px) rotate(' + (scrollY * 0.02) + 'deg)';
                }
            });
            
            ticking = false;
        }
        
        function onScroll() {
            if (!ticking) {
                window.requestAnimationFrame(updateParallax);
                ticking = true;
            }
        }
        
        window.addEventListener('scroll', onScroll, { passive: true });
    }
    
    // Smooth Page Transitions
    function initPageTransitions() {
        var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReduced) return;
        
        var links = document.querySelectorAll('a[href]:not([target="_blank"]):not([href^="#"]):not([href^="javascript:"]):not([href^="mailto:"]):not([href^="tel:"])');
        
        links.forEach(function(link) {
            link.addEventListener('click', function(e) {
                var href = link.getAttribute('href');
                if (!href || href.startsWith('http') || href.startsWith('//')) return;
                
                e.preventDefault();
                
                // Create transition overlay
                var overlay = document.createElement('div');
                overlay.className = 'page-transition';
                document.body.appendChild(overlay);
                
                // Trigger animation
                requestAnimationFrame(function() {
                    overlay.classList.add('active');
                });
                
                setTimeout(function() {
                    window.location.href = href;
                }, 300);
            });
        });
    }
    
    // Floating Animation for Elements
    function initFloatingElements() {
        var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReduced) return;
        
        var floatingElements = document.querySelectorAll('.brand-mark, .indian-flag, .made-in-india, .discount-pill, .platform-badge');
        
        floatingElements.forEach(function(el, index) {
            var delay = index * 200;
            var duration = 3000 + index * 500;
            
            el.style.animation = 'float-slow ' + duration + 'ms ease-in-out infinite';
            el.style.animationDelay = delay + 'ms';
        });
    }
    
    // Initialize all 3D effects
    document.addEventListener('DOMContentLoaded', function() {
        initScrollReveal();
        init3DTilt();
        initMagneticButtons();
        initParallaxScroll();
        initPageTransitions();
        initFloatingElements();
        
        // Add reveal classes to existing elements
        var revealTargets = document.querySelectorAll(
            '.product-card, .category-chip, .platform-pill, .hero-banner, .hero-copy, .hero-deal-card, ' +
            '.section-heading, .load-more-wrap, .footer-brand, .footer-links, .footer-disclosure, ' +
            '.search-form, .brand, .header-badge, .top-strip, .site-header'
        );
        
        revealTargets.forEach(function(el, index) {
            if (!el.classList.contains('reveal')) {
                el.classList.add('reveal');
                el.style.transitionDelay = (index * 60) + 'ms';
            }
        });
        
        // Trigger initial reveal check
        setTimeout(function() {
            var event = new Event('scroll');
            window.dispatchEvent(event);
        }, 100);
    });
})();

