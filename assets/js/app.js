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
    var productsSection = document.querySelector('.products-section');
    var dealsHeading = document.getElementById('dealsHeading');
    var filterPanel = document.querySelector('.filter-panel');
    var pageSize = Number(cfg.pageSize) || 24;

    var state = {
        q: current.q || '',
        searchTerms: Array.isArray(current.searchTerms) ? current.searchTerms.slice(0, 7) : [],
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

    /* ---------- Anonymous behaviour (stored on this device only) ---------- */
    var BEHAVIOUR_KEY = 'safa_behaviour_v1';

    function behaviourLoad() {
        try {
            var raw = window.localStorage.getItem(BEHAVIOUR_KEY);
            var data = raw ? JSON.parse(raw) : null;
            if (!data || typeof data !== 'object') {
                data = {};
            }
            return {
                views: Array.isArray(data.views) ? data.views.slice(0, 10) : [],
                queries: Array.isArray(data.queries) ? data.queries.slice(0, 6) : [],
                interests: Array.isArray(data.interests) ? data.interests.slice(0, 10) : []
            };
        } catch (error) {
            return { views: [], queries: [], interests: [] };
        }
    }

    function behaviourSave(data) {
        try {
            window.localStorage.setItem(BEHAVIOUR_KEY, JSON.stringify(data));
        } catch (error) {
            /* storage blocked — recommendations fall back to server defaults */
        }
    }

    function behaviourSignals() {
        var data = behaviourLoad();
        return data.views.length + data.queries.length + data.interests.length;
    }

    function behaviourView(product) {
        var id = Number(product && product.id);
        if (!id) {
            return;
        }
        var data = behaviourLoad();
        data.views = data.views.filter(function (view) {
            return String(view.id) !== String(id);
        });
        data.views.unshift({
            id: id,
            slug: String(product.slug || ''),
            title: String(product.title || '').slice(0, 120),
            category: String(product.category || ''),
            platform: String(product.platform || ''),
            brand: String(product.brand || ''),
            price: Number(product.price) || 0
        });
        data.views = data.views.slice(0, 10);
        behaviourSave(data);
    }

    function behaviourQuery(query) {
        query = String(query || '').trim().slice(0, 60);
        if (!query) {
            return;
        }
        var data = behaviourLoad();
        data.queries = data.queries.filter(function (item) {
            return item.toLowerCase() !== query.toLowerCase();
        });
        data.queries.unshift(query);
        data.queries = data.queries.slice(0, 6);
        behaviourSave(data);
    }

    function behaviourInterest(value) {
        value = String(value || '').trim().slice(0, 40);
        if (!value) {
            return;
        }
        var data = behaviourLoad();
        data.interests = data.interests.filter(function (item) {
            return item.toLowerCase() !== value.toLowerCase();
        });
        data.interests.unshift(value);
        data.interests = data.interests.slice(0, 10);
        behaviourSave(data);
    }

    function fetchRecommendations(context, done) {
        var data = behaviourLoad();
        fetch('api/recommend.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                current: context.current || null,
                views: data.views,
                queries: data.queries.concat(data.interests),
                exclude: context.exclude || []
            })
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            done(payload && payload.ok ? payload : null);
        }).catch(function () {
            done(null);
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
            .replace(/\s+Price in India\b.*$/i, '')
            .replace(/\s*\|\s*(?:Flipkart|Myntra|Amazon(?:\.in)?|Ajio|Shopsy|DigiHaat|Tata\s*CLiQ|Nykaa|Meesho)\b.*$/i, '')
            .replace(/\s+(?:online\s+)?at\s+(?:Flipkart|Myntra|Amazon(?:\.in)?|Ajio|Shopsy|DigiHaat|Tata\s*CLiQ|Nykaa|Meesho)(?:\.(?:com|in))?\s*$/i, '')
            .replace(/\s*[-–—]\s*Apparel for (?:Men|Women|Kids|Boys|Girls)[.!]?\s*$/i, '')
            .replace(/^Buy\s+(?=[A-Z0-9])/i, '')
            .replace(/^[\s\-–—|,]+|[\s\-–—|,]+$/g, '')
            .trim();
        return cleaned || original;
    }

    function formatINR(value) {
        var number = Number(value);
        if (!isFinite(number) || number < 0) {
            number = 0;
        }
        return '₹' + Math.round(number).toLocaleString('en-IN', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
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
        var featured = (cfg.featuredBadges || {})[slug] || '';
        var featuredHtml = featured
            ? '<span class="card-featured">⭐ ' + escapeHtml(String(featured)) + '</span>'
            : '';
        var mrpHtml = (mrp > price && price > 0)
            ? '<span class="price-mrp">' + formatINR(mrp) + '</span>'
            : '';

        return '<article class="product-card" data-product-id="' + id + '">'
            + '<a class="product-media" href="' + escapeHtml(productUrl) + '" aria-label="' + escapeHtml(title) + '">'
            + media
            + discountHtml
            + featuredHtml
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

    function hasActiveFilters() {
        return state.q.trim() !== ''
            || state.category !== ''
            || state.platform !== ''
            || state.sort !== ''
            || state.brand !== ''
            || state.minPrice > 0
            || state.maxPrice > 0
            || state.minDiscount > 0
            || state.minRating > 0;
    }

    function filterLabel() {
        if (state.q.trim() !== '') {
            return 'Results for “' + state.q.trim() + '”';
        }
        if (state.category !== '') {
            return state.category + ' deals';
        }
        if (state.platform !== '') {
            return state.platform + ' deals';
        }
        if (state.brand !== '') {
            return state.brand + ' deals';
        }
        if (state.sort === 'discount_desc') {
            return 'Biggest discounts';
        }
        if (state.sort === 'price_asc') {
            return 'Lowest prices first';
        }
        if (state.sort === 'price_desc') {
            return 'Highest prices first';
        }
        if (state.sort === 'rating_desc') {
            return 'Top rated deals';
        }
        return 'Handpicked deals';
    }

    // Mirrors the server layout: with any filter active the hero, rails and
    // browse links are hidden so the results are the only thing on screen.
    function syncFilteredLayout() {
        var active = hasActiveFilters();
        document.body.classList.toggle('has-active-filter', active);
        $all('[data-clear-filters]').forEach(function (button) {
            button.hidden = !active;
        });
        if (filterPanel && active) {
            filterPanel.open = true;
        }
        if (dealsHeading) {
            dealsHeading.textContent = filterLabel();
        }
    }

    function setResultsLoading(active, chip) {
        $all('.platform-pill.is-loading, .category-chip.is-loading').forEach(function (item) {
            item.classList.remove('is-loading');
            item.removeAttribute('aria-busy');
        });
        if (productsSection) {
            productsSection.classList.toggle('is-loading', active);
        }
        if (!active) {
            return;
        }
        if (grid) {
            // Clear the old list right away so the tapped filter never looks
            // like a no-op while the request is still starting.
            grid.innerHTML = skeletonMarkup(8);
            hideEmpty();
        }
        if (chip) {
            chip.classList.add('is-loading');
            chip.setAttribute('aria-busy', 'true');
        }
        if (resultCount) {
            resultCount.textContent = 'Loading ' + filterLabel().toLowerCase() + '…';
        }
    }

    function scrollToResults() {
        if (!productsSection) {
            return;
        }
        var headerHeight = header ? header.getBoundingClientRect().height : 0;
        var rect = productsSection.getBoundingClientRect();
        // Already comfortably in view: do not yank the page around.
        if (rect.top >= headerHeight - 8 && rect.top <= window.innerHeight * 0.6) {
            return;
        }
        var reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var currentTop = window.pageYOffset || document.documentElement.scrollTop || 0;
        var target = Math.max(0, rect.top + currentTop - headerHeight - 14);
        if (reduceMotion) {
            window.scrollTo(0, target);
        } else {
            window.scrollTo({ top: target, behavior: 'smooth' });
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
        var order = cfg.defaultOrder || 'created_at.desc';
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
        if (state.searchTerms && state.searchTerms.length > 1) {
            var conditions = [];
            state.searchTerms.forEach(function (term) {
                var cleanTerm = sanitizeSearch(term);
                if (!cleanTerm) {
                    return;
                }
                var cleanPattern = '*' + cleanTerm + '*';
                conditions.push(
                    'title.ilike.' + cleanPattern,
                    'category.ilike.' + cleanPattern,
                    'source_platform.ilike.' + cleanPattern,
                    'brand.ilike.' + cleanPattern
                );
            });
            if (conditions.length) {
                params.set('or', '(' + conditions.join(',') + ')');
            }
        } else {
            var search = sanitizeSearch(state.q);
            if (search !== '') {
                var pattern = '*' + search + '*';
                params.set('or', '(title.ilike.' + pattern + ',category.ilike.' + pattern + ',source_platform.ilike.' + pattern + ',brand.ilike.' + pattern + ')');
            }
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
            // Highlighted products float to the top of the first page, so the
            // same product can arrive again later. Never show a card twice.
            var seenIds = {};
            Array.prototype.forEach.call(grid.querySelectorAll('.product-card'), function (card) {
                seenIds[String(card.getAttribute('data-product-id') || '')] = true;
            });
            var fresh = data.filter(function (product) {
                var id = String(Number(product && product.id) || 0);
                if (id === '0' || seenIds[id]) {
                    return false;
                }
                seenIds[id] = true;
                return true;
            });
            var html = fresh.map(productCard).join('');
            grid.insertAdjacentHTML('beforeend', html);

            state.offset += data.length;
            state.loaded += fresh.length;
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
                setResultsLoading(false);
                if (loadMoreBtn) {
                    loadMoreBtn.classList.remove('is-loading');
                }
            }
        });
    }

    function resetAndLoad(options) {
        options = options || {};
        var activeFilters = hasActiveFilters();
        applyFilterUI();
        syncFilteredLayout();
        state.requestId += 1;
        if (state.controller) {
            state.controller.abort();
            state.controller = null;
        }
        state.loading = false;
        state.offset = 0;
        state.loaded = 0;
        state.hasMore = true;
        window.clearTimeout(resetAndLoad.timer);

        // Immediate feedback: skeletons, pill spinner and a progress bar appear
        // before the network request starts, so a tap never feels ignored.
        setResultsLoading(true, options.chip || null);
        if (activeFilters && options.scroll !== false) {
            window.requestAnimationFrame(scrollToResults);
        }

        // Coalesce rapid taps into one request instead of aborting each other.
        resetAndLoad.timer = window.setTimeout(function () {
            loadProducts(true);
        }, activeFilters ? 120 : 20);
    }

    /* ---------- Index page: search, filters, pagination ---------- */
    if (grid) {
        if (searchForm) {
            searchForm.addEventListener('submit', function () {
                if (searchInput) {
                    behaviourQuery(searchInput.value);
                }
                // Normal form navigation: the server adds AI query expansion,
                // so results stay consistent for the whole search session.
            });
        }

        if (searchInput) {
            var searchTimer = null;
            searchInput.addEventListener('input', function () {
                state.searchTerms = [];
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function () {
                    state.q = searchInput.value.trim();
                    resetAndLoad();
                }, 450);
            });
            searchInput.addEventListener('blur', function () {
                behaviourQuery(searchInput.value);
            });
        }

        $all('[data-category]').forEach(function (chip) {
            chip.addEventListener('click', function (event) {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                event.preventDefault();
                var nextCategory = chip.dataset.category || '';
                if (nextCategory === state.category) {
                    scrollToResults();
                    return;
                }
                state.category = nextCategory;
                state.searchTerms = [];
                if (state.category) {
                    behaviourInterest(state.category);
                }
                resetAndLoad({ chip: chip });
            });
        });

        $all('[data-platform]').forEach(function (chip) {
            chip.addEventListener('click', function (event) {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                event.preventDefault();
                var nextPlatform = chip.dataset.platform || '';
                if (nextPlatform === state.platform) {
                    // Same tab tapped again: just bring the results into view.
                    scrollToResults();
                    return;
                }
                state.platform = nextPlatform;
                state.searchTerms = [];
                if (state.platform) {
                    behaviourInterest(state.platform);
                }
                resetAndLoad({ chip: chip });
            });
        });

        $all('[data-clear-filters]').forEach(function (button) {
            button.addEventListener('click', function () {
                state.q = '';
                state.searchTerms = [];
                state.category = '';
                state.platform = '';
                state.sort = '';
                state.brand = '';
                state.minPrice = 0;
                state.maxPrice = 0;
                state.minDiscount = 0;
                state.minRating = 0;
                if (searchInput) {
                    searchInput.value = '';
                }
                resetAndLoad({ scroll: false });
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
        syncFilteredLayout();
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

    /* ---------- Horizontal offer rails ---------- */
    $all('[data-rail]').forEach(function (rail) {
        var shell = rail.parentElement;
        var prev = shell ? shell.querySelector('[data-rail-prev]') : null;
        var next = shell ? shell.querySelector('[data-rail-next]') : null;
        if (!prev && !next) {
            return;
        }
        var step = function () {
            return Math.max(240, Math.round(rail.clientWidth * 0.8));
        };
        var sync = function () {
            var edge = rail.scrollWidth - rail.clientWidth - 2;
            if (prev) {
                prev.disabled = rail.scrollLeft <= 2;
            }
            if (next) {
                next.disabled = rail.scrollLeft >= edge;
            }
        };
        if (prev) {
            prev.addEventListener('click', function () {
                rail.scrollBy({ left: -step(), behavior: 'smooth' });
            });
        }
        if (next) {
            next.addEventListener('click', function () {
                rail.scrollBy({ left: step(), behavior: 'smooth' });
            });
        }
        rail.addEventListener('scroll', sync, { passive: true });
        window.addEventListener('resize', sync);
        sync();
    });

    /* ---------- Home hero carousel ---------- */
    $all('[data-hero-carousel]').forEach(function (root) {
        var slides = Array.prototype.slice.call(root.querySelectorAll('[data-slide]'));
        if (slides.length < 2) {
            return;
        }
        var dots = Array.prototype.slice.call(root.querySelectorAll('[data-hero-dot]'));
        var prevBtn = root.querySelector('[data-hero-prev]');
        var nextBtn = root.querySelector('[data-hero-next]');
        var track = root.querySelector('.hero-track');
        var index = 0;
        var timer = null;
        var delay = Math.max(3000, Number(root.getAttribute('data-autoplay')) || 5500);
        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function render() {
            slides.forEach(function (slide, i) {
                var active = i === index;
                slide.classList.toggle('is-active', active);
                slide.classList.toggle('is-prev', i === (index - 1 + slides.length) % slides.length);
                slide.classList.toggle('is-next', i === (index + 1) % slides.length);
                slide.setAttribute('aria-hidden', active ? 'false' : 'true');
                Array.prototype.forEach.call(slide.querySelectorAll('a'), function (link) {
                    link.tabIndex = active ? 0 : -1;
                });
            });
            dots.forEach(function (dot, i) {
                dot.classList.toggle('is-active', i === index);
                dot.setAttribute('aria-selected', i === index ? 'true' : 'false');
            });
        }

        function go(next) {
            index = (next + slides.length) % slides.length;
            render();
        }

        function stop() {
            window.clearInterval(timer);
            timer = null;
        }

        function start() {
            stop();
            if (reduceMotion || document.hidden) {
                return;
            }
            timer = window.setInterval(function () {
                go(index + 1);
            }, delay);
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                go(index - 1);
                start();
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                go(index + 1);
                start();
            });
        }
        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                go(Number(dot.getAttribute('data-hero-dot')) || 0);
                start();
            });
        });

        root.addEventListener('mouseenter', stop);
        root.addEventListener('mouseleave', start);
        root.addEventListener('focusin', stop);
        root.addEventListener('focusout', function (event) {
            if (!root.contains(event.relatedTarget)) {
                start();
            }
        });
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
            } else {
                start();
            }
        });

        if (track) {
            var startX = 0;
            var startY = 0;
            var tracking = false;
            track.addEventListener('pointerdown', function (event) {
                if (event.pointerType === 'mouse') {
                    return;
                }
                tracking = true;
                startX = event.clientX;
                startY = event.clientY;
            });
            track.addEventListener('pointerup', function (event) {
                if (!tracking) {
                    return;
                }
                tracking = false;
                var dx = event.clientX - startX;
                var dy = event.clientY - startY;
                if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
                    go(dx < 0 ? index + 1 : index - 1);
                    start();
                }
            });
            track.addEventListener('pointercancel', function () {
                tracking = false;
            });
        }

        render();
        start();
    });

    /* ---------- Behaviour tracking + personalised recommendations ---------- */
    var productView = null;
    try {
        productView = JSON.parse(document.body.getAttribute('data-product-view') || 'null');
    } catch (error) {
        productView = null;
    }
    if (productView && productView.id) {
        behaviourView(productView);
    }

    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (!link) {
            return;
        }
        var href = link.getAttribute('href') || '';
        var match = href.match(/\/deals\/([a-z0-9-]+)/i) || href.match(/\/store\/([a-z0-9-]+)/i);
        if (match && match[1] !== 'under-499' && match[1] !== 'under-999' && match[1] !== 'under-1999') {
            behaviourInterest(match[1]);
        }
    });

    var relatedGrid = document.getElementById('relatedGrid');
    if (relatedGrid && productView && behaviourSignals() >= 2) {
        fetchRecommendations({
            current: productView,
            exclude: [productView.id]
        }, function (payload) {
            if (!payload || !payload.html || payload.count < 4) {
                return;
            }
            relatedGrid.innerHTML = payload.html;
            var subtitle = document.getElementById('relatedSubtitle');
            if (subtitle) {
                subtitle.textContent = 'Picked for you from the deals you viewed and searched';
            }
            var heading = document.getElementById('relatedHeading');
            if (heading) {
                heading.textContent = 'More deals picked for you';
            }
        });
    }

    var forYouSection = document.getElementById('forYouSection');
    if (forYouSection && behaviourSignals() >= 2) {
        fetchRecommendations({ current: null, exclude: [] }, function (payload) {
            if (!payload || !payload.html || payload.count < 4) {
                return;
            }
            var forYouGrid = document.getElementById('forYouGrid');
            if (forYouGrid) {
                forYouGrid.innerHTML = payload.html;
            }
            forYouSection.hidden = false;
        });
    }

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
