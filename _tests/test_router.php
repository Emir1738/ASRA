<?php
// Test router: /ntfy/* -> ntfy mock (ayni orijin, CORS derdi yok);
// digerleri -> dosya sunumu (auth.php, index.html)
$p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($p) && strpos($p, '/ntfy/') === 0) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $_SERVER['REQUEST_URI'] = substr($p, 5) . ($qs !== '' ? '?' . $qs : '');
    require __DIR__ . '/ntfy_mock.php';
    return;
}
return false;
