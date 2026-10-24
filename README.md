# tapcub/tapcub-php

Server-side SDK for [TapCub](https://tapcub.com) on PHP: business events, sign-ups, identities, user profiles, items and accounts, sent to `POST /api/v1/track`. No dependencies beyond the curl and json extensions.

- Sends on every call (default) or batches until the end of the request
- Retries network errors, 5xx and 429 with exponential backoff, without changing the batch
- Cleans each event before it is sent, so one bad value never rejects a whole batch
- Hashes email and mobile identities (SHA-256) before they leave your server

Requires PHP 8.0 or newer with `ext-curl` and `ext-json` (`ext-zlib` for the gzip option). `mbstring` is not needed.

> This repository is a read-only mirror. Development happens in the TapCub main repository and is synced here as snapshots. Please open an issue for bugs and suggestions.

## Install

```bash
composer require tapcub/tapcub-php
```

## Quick start

```php
use TapCub\TapCub;

$tc = new TapCub(getenv('TAPCUB_API_KEY')); // a wck_… key with the track:write scope

$tc->track('purchase', [
    'loginId' => $user->id,
    'insertId' => $order->id, // idempotency key: retries of the same order are counted once
    'properties' => ['revenue' => 99, 'currency' => 'USD', 'items' => [['item_id' => 'sku-1', 'price' => 99, 'quantity' => 1]]],
]);
```

Create the key in the TapCub console under **Configuration › Settings › API keys** with the **Server track** scope. It is shown once; keep it out of the repository.

## Configuration

`new TapCub(string $apiKey, ?string $endpoint = null, array $options = [])`

| Option | Default | Description |
|---|---|---|
| `$endpoint` | built in | Leave unset. Set it only when TapCub gives you a dedicated address (private deployment). A trailing `/api/v1/track` is removed |
| `consumer` | `'sync'` | `'sync'`: send on every call; methods return the server response and throw `TapCubException` on failure. `'batch'`: queue and send when `maxBatch` is reached, on `flush()` / `close()` and at the end of the request. `'debug'`: like `'sync'` against `?debug=1` (validated, nothing stored), every result is logged |
| `maxBatch` | `200` | Events per request (at most 1000). Requests are also kept under 1 MB |
| `maxQueueSize` | `10000` | Queued events kept in memory; the oldest are dropped beyond this |
| `maxRetries` | `5` | Retries for network errors, 5xx and 429 (honours `Retry-After`) |
| `timeout` | `10.0` | Seconds per request |
| `gzip` | `false` | Send `Content-Encoding: gzip` |
| `onError` | `error_log` | `callable(TapCubException)`, called for failures in batch mode that nobody caught (end of request, full batch) |
| `logger` | `error_log` | Any PSR-3 style object with `warning()` and `error()` |

## API

Invalid arguments throw `InvalidArgumentException` and send nothing.

| Method | Event |
|---|---|
| `track($event, ['loginId', 'loginIdType', 'deviceId', 'sessionId', 'insertId', 'time', 'group', 'properties', 'context'])` | A business event |
| `trackSignup($loginId, ['deviceId', 'invitedBy', 'properties', 'context', …])` | `sign_up`; links the anonymous device to the new login ID |
| `bind($loginId, ['email' => …, 'mobile' => …, 'unionid' => …])` | Attach more identities (email / mobile hashed in the SDK) |
| `unbind($loginId, $identityType, $value)` | Remove one identity |
| `profileSet($loginId, $props)` / `profileSetOnce($loginId, $props)` | Set profile properties (`$name`, `$email`, `$phone`, `$avatar` are display fields) |
| `profileIncrement($loginId, ['key' => delta])` / `profileAppend($loginId, ['key' => [values]])` / `profileUnset($loginId, [keys])` | Change profile properties |
| `itemSet($itemType, $itemId, $props)` / `itemDelete($itemType, $itemId)` | Dimension rows (products, articles, stores) |
| `trackUpdate($event, $insertId, $props)` / `trackOverwrite($event, $insertId, $props)` | Merge into / replace the properties of a stored event |
| `groupSet($groupType, $groupId, $props)` | Account (B2B group) traits; attach events with `'group' => 'company:acme'` |
| `flush()` / `close()` | Send the queue now; throw the first `TapCubException` |
| `TapCub::fromRequestHeaders()` | `['deviceId' => …, 'sessionId' => …]` from `X-Tc-Device` / `X-Tc-Session` (reads `$_SERVER`) |

`time` is milliseconds or a `DateTimeInterface` and defaults to the time of the call. Properties are flat: strings (cut to 1000 characters), numbers and booleans; dates become ISO-8601 strings; a list of arrays such as `items` is kept one level deep; other nested values are sent as JSON strings. Empty or numeric-keyed arrays are always sent as JSON objects.

### Record where a sign-up came from

The browser script already knows the visit source (`tapcub.getAttribution()`). Send it to your backend with the sign-up form and pass it through unchanged:

```php
// Laravel controller
public function store(Request $request)
{
    $user = User::create($request->validated());
    app(TapCub::class)->trackSignup((string) $user->id, [
        'deviceId' => $request->input('tapcubDeviceId'), // optional: links the anonymous visits
        'invitedBy' => $request->input('inviterId'),     // optional: referral program → properties.$invited_by
        'context' => [
            'campaign' => $request->input('attribution'), // [source, medium, name, term, content, ref, click_id, landing_url, referrer, touched_at]
            'ip' => $request->ip(),                       // ip + user_agent let TapCub infer the source when there is no cookie
            'user_agent' => $request->userAgent(),
        ],
    ]);
    return response()->json(['ok' => true]);
}
```

## Batching

PHP has no long-lived process per request, so `sync` is the default. With `'consumer' => 'batch'` events are kept until `maxBatch` is reached, you call `flush()`, the client is destroyed, or the request ends (a shutdown function); call `fastcgi_finish_request()` first under PHP-FPM if the extra request at the end must not delay the response. In queue workers and long-running processes (Laravel Octane, RoadRunner, Swoole) call `flush()` at the end of each job.

## Idempotency

The SDK resends a failed batch unchanged, but it does **not** generate `insertId` for you. Pass a natural key (order ID, payment ID, ≤36 characters) for events that must not be counted twice; the server stores one event per `insertId`.

## Errors

```php
use TapCub\TapCubException;

try {
    $tc->track('purchase', ['loginId' => 'u1', 'insertId' => 'o1']);
} catch (TapCubException $e) {
    $log->warning('tapcub', ['code' => $e->getErrorCode(), 'status' => $e->getStatus(), 'lost' => count($e->getEvents())]);
}
```

`getErrorCode()` is the server code (`NO_API_KEY`, `BAD_API_KEY`, `VALIDATION`, …), `HTTP_<status>`, `NETWORK`, `EVENT_REJECTED` (the request succeeded but some events were rejected; see `getResults()`) or `QUEUE_FULL`. 4xx responses other than 429 are not retried.

## Check your integration

```php
(new TapCub($apiKey, null, ['consumer' => 'debug']))->track('purchase', ['loginId' => 'u1', 'properties' => ['revenue' => 1]]);
// logs "debug #0 ok" (or the warnings) and stores nothing
```

## Releases

Versions are git tags such as `v0.1.0`, pushed from the TapCub main repository; Packagist picks them up automatically. See [CHANGELOG.md](CHANGELOG.md).

## Documentation

[docs.tapcub.com → Server SDKs](https://docs.tapcub.com/en/sdk/server#php). Tests run the conformance vectors shared by every TapCub server SDK (`test-vectors/track`): `composer install && vendor/bin/phpunit`.

## License

MIT, see [LICENSE](LICENSE).
