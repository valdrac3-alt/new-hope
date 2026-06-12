<?php
// ============================================================
// router.php — Required for Railway / PHP built-in server
// PHP's built-in server does NOT serve static files by default.
// This router intercepts every request. If the requested file
// exists on disk (CSS, JS, images, fonts, etc.), it returns
// false — which tells the built-in server to serve it directly.
// Anything else falls through to normal PHP execution.
// ============================================================

$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

// Serve existing static files (css, js, images, fonts, etc.)
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    // Set proper MIME types
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
        'pdf'   => 'application/pdf',
        'json'  => 'application/json',
        'xml'   => 'application/xml',
        'txt'   => 'text/plain',
        'webp'  => 'image/webp',
    ];
    if (isset($mime[$ext])) {
        header('Content-Type: ' . $mime[$ext]);
    }
    // Cache static assets in browser — 7 days for CSS/JS/fonts, 1 day for images
    $cacheable = ['css','js','woff','woff2','ttf','eot'];
    $imgcache  = ['png','jpg','jpeg','gif','svg','ico','webp'];
    if (in_array($ext, $cacheable)) {
        header('Cache-Control: public, max-age=604800, immutable');
    } elseif (in_array($ext, $imgcache)) {
        header('Cache-Control: public, max-age=86400');
    }
    return false; // Let the built-in server send the file
}

// For everything else, let PHP handle it normally
return false;