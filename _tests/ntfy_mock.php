<?php
// Yerel ntfy mock (test):
//  POST /<topic>          -> push'ı yakala (sunucu yayını simülasyonu)
//  GET  /<topic>/json     -> sayfanın doğrudan dinlediği uç; NDJSON döndür
declare(strict_types=1);
$dir = __DIR__;
$entriesFile = $dir . '/ntfy_log.json';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: *');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    return;
}
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$parts = array_values(array_filter(explode('/', trim((string) $uri, '/')), 'strlen'));

$load = function () use ($entriesFile) {
    $raw = is_file($entriesFile) ? (string) file_get_contents($entriesFile) : '';
    $d = json_decode($raw ?: '[]', true);
    return is_array($d) ? $d : [];
};
$save = function (array $d) use ($entriesFile) {
    $f = fopen($entriesFile, 'c+');
    if ($f === false) return;
    flock($f, LOCK_EX);
    ftruncate($f, 0);
    fseek($f, 0);
    fwrite($f, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($f, LOCK_UN);
    fclose($f);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && count($parts) >= 1) {
    $entries = $load();
    $maxId = 0;
    foreach ($entries as $e) { $maxId = max($maxId, (int) ($e['id'] ?? 0)); }
    $entries[] = [
        'id' => (string) ($maxId + 1),
        'topic' => $parts[0],
        'title' => $_SERVER['HTTP_TITLE'] ?? '',
        'body' => (string) file_get_contents('php://input'),
        'at' => time(),
    ];
    $save($entries);
    header('Content-Type: text/plain');
    echo 'OK';
    return;
}

if (count($parts) >= 2 && $parts[1] === 'json') {
    $topic = $parts[0];
    $since = (int) ($_GET['since'] ?? 0);
    $entries = $load();
    header('Content-Type: application/x-ndjson; charset=utf-8');
    foreach ($entries as $e) {
        if (($e['topic'] ?? '') !== $topic) continue;
        if ((int) ($e['id'] ?? 0) <= $since) continue;
        $line = [
            'id' => (string) ($e['id'] ?? '0'),
            'event' => 'message',
            'time' => gmdate('Y-m-d\TH:i:s', (int) ($e['at'] ?? time())) . 'Z',
            'topic' => $topic,
            'message' => $e['body'] ?? '',
            'title' => $e['title'] ?? '',
        ];
        echo json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
    return;
}

header('Content-Type: text/plain');
echo 'mock: unknown endpoint';
