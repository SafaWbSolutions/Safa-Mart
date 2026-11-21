<?php
/**
 * SEO helpers: verification tags, schema.org graphs, landing page content,
 * sitemap.xml and a Google Merchant Center product feed.
 */

/** @return list<string> Meta tags for search engine verification. */
function seo_verification_meta(): array
{
    $tags = [];
    if (GOOGLE_SITE_VERIFICATION !== '') {
        $tags[] = '<meta name="google-site-verification" content="' . e(GOOGLE_SITE_VERIFICATION) . '">';
    }
    if (BING_SITE_VERIFICATION !== '') {
        $tags[] = '<meta name="msvalidate.01" content="' . e(BING_SITE_VERIFICATION) . '">';
    }
    return $tags;
}

/** Encode a JSON-LD block safely. */
function seo_jsonld(array $data): string
{
    return '<script type="application/ld+json">'
        . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>';
}

/** Organization + WebSite graph, used on every public page. */
function seo_site_schema(): string
{
    $origin = rtrim(site_url(), '/');
    $logoFile = SAFA_ROOT . '/assets/img/logo.png';
    $logo = is_file($logoFile)
        ? site_url('assets/img/logo.png')
        : $origin . '/assets/img/logo.png';

    return seo_jsonld([
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type' => 'Organization',
                '@id'   => $origin . '/#organization',
                'name'  => APP_NAME,
                'url'   => $origin . '/',
                'logo'  => [
                    '@type' => 'ImageObject',
                    'url'   => $logo,
                ],
                'sameAs' => array_values(array_filter([
                    TELEGRAM_CHANNEL_URL,
                ])),
            ],
            [
                '@type'     => 'WebSite',
                '@id'       => $origin . '/#website',
                'url'       => $origin . '/',
                'name'      => APP_NAME,
                'publisher' => ['@id' => $origin . '/#organization'],
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => [
                        '@type'       => 'EntryPoint',
                        'urlTemplate' => $origin . '/index.php?q={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ],
    ]);
}

/** Robots meta for indexable or filtered pages. */
function seo_robots_meta(bool $indexable): string
{
    return $indexable
        ? '<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">'
        : '<meta name="robots" content="noindex,follow,max-image-preview:large">';
}

/** Optional GA4 snippet (prints nothing when the id is not configured). */
function seo_analytics_snippet(): string
{
    $id = trim((string) GOOGLE_ANALYTICS_ID);
    if (!preg_match('/^G-[A-Z0-9]{4,20}$/i', $id)) {
        return '';
    }
    $safe = e($id);
    return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $safe . '"></script>'
        . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
        . "gtag('js',new Date());gtag('config','" . $safe . "');</script>";
}

/** hreflang links for Indian English content. */
function seo_hreflang_links(string $url): string
{
    return '<link rel="alternate" hreflang="en-in" href="' . e($url) . '">'
        . '<link rel="alternate" hreflang="x-default" href="' . e($url) . '">';
}

/**
 * BreadcrumbList schema.
 *
 * @param list<array{name:string,url:string}> $items
 */
function seo_breadcrumb_schema(array $items): string
{
    $list = [];
    foreach ($items as $index => $item) {
        $list[] = [
            '@type'    => 'ListItem',
            'position' => $index + 1,
            'name'     => $item['name'],
            'item'     => $item['url'],
        ];
    }
    return seo_jsonld([
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list,
    ]);
}

/**
 * Category catalog used by /deals/<slug> landing pages, internal links and
 * the sitemap. Copy is unique per category to avoid thin pages.
 *
 * @return array<string,array{slug:string,title:string,h1:string,intro:string,long:string}>
 */
function seo_category_catalog(): array
{
    return [
        'Fashion' => [
            'slug'  => 'fashion',
            'title' => 'Fashion Deals – Clothing & Ethnic Wear at Up to 90% Off | Safa Mart',
            'h1'    => 'Fashion deals at up to 90% off',
            'intro' => 'Live fashion deals on shirts, t-shirts, kurtas, ethnic wear and more from Myntra, Ajio, Flipkart and Shopsy.',
            'long'  => 'Every fashion listing on Safa Mart links to the official store page, so the price you see is the price you pay at checkout. Filter by store, price, discount or rating to find your size and style without opening ten different apps.',
        ],
        'Electronics' => [
            'slug'  => 'electronics',
            'title' => 'Electronics Deals – Earbuds, Smartwatches & More | Safa Mart',
            'h1'    => 'Electronics deals worth grabbing',
            'intro' => 'Earbuds, smartwatches, speakers, chargers and everyday gadgets with live discounts across India\'s biggest marketplaces.',
            'long'  => 'We track discounts on popular electronics and show the current price with the listed MRP side by side. Check the rating, compare the saving and buy from the store you already trust.',
        ],
        'Home & Kitchen' => [
            'slug'  => 'home-kitchen',
            'title' => 'Home & Kitchen Deals – Cookware, Storage & Decor | Safa Mart',
            'h1'    => 'Home & kitchen deals for every room',
            'intro' => 'Cookware, storage, cleaning and decor picks with honest discounts from Flipkart, Amazon, Shopsy and DigiHaat.',
            'long'  => 'Upgrading your kitchen or setting up a new home? These handpicked listings cover daily-use essentials without the inflated MRP games — every saving is calculated from the listed price.',
        ],
        'Beauty' => [
            'slug'  => 'beauty',
            'title' => 'Beauty Deals – Skincare, Makeup & Haircare Offers | Safa Mart',
            'h1'    => 'Beauty deals on skincare and makeup',
            'intro' => 'Skincare, makeup, haircare and grooming products with live prices from trusted marketplaces.',
            'long'  => 'Beauty prices move fast during sale weeks. Safa Mart shows the latest discount and links straight to the merchant so you can check ingredients, shades and delivery in one tap.',
        ],
        'Footwear' => [
            'slug'  => 'footwear',
            'title' => 'Footwear Deals – Sneakers, Sandals & Formal Shoes | Safa Mart',
            'h1'    => 'Footwear deals for men, women and kids',
            'intro' => 'Sneakers, sports shoes, sandals and formal footwear at live discounts from top Indian stores.',
            'long'  => 'Compare style, size availability and price in one place. Each card shows the current deal price, the listed MRP and how much you save before you open the store page.',
        ],
        'Accessories' => [
            'slug'  => 'accessories',
            'title' => 'Accessories Deals – Bags, Watches & Wallets | Safa Mart',
            'h1'    => 'Accessories deals to complete the look',
            'intro' => 'Bags, watches, belts, wallets and small add-ons with genuine marketplace discounts.',
            'long'  => 'Small purchases deserve good prices too. Browse accessories by discount, brand or store and jump straight to the official listing when something catches your eye.',
        ],
    ];
}

/** @return array<string,string> slug => category name */
function seo_category_slug_map(): array
{
    $map = [];
    foreach (seo_category_catalog() as $name => $meta) {
        $map[$meta['slug']] = $name;
    }
    return $map;
}

/**
 * Store catalog used by /store/<slug> landing pages.
 *
 * @return array<string,array{slug:string,title:string,h1:string,intro:string,long:string}>
 */
function seo_store_catalog(): array
{
    return [
        'Flipkart' => [
            'slug'  => 'flipkart',
            'title' => 'Flipkart Deals & Offers – Live Discounts | Safa Mart',
            'h1'    => 'Flipkart deals with live prices',
            'intro' => 'Handpicked Flipkart listings with the current price, MRP and discount shown up front.',
            'long'  => 'Big Billion Days or a normal Tuesday — the discount is calculated from the live listing. Open any product to see the full details before buying on Flipkart.',
        ],
        'Myntra' => [
            'slug'  => 'myntra',
            'title' => 'Myntra Deals & Offers – Fashion at Up to 80% Off | Safa Mart',
            'h1'    => 'Myntra deals on fashion you will actually wear',
            'intro' => 'Trending Myntra fashion finds with honest discounts, ratings and direct links to the official product page.',
            'long'  => 'Myntra runs frequent End of Reason Sale drops. Safa Mart keeps a shortlist of listings with strong discounts so you do not have to scroll endless categories.',
        ],
        'Amazon' => [
            'slug'  => 'amazon',
            'title' => 'Amazon Deals – Electronics, Home & More | Safa Mart',
            'h1'    => 'Amazon deals across every category',
            'intro' => 'Amazon finds with live pricing, ratings and a one-tap link to the official listing.',
            'long'  => 'From daily essentials to gadgets, these picks are chosen for real discounts rather than inflated MRP. Confirm delivery and bank offers on Amazon at checkout.',
        ],
        'Ajio' => [
            'slug'  => 'ajio',
            'title' => 'Ajio Deals – Fashion & Footwear Offers | Safa Mart',
            'h1'    => 'Ajio deals on fresh fashion drops',
            'intro' => 'Ajio clothing and footwear picks with current discounts and direct product links.',
            'long'  => 'Ajio is strong on own-brand fashion and frequent markdowns. Browse the shortlist, check the saving and open the listing when a style fits.',
        ],
        'Shopsy' => [
            'slug'  => 'shopsy',
            'title' => 'Shopsy Deals – Budget Fashion & Everyday Low Prices | Safa Mart',
            'h1'    => 'Shopsy deals for budget shopping',
            'intro' => 'Low-price Shopsy listings across fashion and everyday essentials, with real discount percentages.',
            'long'  => 'Shopsy is built for value shopping. Every card shows the deal price and MRP so you can spot a genuine drop before you buy.',
        ],
        'DigiHaat' => [
            'slug'  => 'digihaat',
            'title' => 'DigiHaat Deals – Grocery, Home & Beauty Offers | Safa Mart',
            'h1'    => 'DigiHaat deals on everyday essentials',
            'intro' => 'DigiHaat grocery, home and beauty listings with live prices and direct links.',
            'long'  => 'Daily essentials and regional favourites, collected in one place. Check the listed MRP and the current price before opening the store.',
        ],
    ];
}

/** @return array<string,string> slug => store name */
function seo_store_slug_map(): array
{
    $map = [];
    foreach (seo_store_catalog() as $name => $meta) {
        $map[$meta['slug']] = $name;
    }
    return $map;
}

function seo_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function seo_cache_path(string $name): string
{
    $dir = dirname(UPLOAD_DIR);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return '';
    }
    return $dir . '/' . $name;
}

function seo_cached_xml(string $name, int $ttl, callable $builder): string
{
    $file = seo_cache_path($name);
    if ($file !== '' && is_file($file) && time() - (int) filemtime($file) < $ttl) {
        $cached = @file_get_contents($file);
        if (is_string($cached) && str_starts_with($cached, '<?xml')) {
            return $cached;
        }
    }

    $xml = $builder();
    if ($file !== '') {
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $xml, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }
    return $xml;
}

/** Full sitemap covering home, category/store pages and every active product. */
function seo_sitemap_xml(): string
{
    return seo_cached_xml('sitemap-cache.xml', SEO_SITEMAP_CACHE_TTL, static function (): string {
        $urls = [
            ['loc' => site_url('index.php'), 'changefreq' => 'hourly', 'priority' => '1.0', 'lastmod' => gmdate('c')],
        ];
        foreach (seo_category_catalog() as $meta) {
            $urls[] = [
                'loc'        => site_url('deals/' . $meta['slug']),
                'changefreq' => 'daily',
                'priority'   => '0.8',
                'lastmod'    => gmdate('c'),
            ];
        }
        foreach (seo_store_catalog() as $meta) {
            $urls[] = [
                'loc'        => site_url('store/' . $meta['slug']),
                'changefreq' => 'daily',
                'priority'   => '0.7',
                'lastmod'    => gmdate('c'),
            ];
        }

        if (supabase_is_configured()) {
            $query = 'select=slug,title,last_checked_at,created_at,source_platform,original_url,affiliate_url'
                . '&is_active=eq.true&order=created_at.desc&limit=5000';
            $result = supabase_request('GET', supabase_table() . '?' . $query, null, ['timeout' => 20]);
            if (!$result['ok']) {
                $result = supabase_request('GET', supabase_table() . '?' . $query, null, ['timeout' => 20]);
            }
            if ($result['ok'] && is_array($result['data'])) {
                foreach ($result['data'] as $product) {
                    if (!is_array($product) || is_blocked_product($product)) {
                        continue;
                    }
                    $slug = (string) ($product['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $stamp = (string) ($product['last_checked_at'] ?? ($product['created_at'] ?? ''));
                    $timestamp = $stamp !== '' ? strtotime($stamp) : false;
                    $urls[] = [
                        'loc'        => site_url('product.php?slug=' . rawurlencode($slug)),
                        'changefreq' => 'daily',
                        'priority'   => '0.9',
                        'lastmod'    => $timestamp !== false ? gmdate('c', $timestamp) : gmdate('c'),
                    ];
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . seo_xml_escape($url['loc']) . '</loc>'
                . '<lastmod>' . seo_xml_escape($url['lastmod']) . '</lastmod>'
                . '<changefreq>' . $url['changefreq'] . '</changefreq>'
                . '<priority>' . $url['priority'] . '</priority></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";
        return $xml;
    });
}

/** Google Merchant Center RSS 2.0 feed. */
function seo_product_feed_xml(): string
{
    return seo_cached_xml('product-feed-cache.xml', 3600, static function (): string {
        $items = [];
        if (supabase_is_configured()) {
            $query = 'select=id,slug,title,description,price,mrp,thumbnail_url,brand,source_platform,'
                . 'original_url,affiliate_url&is_active=eq.true&price=gt.0&order=created_at.desc&limit=1000';
            $result = supabase_request('GET', supabase_table() . '?' . $query, null, ['timeout' => 20]);
            if ($result['ok'] && is_array($result['data'])) {
                foreach ($result['data'] as $product) {
                    if (!is_array($product) || is_blocked_product($product)) {
                        continue;
                    }
                    $image = (string) ($product['thumbnail_url'] ?? '');
                    $slug = (string) ($product['slug'] ?? '');
                    $price = (float) ($product['price'] ?? 0);
                    if ($slug === '' || $price <= 0 || !preg_match('#^https?://#i', $image)) {
                        continue;
                    }
                    $items[] = $product;
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n";
        $xml .= '  <title>' . seo_xml_escape(APP_NAME . ' deals') . "</title>\n";
        $xml .= '  <link>' . seo_xml_escape(site_url('index.php')) . "</link>\n";
        $xml .= '  <description>' . seo_xml_escape(SITE_DESCRIPTION) . "</description>\n";

        foreach ($items as $product) {
            $title = clean_product_title((string) ($product['title'] ?? ''));
            $price = (float) ($product['price'] ?? 0);
            $description = truncate_text(trim((string) ($product['description'] ?? '')), 900);
            if ($description === '') {
                $description = $title . ' available on ' . (string) ($product['source_platform'] ?? 'the store') . '.';
            }
            $brand = clean_text((string) ($product['brand'] ?? ''));
            if ($brand === '') {
                $brand = clean_text((string) ($product['source_platform'] ?? APP_NAME));
            }
            $link = site_url('product.php?slug=' . rawurlencode((string) $product['slug']));

            $xml .= "  <item>\n";
            $xml .= '    <g:id>' . (int) ($product['id'] ?? 0) . "</g:id>\n";
            $xml .= '    <title>' . seo_xml_escape($title) . "</title>\n";
            $xml .= '    <description>' . seo_xml_escape($description) . "</description>\n";
            $xml .= '    <link>' . seo_xml_escape($link) . "</link>\n";
            $xml .= '    <g:image_link>' . seo_xml_escape((string) $product['thumbnail_url']) . "</g:image_link>\n";
            $xml .= '    <g:price>' . number_format($price, 2, '.', '') . " INR</g:price>\n";
            $xml .= "    <g:availability>in stock</g:availability>\n";
            $xml .= "    <g:condition>new</g:condition>\n";
            $xml .= '    <g:brand>' . seo_xml_escape($brand) . "</g:brand>\n";
            $xml .= "    <g:identifier_exists>no</g:identifier_exists>\n";
            $xml .= "  </item>\n";
        }

        $xml .= "</channel>\n</rss>\n";
        return $xml;
    });
}
