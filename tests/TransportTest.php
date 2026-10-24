<?php

declare(strict_types=1);

namespace TapCub\Tests;

use PHPUnit\Framework\TestCase;
use TapCub\TapCub;
use TapCub\TapCubException;

/** Real curl requests against the PHP built-in server (tests/fixtures/router.php). */
final class TransportTest extends TestCase
{
    /** @var resource|null */
    private static $proc;
    /** @var string */
    private static $base;
    /** @var string */
    private static $log;

    public static function setUpBeforeClass(): void
    {
        self::$log = tempnam(sys_get_temp_dir(), 'tapcub-php-test');
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
        fclose($sock);
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/router.php'];
        self::$proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['TAPCUB_TEST_LOG' => self::$log] + getenv());
        self::$base = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port);
            if ($c) {
                fclose($c);
                return;
            }
            usleep(20000);
        }
        self::fail('php -S did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        @unlink(self::$log);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');
    }

    /** @return list<array<string, mixed>> */
    private function reqs(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents(self::$log)));
        return array_values(array_map(static fn ($l) => json_decode($l, true), $lines));
    }

    private function client(string $scenario, array $options = []): TapCub
    {
        return new TapCub('wck_abc', self::$base . "/{$scenario}", $options + ['_retryBase' => 0.005]);
    }

    public function testVersionIsSemver(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', TapCub::VERSION);
    }

    public function testEndpointNormalization(): void
    {
        $this->assertSame('https://api.pathclue.com', TapCub::normalizeEndpoint(null));
        $this->assertSame('https://api.pathclue.com', TapCub::normalizeEndpoint('https://api.pathclue.com/'));
        $this->assertSame('https://x.example.com', TapCub::normalizeEndpoint('https://x.example.com/api/v1/track'));
        $this->assertSame('https://x.example.com/proxy', TapCub::normalizeEndpoint('https://x.example.com/proxy/api/v1'));
        $this->expectException(\InvalidArgumentException::class);
        TapCub::normalizeEndpoint('api.pathclue.com');
    }

    public function testEmptyKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TapCub(' ');
    }

    public function testHeadersPathAndResponse(): void
    {
        $res = $this->client('ok')->track('purchase', ['insertId' => 'o1']);
        $this->assertSame(1, $res['accepted']);
        $r = $this->reqs()[0];
        $this->assertSame('/ok/api/v1/track', $r['uri']);
        $this->assertSame('Bearer wck_abc', $r['headers']['authorization']);
        $this->assertSame('application/json', $r['headers']['content-type']);
        $this->assertSame('tapcub-php/' . TapCub::VERSION, $r['headers']['user-agent']);
        $this->assertSame(['events'], array_keys($r['body']));
    }

    public function testEmptyMapsEncodeAsObjects(): void
    {
        $this->client('ok')->bind('u', ['email' => '']);
        $this->assertStringContainsString('"identities":{}', $this->reqs()[0]['raw']);
    }

    public function testBatchSplitsAndFlushes(): void
    {
        $tc = $this->client('ok', ['consumer' => 'batch', 'maxBatch' => 2]);
        $tc->track('a');
        $this->assertSame([], $this->reqs());
        $this->assertSame(1, $tc->queued());
        $tc->track('b'); // 满批立即发送
        $tc->track('c');
        $tc->close();
        $this->assertSame([['a', 'b'], ['c']], array_map(static fn ($r) => array_column($r['body']['events'], 'event'), $this->reqs()));
    }

    public function testDestructorFlushes(): void
    {
        $tc = $this->client('ok', ['consumer' => 'batch']);
        $tc->track('a');
        unset($tc);
        $this->assertCount(1, $this->reqs());
    }

    public function testBodyIsSplitAt1Mb(): void
    {
        $tc = $this->client('ok', ['consumer' => 'batch', 'maxBatch' => 1000]);
        $big = [];
        for ($i = 0; $i < 40; $i++) {
            $big["p{$i}"] = str_repeat('x', 1000);
        }
        for ($i = 0; $i < 50; $i++) {
            $tc->track('big', ['properties' => $big]);
        }
        $tc->flush();
        $reqs = $this->reqs();
        $this->assertGreaterThanOrEqual(2, count($reqs));
        $total = 0;
        foreach ($reqs as $r) {
            $this->assertLessThanOrEqual(1000000, strlen((string) json_encode($r['body'])));
            $total += count($r['body']['events']);
        }
        $this->assertSame(50, $total);
    }

    public function testRetriesWithSameBody(): void
    {
        $this->client('retry')->track('a', ['insertId' => 'same']);
        $reqs = $this->reqs();
        $this->assertCount(3, $reqs);
        $this->assertSame($reqs[0]['body'], $reqs[2]['body']);
    }

    public function testClientErrorIsNotRetried(): void
    {
        try {
            $this->client('badkey')->track('a');
            $this->fail('expected TapCubException');
        } catch (TapCubException $e) {
            $this->assertSame('BAD_API_KEY', $e->getErrorCode());
            $this->assertSame(401, $e->getStatus());
            $this->assertCount(1, $e->getEvents());
        }
        $this->assertCount(1, $this->reqs());
    }

    public function testBatchErrorsGoToOnErrorAndFlushThrows(): void
    {
        $errors = [];
        $tc = $this->client('down', ['consumer' => 'batch', 'maxBatch' => 1, 'maxRetries' => 2, 'onError' => static function ($e) use (&$errors) {
            $errors[] = $e;
        }]);
        $tc->track('a'); // 满批 → 发送失败 → onError
        $this->assertCount(3, $this->reqs());
        $this->assertSame(['HTTP_500'], array_map(static fn ($e) => $e->getErrorCode(), $errors));
        $tc2 = $this->client('down', ['consumer' => 'batch', 'maxRetries' => 1, 'onError' => static function ($e) use (&$errors) {
            $errors[] = $e;
        }]);
        $tc2->track('b');
        try {
            $tc2->close();
            $this->fail('expected TapCubException');
        } catch (TapCubException $e) {
            $this->assertSame('b', $e->getEvents()[0]['event']);
        }
        $this->assertCount(1, $errors);
    }

    public function testNetworkError(): void
    {
        try {
            (new TapCub('k', 'http://127.0.0.1:9', ['maxRetries' => 1, '_retryBase' => 0.005, 'timeout' => 2]))->track('a');
            $this->fail('expected TapCubException');
        } catch (TapCubException $e) {
            $this->assertSame('NETWORK', $e->getErrorCode());
        }
    }

    public function testEventRejectedInside2xx(): void
    {
        $tc = $this->client('reject', ['consumer' => 'batch']);
        $tc->track('a');
        $tc->profileSet('u', ['a' => 1]);
        try {
            $tc->flush();
            $this->fail('expected TapCubException');
        } catch (TapCubException $e) {
            $this->assertSame('EVENT_REJECTED', $e->getErrorCode());
            $this->assertSame(['profile_set'], array_column($e->getEvents(), 'type'));
        }
    }

    public function testQueueOverflowDropsOldest(): void
    {
        $tc = $this->client('ok', ['consumer' => 'batch', 'maxQueueSize' => 3, 'maxBatch' => 1000]);
        foreach (['a', 'b', 'c', 'd', 'e'] as $e) {
            $tc->track($e);
        }
        try {
            $tc->flush();
            $this->fail('expected TapCubException');
        } catch (TapCubException $e) {
            $this->assertSame('QUEUE_FULL', $e->getErrorCode());
        }
        $this->assertSame(['c', 'd', 'e'], array_column($this->reqs()[0]['body']['events'], 'event'));
    }

    public function testGzip(): void
    {
        $this->client('ok', ['gzip' => true])->track('a');
        $r = $this->reqs()[0];
        $this->assertSame('gzip', $r['headers']['content-encoding']);
        $this->assertSame('a', $r['body']['events'][0]['event']);
    }

    public function testDebugConsumer(): void
    {
        $logs = [];
        $logger = new class ($logs) {
            /** @var array<int, string> */
            public $logs;

            public function __construct(array &$logs)
            {
                $this->logs = &$logs;
            }

            public function warning(string $m): void
            {
                $this->logs[] = $m;
            }

            public function error(string $m): void
            {
                $this->logs[] = $m;
            }
        };
        $this->client('debug', ['consumer' => 'debug', 'logger' => $logger])->track('a');
        $this->assertSame('/debug/api/v1/track?debug=1', $this->reqs()[0]['uri']);
        $this->assertStringContainsString('#0 ok warnings=plaintext_identity_hashed', $logs[0]);
    }

    public function testDateTimeTimeAndProperties(): void
    {
        $when = new \DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $this->client('ok')->track('a', ['time' => $when, 'properties' => ['at' => $when, 'n' => ['0' => 'x']]]);
        $ev = $this->reqs()[0]['body']['events'][0];
        $this->assertSame($when->getTimestamp() * 1000, $ev['time']);
        $this->assertSame(['at' => '2026-10-01T12:00:00+00:00', 'n' => '["x"]'], $ev['properties']);
    }

    public function testInvalidUtf8IsReplaced(): void
    {
        $this->client('ok')->track('a', ['properties' => ['s' => "ok\xC3("]]);
        $this->assertSame("ok\u{FFFD}(", $this->reqs()[0]['body']['events'][0]['properties']['s']);
    }

    public function testFromRequestHeaders(): void
    {
        $this->assertSame(['deviceId' => 'custom:abcd1234', 'sessionId' => 's1'], TapCub::fromRequestHeaders(['HTTP_X_TC_DEVICE' => 'custom:abcd1234', 'HTTP_X_TC_SESSION' => ' s1 ']));
        $this->assertSame(['deviceId' => null, 'sessionId' => null], TapCub::fromRequestHeaders([]));
    }
}
