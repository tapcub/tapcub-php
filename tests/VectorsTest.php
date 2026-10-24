<?php

declare(strict_types=1);

namespace TapCub\Tests;

use PHPUnit\Framework\TestCase;
use TapCub\TapCub;

/** Records the request instead of sending it. */
final class CaptureClient extends TapCub
{
    /** @var list<list<array<string, mixed>>> */
    public $sent = [];

    protected function post(string $url, string $body, array $headers): array
    {
        $this->sent[] = json_decode($body, true)['events'];
        return [200, [], '{"received":1,"accepted":1,"rejected":0,"events":1,"results":[]}'];
    }
}

/** Shared conformance vectors: test-vectors/track (rules in that directory's README). */
final class VectorsTest extends TestCase
{
    public static function vectorDir(): ?string
    {
        $candidates = array_filter([
            getenv('TAPCUB_VECTORS') ?: null,
            __DIR__ . '/../../../shared/test-vectors/track', // main repository / tapcub-sdk
            __DIR__ . '/../test-vectors/track',              // tapcub-php
        ]);
        foreach ($candidates as $d) {
            if (is_dir($d)) {
                return realpath($d) ?: $d;
            }
        }
        return null;
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public function vectors(): array
    {
        $dir = self::vectorDir();
        $out = [];
        foreach ($dir ? glob($dir . '/*.json') : [] as $f) {
            $out[basename($f)] = [json_decode((string) file_get_contents($f), true)];
        }
        return $out;
    }

    public function testVectorSetIsComplete(): void
    {
        $this->assertNotNull(self::vectorDir(), 'shared vectors not found');
        $this->assertGreaterThanOrEqual(30, count($this->vectors()));
    }

    /** @param array<string, mixed> $a */
    private static function call(TapCub $tc, string $method, array $a): void
    {
        $opts = static function (array $map) use ($a): array {
            $o = [];
            foreach ($map as $from => $to) {
                if (array_key_exists($from, $a)) {
                    $o[$to] = $a[$from];
                }
            }
            return $o;
        };
        switch ($method) {
            case 'track':
                $tc->track((string) ($a['event'] ?? ''), $opts(['login_id' => 'loginId', 'login_id_type' => 'loginIdType', 'device_id' => 'deviceId', 'session_id' => 'sessionId', 'insert_id' => 'insertId', 'time' => 'time', 'group' => 'group', 'properties' => 'properties', 'context' => 'context']));
                break;
            case 'track_signup':
                $tc->trackSignup((string) $a['login_id'], $opts(['device_id' => 'deviceId', 'session_id' => 'sessionId', 'insert_id' => 'insertId', 'time' => 'time', 'properties' => 'properties', 'invited_by' => 'invitedBy', 'context' => 'context']));
                break;
            case 'bind':
                $tc->bind($a['login_id'], $a['identities']);
                break;
            case 'unbind':
                $tc->unbind($a['login_id'], $a['identity_type'], $a['value']);
                break;
            case 'profile_set':
                $tc->profileSet($a['login_id'], $a['properties']);
                break;
            case 'profile_set_once':
                $tc->profileSetOnce($a['login_id'], $a['properties']);
                break;
            case 'profile_increment':
                $tc->profileIncrement($a['login_id'], $a['increment']);
                break;
            case 'profile_append':
                $tc->profileAppend($a['login_id'], $a['append']);
                break;
            case 'profile_unset':
                $tc->profileUnset($a['login_id'], $a['keys']);
                break;
            case 'item_set':
                $tc->itemSet($a['item_type'], $a['item_id'], $a['properties']);
                break;
            case 'item_delete':
                $tc->itemDelete($a['item_type'], $a['item_id']);
                break;
            case 'track_update':
                $tc->trackUpdate($a['event'], $a['insert_id'], $a['properties']);
                break;
            case 'track_overwrite':
                $tc->trackOverwrite($a['event'], $a['insert_id'], $a['properties']);
                break;
            case 'group_set':
                $tc->groupSet($a['group_type'], $a['group_id'], $a['properties']);
                break;
            default:
                throw new \LogicException("unknown method {$method}");
        }
    }

    /**
     * @dataProvider vectors
     * @param array<string, mixed> $v
     */
    public function testVector(array $v): void
    {
        $tc = new CaptureClient('wck_test');
        if (!empty($v['error'])) {
            try {
                self::call($tc, $v['call']['method'], $v['call']['args']);
                $this->fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame([], $tc->sent);
            }
            return;
        }
        self::call($tc, $v['call']['method'], $v['call']['args']);
        $this->assertCount(1, $tc->sent);
        $ev = $tc->sent[0][0];
        $expected = $v['expected'];
        if (($expected['time'] ?? null) === '$now') {
            $this->assertIsInt($ev['time']);
            $this->assertLessThan(60000, abs($ev['time'] - (int) (microtime(true) * 1000)));
            $expected['time'] = $ev['time'];
        }
        $this->assertEquals(self::sortKeys($expected), self::sortKeys($ev));
    }

    /** JSON 比较与键序无关 */
    private static function sortKeys(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        $v = array_map([self::class, 'sortKeys'], $v);
        if (!TapCubTestUtil::isList($v)) {
            ksort($v, SORT_STRING);
        }
        return $v;
    }
}

final class TapCubTestUtil
{
    public static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }
}
