<?php
// phantomtrack.nbence.hu sitemap
header('Content-Type: application/xml; charset=utf-8');

$baseUrl = 'https://phantomtrack.nbence.hu';

error_reporting(0);
ini_set('display_errors', 0);

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

// Főoldal (redirect login-ra)
echo '  <url>' . PHP_EOL;
echo '    <loc>' . $baseUrl . '/</loc>' . PHP_EOL;
echo '    <priority>1.00</priority>' . PHP_EOL;
echo '    <changefreq>weekly</changefreq>' . PHP_EOL;
echo '    <lastmod>' . date('Y-m-d') . '</lastmod>' . PHP_EOL;
echo '  </url>' . PHP_EOL;

// Publikus oldalak
$publicPages = [
    'tracker/login.php' => ['priority' => '0.90', 'changefreq' => 'monthly', 'title' => 'Login'],
    'gallery_view.php' => ['priority' => '0.80', 'changefreq' => 'weekly', 'title' => 'Galéria'],
];

foreach ($publicPages as $page => $details) {
    if (file_exists(__DIR__ . '/' . $page)) {
        $cleanUrl = str_replace('.php', '', $page);
        echo '  <url>' . PHP_EOL;
        echo '    <loc>' . $baseUrl . '/' . $cleanUrl . '</loc>' . PHP_EOL;
        echo '    <priority>' . $details['priority'] . '</priority>' . PHP_EOL;
        echo '    <changefreq>' . $details['changefreq'] . '</changefreq>' . PHP_EOL;
        echo '    <lastmod>' . date('Y-m-d') . '</lastmod>' . PHP_EOL;
        echo '  </url>' . PHP_EOL;
    }
}

echo '</urlset>' . PHP_EOL;
