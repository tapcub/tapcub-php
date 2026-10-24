<?php
// End-to-end scenario against a running TapCub API. Used by scripts/smoke-sdk-server.mjs in the main repository.
//   TAPCUB_API_KEY=wck_… TAPCUB_ENDPOINT=http://localhost:3001 TAPCUB_RUN=abc php examples/e2e.php

declare(strict_types=1);

require file_exists(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor/autoload.php' : __DIR__ . '/../src/TapCub.php';
foreach (['Normalizer', 'TapCubException'] as $c) {
    if (!class_exists("TapCub\\{$c}")) {
        require __DIR__ . "/../src/{$c}.php";
    }
}

use TapCub\TapCub;

$lang = 'php';
$run = getenv('TAPCUB_RUN') ?: base_convert((string) time(), 10, 36);
$apiKey = (string) getenv('TAPCUB_API_KEY');
$endpoint = getenv('TAPCUB_ENDPOINT') ?: null;
$user = "{$lang}-{$run}";
$quiet = new class {
    public function warning(string $m): void {}
    public function error(string $m): void { fwrite(STDERR, $m . "\n"); }
};

// 1. Validate one event without storing it
(new TapCub($apiKey, $endpoint, ['consumer' => 'debug', 'logger' => $quiet]))->track('sdk_e2e_debug', ['loginId' => $user]);

// 2. Batch client: everything below goes out in one request on close()
$errors = [];
$tc = new TapCub($apiKey, $endpoint, ['consumer' => 'batch', 'onError' => static function ($e) use (&$errors) { $errors[] = $e; }]);
$tc->trackSignup($user, ['deviceId' => "custom:{$lang}{$run}dev", 'invitedBy' => 'e2e-inviter', 'context' => ['campaign' => ['source' => 'e2e', 'medium' => 'sdk', 'name' => $lang]]]);
$tc->track('sdk_e2e_purchase', [
    'loginId' => $user,
    'insertId' => "{$user}-o1",
    'properties' => ['revenue' => 19.9, 'currency' => 'USD', 'items' => [['item_id' => "sku-{$lang}", 'price' => 19.9, 'quantity' => 1]]],
    'context' => ['ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)', 'url' => 'https://shop.example.com/checkout'],
]);
$tc->bind($user, ['email' => "{$user}@example.com"]);
$tc->profileSet($user, ['plan' => 'pro', '$name' => "E2E {$lang}"]);
$tc->profileSetOnce($user, ['first_source' => 'e2e']);
$tc->profileIncrement($user, ['orders' => 1]);
$tc->profileAppend($user, ['tags' => ['e2e']]);
$tc->itemSet('product', "sku-{$lang}", ['name' => 'Shoe', 'price' => 19.9]);
$tc->groupSet('company', "acme-{$lang}", ['name' => 'Acme']);
$tc->track('sdk_e2e_seat', ['loginId' => $user, 'group' => "company:acme-{$lang}"]);
$tc->close();

if ($errors !== []) {
    fwrite(STDERR, implode("\n", array_map(static fn ($e) => $e->getMessage(), $errors)) . "\n");
    exit(1);
}
echo json_encode(['ok' => true, 'lang' => $lang, 'user' => $user]), "\n";
