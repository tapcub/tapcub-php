<?php
// Mock /api/v1/track for TransportTest (php -S). The first path segment picks the scenario; requests are logged to TAPCUB_TEST_LOG.
$log = getenv('TAPCUB_TEST_LOG');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scenario = explode('/', trim($path, '/'))[0];
$raw = file_get_contents('php://input');
if (($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip') {
    $raw = gzdecode($raw);
}
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
$headers['content-type'] = $_SERVER['CONTENT_TYPE'] ?? '';
file_put_contents($log, json_encode(['uri' => $_SERVER['REQUEST_URI'], 'headers' => $headers, 'body' => json_decode($raw, true), 'raw' => $raw], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
$n = count(array_filter(explode("\n", (string) file_get_contents($log)), static fn ($l) => $l !== '' && strpos($l, '"uri":"/' . $scenario . '/') !== false));

header('Content-Type: application/json');
$ok = ['received' => 1, 'accepted' => 1, 'rejected' => 0, 'events' => 1, 'results' => []];
switch ($scenario) {
    case 'retry':
        if ($n === 1) {
            http_response_code(503);
            echo json_encode(['message' => 'down']);
        } elseif ($n === 2) {
            http_response_code(429);
            header('Retry-After: 0');
            echo json_encode(['message' => 'slow']);
        } else {
            echo json_encode($ok);
        }
        break;
    case 'badkey':
        http_response_code(401);
        echo json_encode(['statusCode' => 401, 'message' => 'bad key', 'code' => 'BAD_API_KEY']);
        break;
    case 'down':
        http_response_code(500);
        echo json_encode(['message' => 'boom']);
        break;
    case 'reject':
        echo json_encode(['received' => 2, 'accepted' => 1, 'rejected' => 1, 'results' => [['index' => 1, 'ok' => false, 'warnings' => ['profile_without_person']]]]);
        break;
    case 'debug':
        echo json_encode(['debug' => true, 'results' => [['index' => 0, 'ok' => true, 'warnings' => ['plaintext_identity_hashed']]]]);
        break;
    default:
        echo json_encode($ok);
}
