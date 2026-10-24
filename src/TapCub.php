<?php

declare(strict_types=1);

namespace TapCub;

/**
 * TapCub server-side client.
 *
 * Consumers (option `consumer`):
 * - `sync` (default for PHP): send on every call; throws TapCubException on failure.
 * - `batch`: queue in memory; sent when `maxBatch` events are queued, on flush() / close(), and at the end of the request
 *   (shutdown function). Failures at shutdown go to `onError`.
 * - `debug`: like `sync`, against `?debug=1` (validated, nothing stored); every result is logged.
 */
class TapCub
{
    public const VERSION = '0.1.0';
    public const DEFAULT_ENDPOINT = 'https://api.pathclue.com';
    private const MAX_BODY_BYTES = 1000000;

    /** @var string */
    private $apiKey;
    /** @var string */
    private $endpoint;
    /** @var string */
    private $consumer;
    /** @var int */
    private $maxBatch;
    /** @var int */
    private $maxQueueSize;
    /** @var int */
    private $maxRetries;
    /** @var float */
    private $timeout;
    /** @var bool */
    private $gzip;
    /** @var callable */
    private $onError;
    /** @var object|null PSR-3 style logger (error / warning) */
    private $logger;
    /** @var float 测试用：退避基数（秒） */
    private $retryBase;
    /** @var list<array{0: array<string, mixed>, 1: int}> */
    private $queue = [];
    /** @var int */
    private $dropped = 0;
    /** @var bool */
    private $shutdownRegistered = false;

    /**
     * @param string $apiKey project API key (`wck_…`) with the `track:write` scope
     * @param string|null $endpoint origin of the ingest API, default https://api.pathclue.com
     * @param array{consumer?: string, maxBatch?: int, maxQueueSize?: int, maxRetries?: int, timeout?: float, gzip?: bool, onError?: callable, logger?: object} $options
     */
    public function __construct(string $apiKey, ?string $endpoint = null, array $options = [])
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('TapCub: apiKey is required');
        }
        $consumer = $options['consumer'] ?? 'sync';
        if (!in_array($consumer, ['batch', 'sync', 'debug'], true)) {
            throw new \InvalidArgumentException('TapCub: consumer must be batch, sync or debug');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('TapCub: the curl extension is required');
        }
        $this->apiKey = $apiKey;
        $this->endpoint = self::normalizeEndpoint($endpoint);
        $this->consumer = $consumer;
        $this->maxBatch = max(1, min(1000, (int) ($options['maxBatch'] ?? 200)));
        $this->maxQueueSize = max(1, (int) ($options['maxQueueSize'] ?? 10000));
        $this->maxRetries = max(0, (int) ($options['maxRetries'] ?? 5));
        $this->timeout = (float) ($options['timeout'] ?? 10.0);
        $this->gzip = (bool) ($options['gzip'] ?? false);
        $this->logger = $options['logger'] ?? null;
        // static：不持有 $this，避免循环引用导致 __destruct 不执行
        $logger = $this->logger;
        $this->onError = $options['onError'] ?? static function (TapCubException $e) use ($logger): void {
            self::logTo($logger, 'error', '[tapcub] ' . $e->getMessage());
        };
        $this->retryBase = (float) ($options['_retryBase'] ?? 0.5);
    }

    public function __destruct()
    {
        $this->flushQuietly();
    }

    /** Origin (plus any reverse-proxy prefix) without `/api/v1/track` */
    public static function normalizeEndpoint(?string $endpoint): string
    {
        $e = rtrim(trim($endpoint ?? '') === '' ? self::DEFAULT_ENDPOINT : trim($endpoint), '/');
        $e = (string) preg_replace('#/api/v1(/track)?$#', '', $e);
        if (preg_match('#^https?://#', $e) !== 1) {
            throw new \InvalidArgumentException('TapCub: endpoint must start with http:// or https://');
        }
        return $e;
    }

    /**
     * Device and session IDs a TapCub client put on the request to your backend (`X-Tc-Device` / `X-Tc-Session`).
     *
     * @param array<string, mixed>|null $server defaults to $_SERVER
     * @return array{deviceId: ?string, sessionId: ?string}
     */
    public static function fromRequestHeaders(?array $server = null): array
    {
        $server = $server ?? $_SERVER;
        $get = static function (string $name) use ($server): ?string {
            foreach (['HTTP_' . strtoupper(str_replace('-', '_', $name)), $name, strtolower($name)] as $k) {
                $v = $server[$k] ?? null;
                if (is_array($v)) {
                    $v = $v[0] ?? null;
                }
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
            return null;
        };
        return ['deviceId' => $get('X-Tc-Device'), 'sessionId' => $get('X-Tc-Session')];
    }

    // ---------------- events ----------------

    /**
     * A business event.
     *
     * @param array{loginId?: ?string, loginIdType?: string, deviceId?: ?string, sessionId?: ?string, insertId?: ?string, time?: int|float|\DateTimeInterface, group?: string, properties?: array<string, mixed>, context?: array<string, mixed>} $options
     *   `insertId` (≤36) is the idempotency key; it is not generated for you.
     * @return array<string, mixed>|null the response in sync / debug mode
     */
    public function track(string $event, array $options = []): ?array
    {
        $type = Normalizer::loginIdType($options['loginIdType'] ?? null);
        $login = Normalizer::optionalText('loginId', $options['loginId'] ?? null, 200);
        return $this->enqueue([
            'type' => 'track',
            'event' => Normalizer::eventName($event),
            'login_id' => $login !== null && ($type === 'email' || $type === 'mobile') ? Normalizer::identityValue($type, $login) : $login,
            'login_id_type' => $type,
            'device_id' => Normalizer::optionalText('deviceId', $options['deviceId'] ?? null, 72),
            'session_id' => Normalizer::optionalText('sessionId', $options['sessionId'] ?? null, 32),
            'insert_id' => Normalizer::optionalText('insertId', $options['insertId'] ?? null, 36),
            'time' => Normalizer::time($options['time'] ?? null),
            'group' => Normalizer::group($options['group'] ?? null),
            'properties' => Normalizer::properties($options['properties'] ?? null),
            'context' => Normalizer::context($options['context'] ?? null),
        ]);
    }

    /**
     * Sign-up: links the anonymous device (if any) to the new login ID and records `sign_up`.
     *
     * @param array{deviceId?: ?string, sessionId?: ?string, insertId?: ?string, time?: int|float|\DateTimeInterface, properties?: array<string, mixed>, invitedBy?: ?string, context?: array<string, mixed>} $options
     *   `invitedBy` is the inviter's login ID (sent as `properties.$invited_by`).
     * @return array<string, mixed>|null
     */
    public function trackSignup(string $loginId, array $options = []): ?array
    {
        $props = $options['properties'] ?? [];
        if (isset($options['invitedBy']) && trim((string) $options['invitedBy']) !== '') {
            $props['$invited_by'] = trim((string) $options['invitedBy']);
        }
        return $this->enqueue([
            'type' => 'track_signup',
            'event' => 'sign_up',
            'login_id' => Normalizer::requireText('loginId', $loginId, 200),
            'device_id' => Normalizer::optionalText('deviceId', $options['deviceId'] ?? null, 72),
            'session_id' => Normalizer::optionalText('sessionId', $options['sessionId'] ?? null, 32),
            'insert_id' => Normalizer::optionalText('insertId', $options['insertId'] ?? null, 36),
            'time' => Normalizer::time($options['time'] ?? null),
            'properties' => Normalizer::properties($props),
            'context' => Normalizer::context($options['context'] ?? null),
        ]);
    }

    /** Merge properties into the stored event with this insert ID. */
    public function trackUpdate(string $event, string $insertId, array $properties): ?array
    {
        return $this->enqueue(['type' => 'track_update', 'event' => Normalizer::eventName($event), 'insert_id' => Normalizer::requireText('insertId', $insertId, 36), 'time' => Normalizer::time(null), 'properties' => Normalizer::properties($properties)]);
    }

    /** Replace the properties of the stored event with this insert ID. */
    public function trackOverwrite(string $event, string $insertId, array $properties): ?array
    {
        return $this->enqueue(['type' => 'track_overwrite', 'event' => Normalizer::eventName($event), 'insert_id' => Normalizer::requireText('insertId', $insertId, 36), 'time' => Normalizer::time(null), 'properties' => Normalizer::properties($properties)]);
    }

    // ---------------- identity ----------------

    /**
     * Attach more identities (email, mobile, unionid, openid, custom). Email and mobile are SHA-256 hashed first.
     *
     * @param array<string, string> $identities
     */
    public function bind(string $loginId, array $identities): ?array
    {
        return $this->enqueue(['type' => 'bind', 'login_id' => Normalizer::requireText('loginId', $loginId, 200), 'time' => Normalizer::time(null), 'identities' => Normalizer::identities($identities)]);
    }

    /** Remove one identity from the user. */
    public function unbind(string $loginId, string $identityType, string $value): ?array
    {
        return $this->enqueue(['type' => 'unbind', 'login_id' => Normalizer::requireText('loginId', $loginId, 200), 'time' => Normalizer::time(null), 'identities' => Normalizer::identities([$identityType => Normalizer::requireText('value', $value)])]);
    }

    // ---------------- profiles ----------------

    public function profileSet(string $loginId, array $properties): ?array
    {
        return $this->profile('profile_set', $loginId, ['properties' => Normalizer::properties($properties)]);
    }

    /** Set only the properties that are not set yet. */
    public function profileSetOnce(string $loginId, array $properties): ?array
    {
        return $this->profile('profile_set_once', $loginId, ['properties' => Normalizer::properties($properties)]);
    }

    /** @param array<string, int|float> $increment */
    public function profileIncrement(string $loginId, array $increment): ?array
    {
        return $this->profile('profile_increment', $loginId, ['increment' => Normalizer::increment($increment)]);
    }

    /** @param array<string, mixed> $append */
    public function profileAppend(string $loginId, array $append): ?array
    {
        return $this->profile('profile_append', $loginId, ['append' => Normalizer::append($append)]);
    }

    /** @param list<string> $keys */
    public function profileUnset(string $loginId, array $keys): ?array
    {
        return $this->profile('profile_unset', $loginId, ['unset' => Normalizer::unsetKeys($keys)]);
    }

    // ---------------- items / accounts ----------------

    /** Upsert a dimension row (product, article, store …). */
    public function itemSet(string $itemType, string $itemId, array $properties): ?array
    {
        return $this->enqueue(['type' => 'item_set', 'item_type' => Normalizer::requireText('itemType', $itemType, 40), 'item_id' => Normalizer::requireText('itemId', $itemId, 80), 'time' => Normalizer::time(null), 'properties' => Normalizer::properties($properties)]);
    }

    public function itemDelete(string $itemType, string $itemId): ?array
    {
        return $this->enqueue(['type' => 'item_delete', 'item_type' => Normalizer::requireText('itemType', $itemType, 40), 'item_id' => Normalizer::requireText('itemId', $itemId, 80), 'time' => Normalizer::time(null)]);
    }

    /** Set account (group) traits for `groupType:groupId`. */
    public function groupSet(string $groupType, string $groupId, array $properties): ?array
    {
        return $this->enqueue(['type' => 'group_set', 'group' => Normalizer::groupKey($groupType, $groupId), 'time' => Normalizer::time(null), 'properties' => Normalizer::properties($properties)]);
    }

    // ---------------- sending ----------------

    /** Send everything queued now. Throws the first TapCubException if a batch could not be delivered. */
    public function flush(): void
    {
        $errors = $this->drain();
        if ($errors !== []) {
            throw $errors[0];
        }
    }

    /** Send what is queued (throws like flush()). */
    public function close(): void
    {
        $this->flush();
    }

    /** Number of events waiting in the queue */
    public function queued(): int
    {
        return count($this->queue);
    }

    /** @internal shutdown function / destructor: failures go to onError */
    public function flushQuietly(): void
    {
        foreach ($this->drain() as $e) {
            $this->report($e);
        }
    }

    private function profile(string $type, string $loginId, array $body): ?array
    {
        return $this->enqueue(['type' => $type, 'login_id' => Normalizer::requireText('loginId', $loginId, 200), 'time' => Normalizer::time(null)] + $body);
    }

    private function enqueue(array $raw): ?array
    {
        $ev = Normalizer::compact($raw);
        if ($this->consumer !== 'batch') {
            return $this->send([$ev], $this->consumer === 'debug');
        }
        $this->queue[] = [$ev, strlen($this->json($ev)) + 1];
        $over = count($this->queue) - $this->maxQueueSize;
        if ($over > 0) {
            array_splice($this->queue, 0, $over);
            $this->dropped += $over;
        }
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            // 弱引用：客户端先被回收时由 __destruct 发送，不靠进程结束
            $ref = \WeakReference::create($this);
            register_shutdown_function(static function () use ($ref): void {
                $tc = $ref->get();
                if ($tc !== null) {
                    $tc->flushQuietly();
                }
            });
        }
        if (count($this->queue) >= $this->maxBatch) {
            $this->flushQuietly();
        }
        return null;
    }

    /** @return list<TapCubException> */
    private function drain(): array
    {
        $errors = [];
        if ($this->dropped > 0) {
            $errors[] = new TapCubException("TapCub: queue full, dropped {$this->dropped} oldest event(s)", 'QUEUE_FULL');
            $this->dropped = 0;
        }
        while ($this->queue !== []) {
            $batch = [];
            $bytes = 0;
            while ($this->queue !== [] && count($batch) < $this->maxBatch) {
                [$ev, $size] = $this->queue[0];
                if ($batch !== [] && $bytes + $size > self::MAX_BODY_BYTES) {
                    break;
                }
                array_shift($this->queue);
                $batch[] = $ev;
                $bytes += $size;
            }
            try {
                $this->send($batch, false);
            } catch (TapCubException $e) {
                $errors[] = $e;
            }
        }
        return $errors;
    }

    private function json(mixed $v): string
    {
        $s = json_encode($v, Normalizer::JSON_FLAGS);
        if ($s === false) {
            throw new \InvalidArgumentException('TapCub: event cannot be encoded as JSON: ' . json_last_error_msg());
        }
        return $s;
    }

    /**
     * One HTTP request. Override in tests.
     *
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string} status (0 = network error), lower-cased headers, body (or the error message)
     */
    protected function post(string $url, string $body, array $headers): array
    {
        $resHeaders = [];
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min($this->timeout, 5.0) * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$resHeaders): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $resHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false || $status === 0) {
            return [0, [], $err !== '' ? $err : 'no response'];
        }
        return [$status, $resHeaders, (string) $res];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function send(array $events, bool $debug): array
    {
        $url = $this->endpoint . '/api/v1/track' . ($debug ? '?debug=1' : '');
        $body = $this->json(['events' => $events]);
        $headers = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->apiKey, 'User-Agent' => 'tapcub-php/' . self::VERSION];
        if ($this->gzip) {
            $body = (string) gzencode($body);
            $headers['Content-Encoding'] = 'gzip';
        }
        $last = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep((int) ($this->backoff($attempt, $last) * 1000000));
            }
            [$status, $resHeaders, $raw] = $this->post($url, $body, $headers);
            if ($status === 0) {
                $last = new TapCubException("TapCub: request failed: {$raw}", 'NETWORK', null, $events);
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            if ($status >= 200 && $status < 300) {
                $results = is_array($data['results'] ?? null) ? $data['results'] : [];
                if ($debug) {
                    foreach ($results as $r) {
                        $w = $r['warnings'] ?? [];
                        $this->log('warning', sprintf('[tapcub] debug #%s %s%s', $r['index'] ?? '?', !empty($r['ok']) ? 'ok' : 'rejected', $w ? ' warnings=' . implode(',', $w) : ''));
                    }
                }
                $bad = array_values(array_filter($results, static fn ($r) => empty($r['ok'])));
                if ($bad !== []) {
                    $desc = implode('; ', array_map(static fn ($r) => '#' . ($r['index'] ?? '?') . ' ' . (implode(',', $r['warnings'] ?? []) ?: 'rejected'), $bad));
                    $rejected = [];
                    foreach ($bad as $r) {
                        if (isset($r['index'], $events[$r['index']])) {
                            $rejected[] = $events[$r['index']];
                        }
                    }
                    throw new TapCubException('TapCub: ' . count($bad) . " event(s) rejected: {$desc}", 'EVENT_REJECTED', $status, $rejected, $bad);
                }
                $data['results'] = $results;
                return $data;
            }
            $code = is_string($data['code'] ?? null) ? $data['code'] : "HTTP_{$status}";
            $msg = is_string($data['message'] ?? null) ? $data['message'] : substr($raw, 0, 200);
            $issues = is_array($data['issues'] ?? null) && $data['issues'] !== [] ? ' (' . implode('; ', array_slice(array_map('strval', $data['issues']), 0, 3)) . ')' : '';
            $last = new TapCubException("TapCub: HTTP {$status} {$code}: {$msg}{$issues}", $code, $status, $events);
            $ra = (float) ($resHeaders['retry-after'] ?? 0);
            $last->retryAfter = $ra > 0 ? $ra : null;
            if ($status !== 429 && $status < 500) {
                throw $last;
            }
        }
        throw $last;
    }

    private function backoff(int $attempt, ?TapCubException $last): float
    {
        if ($last !== null && $last->retryAfter !== null) {
            return min(60.0, $last->retryAfter);
        }
        $base = min(30.0, $this->retryBase * (2 ** ($attempt - 1)));
        return $base / 2 + (mt_rand() / mt_getrandmax()) * $base / 2;
    }

    private function report(TapCubException $e): void
    {
        try {
            ($this->onError)($e);
        } catch (\Throwable $t) {
            $this->log('error', '[tapcub] onError threw: ' . $t->getMessage());
        }
    }

    private function log(string $level, string $msg): void
    {
        self::logTo($this->logger, $level, $msg);
    }

    private static function logTo(?object $logger, string $level, string $msg): void
    {
        if ($logger !== null && method_exists($logger, $level)) {
            $logger->{$level}($msg);
            return;
        }
        error_log($msg);
    }
}
