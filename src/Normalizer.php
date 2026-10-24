<?php

declare(strict_types=1);

namespace TapCub;

/**
 * Event normalization shared by every TapCub server SDK (rules: test-vectors/track/README.md).
 * Maps are returned as stdClass so that empty or numeric-keyed maps still encode as JSON objects.
 *
 * @internal
 */
final class Normalizer
{
    public const LOGIN_ID_TYPES = ['login', 'email', 'mobile', 'unionid', 'openid', 'custom'];
    public const PLATFORMS = ['web', 'mp_weixin', 'mp_alipay', 'mp_bytedance', 'mp_baidu', 'mp_kuaishou', 'mp_qq', 'android', 'ios', 'harmony', 'tvos', 'watchos', 'macos', 'windows', 'linux', 'server', 'import'];
    private const GROUP_KEY_RE = '/^[a-z][a-z0-9_]{0,23}:[A-Za-z0-9_@.\-]{1,80}$/';
    private const GROUP_TYPE_RE = '/^[a-z][a-z0-9_]{0,23}$/';
    private const CAMPAIGN_LIMITS = ['source' => 100, 'medium' => 100, 'name' => 100, 'term' => 100, 'content' => 100, 'ref' => 64, 'click_id' => 1000, 'landing_url' => 2000, 'referrer' => 2000];
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** 非法 UTF-8 字节换成 U+FFFD（不依赖 mbstring） */
    public static function utf8(string $s): string
    {
        return preg_match('//u', $s) === 1 ? $s : htmlspecialchars_decode(htmlspecialchars($s, ENT_SUBSTITUTE | ENT_NOQUOTES, 'UTF-8'), ENT_NOQUOTES);
    }

    /** 服务端 zod 的 max 按 JS .length（UTF-16 码元）计 */
    public static function utf16Length(string $s): int
    {
        $s = self::utf8($s);
        return (int) preg_match_all('/./su', $s) + (int) preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $s);
    }

    /** 截到 ≤$n 个 UTF-16 码元，不拆开代理对 */
    public static function cut(string $s, int $n): string
    {
        $s = self::utf8($s);
        if (strlen($s) <= $n) {
            return $s;
        }
        $out = '';
        $units = 0;
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $units += strlen($ch) === 4 ? 2 : 1;
            if ($units > $n) {
                break;
            }
            $out .= $ch;
        }
        return $out;
    }

    private static function text(mixed $v): string
    {
        if (is_string($v)) {
            return self::utf8($v);
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_scalar($v) || (is_object($v) && method_exists($v, '__toString'))) {
            return self::utf8((string) $v);
        }
        throw new \InvalidArgumentException('TapCub: expected a string');
    }

    public static function requireText(string $name, mixed $v, ?int $max = null): string
    {
        $s = $v === null ? '' : trim(self::text($v));
        if ($s === '') {
            throw new \InvalidArgumentException("TapCub: {$name} is required");
        }
        if ($max !== null && self::utf16Length($s) > $max) {
            throw new \InvalidArgumentException("TapCub: {$name} must be at most {$max} characters");
        }
        return $s;
    }

    public static function optionalText(string $name, mixed $v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim(self::text($v));
        if ($s === '') {
            return null;
        }
        if (self::utf16Length($s) > $max) {
            throw new \InvalidArgumentException("TapCub: {$name} must be at most {$max} characters");
        }
        return $s;
    }

    public static function eventName(mixed $v): string
    {
        $s = $v === null ? '' : trim(self::text($v));
        if ($s === '') {
            throw new \InvalidArgumentException('TapCub: event is required');
        }
        return self::cut($s, 100);
    }

    public static function loginIdType(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!in_array($v, self::LOGIN_ID_TYPES, true)) {
            throw new \InvalidArgumentException('TapCub: loginIdType must be one of ' . implode(', ', self::LOGIN_ID_TYPES));
        }
        return $v;
    }

    /** email 小写、mobile 去空格与 -，再 SHA-256；已是 64 位十六进制的原样保留 */
    public static function identityValue(string $type, mixed $value): string
    {
        $v = $value === null ? '' : trim(self::text($value));
        if ($type === 'email' || $type === 'mobile') {
            $n = $type === 'email' ? strtolower($v) : (string) preg_replace('/[\s-]/u', '', $v);
            return preg_match('/^[a-fA-F0-9]{64}$/', $n) === 1 ? strtolower($n) : hash('sha256', $n);
        }
        return $v;
    }

    public static function identities(?array $v): \stdClass
    {
        $out = new \stdClass();
        foreach ($v ?? [] as $k => $raw) {
            $k = (string) $k;
            if (!in_array($k, self::LOGIN_ID_TYPES, true)) {
                throw new \InvalidArgumentException("TapCub: unknown identity type \"{$k}\"");
            }
            if ($raw === null || trim(self::text($raw)) === '') {
                continue;
            }
            $out->{$k} = self::identityValue($k, $raw);
        }
        return $out;
    }

    public static function group(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = self::text($v);
        if (preg_match(self::GROUP_KEY_RE, $s) !== 1) {
            throw new \InvalidArgumentException('TapCub: group must look like "type:id" (type: a-z, 0-9, _)');
        }
        return $s;
    }

    public static function groupKey(mixed $type, mixed $id): string
    {
        $t = self::requireText('groupType', $type);
        if (preg_match(self::GROUP_TYPE_RE, $t) !== 1) {
            throw new \InvalidArgumentException('TapCub: groupType must start with a-z and contain only a-z, 0-9 and _ (at most 24)');
        }
        return (string) self::group($t . ':' . self::requireText('groupId', $id));
    }

    public static function time(mixed $v): int
    {
        if ($v === null) {
            return (int) floor(microtime(true) * 1000);
        }
        if ($v instanceof \DateTimeInterface) {
            return (int) $v->format('Uv');
        }
        if (is_int($v) || (is_float($v) && is_finite($v))) {
            return (int) $v;
        }
        throw new \InvalidArgumentException('TapCub: time must be milliseconds since the epoch or a DateTimeInterface');
    }

    public static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    private static function isMap(mixed $v): bool
    {
        return (is_array($v) && $v !== [] && !self::isList($v)) || $v instanceof \stdClass;
    }

    private static function jsonText(mixed $v): string
    {
        return (string) json_encode($v, self::JSON_FLAGS | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** @return string|int|float|bool|null null = 丢弃 */
    private static function scalar(mixed $v): string|int|float|bool|null
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v) || is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? $v : null;
        }
        if (is_string($v)) {
            return self::cut($v, 1000);
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format(DATE_ATOM);
        }
        if (is_array($v) || $v instanceof \JsonSerializable || $v instanceof \stdClass) {
            return self::cut(self::jsonText($v), 1000);
        }
        if (is_object($v) && method_exists($v, '__toString')) {
            return self::cut((string) $v, 1000);
        }
        return self::cut(self::jsonText($v), 1000);
    }

    public static function properties(mixed $p): ?\stdClass
    {
        if ($p === null || $p === []) {
            return null;
        }
        if ($p instanceof \stdClass) {
            $p = get_object_vars($p);
        }
        if (!is_array($p)) {
            throw new \InvalidArgumentException('TapCub: properties must be an associative array');
        }
        $out = new \stdClass();
        foreach ($p as $k => $v) {
            $key = self::cut((string) $k, 50);
            if (is_array($v) && $v !== [] && self::isList($v) && count(array_filter($v, static fn ($x) => self::isMap($x))) === count($v)) {
                $rows = [];
                foreach (array_slice($v, 0, 100) as $o) {
                    $row = new \stdClass();
                    foreach ((array) $o as $ik => $iv) {
                        $s = self::scalar($iv);
                        if ($s !== null) {
                            $row->{self::cut((string) $ik, 50)} = $s;
                        }
                    }
                    $rows[] = $row;
                }
                $out->{$key} = $rows;
                continue;
            }
            $s = self::scalar($v);
            if ($s !== null) {
                $out->{$key} = $s;
            }
        }
        return get_object_vars($out) === [] ? null : $out;
    }

    public static function increment(?array $p): \stdClass
    {
        $out = new \stdClass();
        foreach ($p ?? [] as $k => $v) {
            if (is_int($v) || (is_float($v) && is_finite($v))) {
                $out->{self::cut((string) $k, 50)} = $v;
            }
        }
        return $out;
    }

    public static function append(?array $p): \stdClass
    {
        $out = new \stdClass();
        foreach ($p ?? [] as $k => $v) {
            $list = is_array($v) ? array_values($v) : [$v];
            $keep = array_values(array_filter($list, static fn ($x) => is_string($x) || is_bool($x) || is_int($x) || (is_float($x) && is_finite($x))));
            if ($keep !== []) {
                $out->{self::cut((string) $k, 50)} = array_slice($keep, 0, 20);
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function unsetKeys(mixed $keys): array
    {
        if (!is_array($keys)) {
            throw new \InvalidArgumentException('TapCub: keys must be a list of property names');
        }
        $out = [];
        foreach ($keys as $k) {
            if ($k !== null && (string) $k !== '') {
                $out[] = self::cut((string) $k, 50);
            }
        }
        return array_slice($out, 0, 50);
    }

    public static function campaign(mixed $c): ?\stdClass
    {
        if ($c instanceof \stdClass) {
            $c = get_object_vars($c);
        }
        if (!is_array($c)) {
            return null;
        }
        $out = new \stdClass();
        foreach (self::CAMPAIGN_LIMITS as $k => $max) {
            if (isset($c[$k]) && (string) $c[$k] !== '') {
                $out->{$k} = self::cut(self::text($c[$k]), $max);
            }
        }
        if (isset($c['touched_at']) && (is_int($c['touched_at']) || (is_float($c['touched_at']) && is_finite($c['touched_at'])))) {
            $out->touched_at = (int) $c['touched_at'];
        }
        return get_object_vars($out) === [] ? null : $out;
    }

    public static function context(mixed $c): ?\stdClass
    {
        if ($c instanceof \stdClass) {
            $c = get_object_vars($c);
        }
        if (!is_array($c)) {
            return null;
        }
        $out = new \stdClass();
        foreach (['ip' => 45, 'user_agent' => 1000, 'url' => 2000] as $k => $max) {
            if (isset($c[$k]) && (string) $c[$k] !== '') {
                $out->{$k} = self::cut(self::text($c[$k]), $max);
            }
        }
        if (isset($c['platform']) && in_array($c['platform'], self::PLATFORMS, true)) {
            $out->platform = $c['platform'];
        }
        if (isset($c['country']) && is_string($c['country']) && preg_match('/^[A-Za-z]{2}$/', $c['country']) === 1) {
            $out->country = strtoupper($c['country']);
        }
        if (isset($c['lang']) && (string) $c['lang'] !== '') {
            $out->lang = self::cut(self::text($c['lang']), 12);
        }
        $camp = self::campaign($c['campaign'] ?? null);
        if ($camp !== null) {
            $out->campaign = $camp;
        }
        return get_object_vars($out) === [] ? null : $out;
    }

    /** 去掉 null 字段 */
    public static function compact(array $ev): array
    {
        return array_filter($ev, static fn ($v) => $v !== null);
    }
}
