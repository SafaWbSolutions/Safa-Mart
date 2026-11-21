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
        sort: current.sort || '',
        minPrice: Number(current.minPrice) || 0,
        maxPrice: Number(current.maxPrice) || 0,
        minDiscount: Number(current.minDiscount) || 0,
        brand: current.brand || '',
        minRating: Number(current.minRating) || 0,
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

    function cleanProductTitle(value) {
        var original = String(value == null ? '' : value).replace(/\s+/g, ' ').trim();
        if (!original) {
            return '';
        }
        var cleaned = original
            .replace(/^(?:Amazon(?:\.in)?|Flipkart|Myntra|Ajio|Shopsy|DigiHaat)\s*[:\-–—|]\s*/i, '')
            .replace(/\s*[-–—|]\s*Buy\b.*\bOnline\b.*$/i, '')
            .replace(/\s+Online at Best Prices?\b.*$/i, '')
            .replace(/\s*\|\s*(?:Flipkart|Myntra|Amazon(?:\.in)?|Ajio|Shopsy|DigiHaat|Tata\s*CLiQ|Nykaa|Meesho)\b.*$/i, '')
            .replace(/^Buy\s+(?=[A-Z0-9])/, '')
            .replace(/^[\s\-–—|,]+|[\s\-–—|,]+$/g, '')
            .trim();
        return cleaned || original;
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

    function compactCount(value) {
        var number = Number(value) || 0;
        if (number >= 1000000) {
            return (number / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        }
        if (number >= 1000) {
            return (number / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
        }
        return String(number);
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
        var title = cleanProductTitle(product.title) || 'Product';
        var price = Number(product.price) || 0;
        var mrp = Number(product.mrp) || 0;
        var discount = Number(product.discount_percent) || calculateDiscount(mrp, price);
        var platform = String(product.source_platform || 'Store');
        if (platform === 'Other' || platform === '') {
            platform = 'Store';
        }
        var image = safeImageUrl(product.thumbnail_url);
        var slug = String(product.slug || '');
        var brand = String(product.brand || '').trim();
        var rating = Number(product.rating) || 0;
        var ratingCount = Number(product.rating_count) || 0;
        var brandHtml = brand ? '<span class="product-brand">' + escapeHtml(brand) + '</span>' : '';
        var ratingHtml = rating > 0
            ? '<div class="product-meta-row"><span class="product-rating"><span class="rating-star">★</span> '
                + rating.toFixed(1)
                + (ratingCount > 0 ? ' <span class="rating-count">(' + compactCount(ratingCount) + ')</span>' : '')
                + '</span></div>'
            : '';
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
            + brandHtml
            + '<a class="product-title" href="' + escapeHtml(productUrl) + '">' + escapeHtml(title) + '</a>'
            + ratingHtml
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
        if (state.sort !== '') {
            params.set('sort', state.sort);
        }
        if (state.brand !== '') {
            params.set('brand', state.brand);
        }
        if (state.minPrice > 0) {
            params.set('min_price', String(state.minPrice));
        }
        if (state.maxPrice > 0) {
            params.set('max_price', String(state.maxPrice));
        }
        if (state.minDiscount > 0) {
            params.set('min_discount', String(state.minDiscount));
        }
        if (state.minRating > 0) {
            params.set('min_rating', String(state.minRating));
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
        var order = 'created_at.desc';
        if (state.sort === 'price_asc') {
            order = 'price.asc';
        } else if (state.sort === 'price_desc') {
            order = 'price.desc';
        } else if (state.sort === 'discount_desc') {
            order = 'discount_percent.desc';
        } else if (state.sort === 'rating_desc') {
            order = 'rating.desc';
        }
        params.set('order', order);
        params.set('limit', String(pageSize));
        params.set('offset', String(offset));

        if (state.category !== '') {
            params.set('category', 'eq.' + state.category);
        }
        if (state.platform !== '') {
            params.set('source_platform', 'eq.' + state.platform);
        }
        if (state.brand !== '') {
            params.set('brand', 'eq.' + state.brand);
        }
        if (state.minPrice > 0) {
            params.append('price', 'gte.' + state.minPrice);
        }
        if (state.maxPrice > 0) {
            params.append('price', 'lte.' + state.maxPrice);
        }
        if (state.minDiscount > 0) {
            params.set('discount_percent', 'gte.' + state.minDiscount);
        }
        if (state.minRating > 0) {
            params.set('rating', 'gte.' + state.minRating);
        }
        var search = sanitizeSearch(state.q);
        if (search !== '') {
            var pattern = '*' + search + '*';
            params.set('or', '(title.ilike.' + pattern + ',category.ilike.' + pattern + ',source_platform.ilike.' + pattern + ',brand.ilike.' + pattern + ')');
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
})();
