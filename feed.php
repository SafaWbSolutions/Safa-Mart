<?php
/**
 * /feed.xml — Google Merchant Center product feed (RSS 2.0).
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=1800');

echo seo_product_feed_xml();
