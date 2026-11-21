<?php
/**
 * /sitemap.xml — generated from the live product table.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=1800');

echo seo_sitemap_xml();
