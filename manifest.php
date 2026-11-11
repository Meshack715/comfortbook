<?php
header('Content-Type: application/manifest+json'); header('Cache-Control: public, max-age=3600');
$root = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); if ($root === '\\') $root = '';
echo json_encode([
    'name' => 'ComfortBook — UEAB Hostel Booking', 'short_name' => 'ComfortBook', 'description' => 'Book and explore your UEAB hostel room in 3D.',
    'id' => $root . '/', 'start_url' => $root . '/index.php?source=pwa', 'scope' => $root . '/', 'display' => 'standalone', 'orientation' => 'any',
    'background_color' => '#003366', 'theme_color' => '#003366', 'categories' => ['education', 'utilities'],
    'icons' => [['src' => $root . '/assets/logo/ueab.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'], ['src' => $root . '/assets/logo/ueab.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']],
    'shortcuts' => [['name' => 'Explore in 3D', 'url' => $root . '/student/explore.php'], ['name' => 'Approvals', 'url' => $root . '/user/approvals.php']],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
