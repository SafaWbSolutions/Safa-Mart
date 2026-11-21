<?php
/**
 * Safa Mart — SEO / verification settings.
 *
 * Ye file repo me committed hai, isliye GitHub par edit karke commit karne se
 * Hostinger auto-deploy ke saath seedha live ho jati hai. config.local.php
 * chhedne ki zaroorat nahi.
 *
 * Sirf values bharni hain (quotes ke andar). Khali chhodne par kuch nahi hota.
 *
 * Search Console: <meta name="google-site-verification" content="YAHAN_WALI_VALUE">
 * -> sirf content ke andar wali value paste karo.
 */

return [
    // Google Search Console verification content
    'GOOGLE_SITE_VERIFICATION' => '',

    // Bing Webmaster Tools (msvalidate.01) content
    'BING_SITE_VERIFICATION' => '',

    // GA4 measurement id, example: G-XXXXXXXXXX
    'GOOGLE_ANALYTICS_ID' => '',

    // IndexNow key (Bing/Yandex instant indexing). Key file is committed at the
    // site root with the same value: <key>.txt
    'INDEXNOW_KEY' => 'a3f9c2b7d4e6f8102c4b6d8e0f2a4c6e',

    // Ads (jab live karna ho tab bharo)
    // 'ADSENSE_CLIENT' => 'ca-pub-XXXXXXXXXXXXXXXX',
    // 'AD_SLOTS' => [
    //     'home-top'    => ['slot' => '1234567890'],
    //     'home-mid'    => ['slot' => '0987654321'],
    //     'home-bottom' => ['slot' => '1122334455'],
    //     'product-top' => ['slot' => '5566778899'],
    //     'browse-top'  => ['slot' => '6677889900'],
    // ],
];
