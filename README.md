# tapcub/tapcub-php

Server-side SDK for [TapCub](https://tapcub.com), MIT licensed. Requires PHP 8.0+ with the curl and json extensions.

> This repository is a read-only mirror. Development happens in the TapCub main repository and is synced here as snapshots. Please open an issue for bugs and suggestions.

```bash
composer require tapcub/tapcub-php
```

```php
use TapCub\TapCub;

$tc = new TapCub('<server API key>', 'https://api.pathclue.com');
$tc->track('purchase', ['loginId' => 'u_1001', 'properties' => ['revenue' => 19.9]]);
```

## Releases

Versions are git tags such as `v0.1.0`, pushed from the TapCub main repository with `pnpm sdk:sync -- --push --tag php-v0.1.0`. Packagist picks up new tags automatically through its GitHub integration.

## License

MIT, see [LICENSE](LICENSE).
