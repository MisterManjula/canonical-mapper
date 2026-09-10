# canonical-mapper
Maps three incompatible point-of-sale formats (JSON, XML, CSV) into one canonical menu model. Every source produces identical output; ambiguous data is withheld and flagged, never guessed.

**Work in progress.** The scaffold is in place and the domain is not: this README
grows into its final shape as the model, the adapters and the CLI arrive.

---

## Running it

Everything runs inside the container. There is nothing to install on the host
beyond Docker — no PHP, no Composer.

```
docker compose build
docker compose run --rm app composer install
docker compose run --rm app vendor/bin/phpunit
docker compose run --rm app vendor/bin/phpstan analyse
```

`composer install` reads `composer.lock`, which is committed: the central claim of
this repository is a static-analysis result, and a floating dependency version
would make that result a statement about one afternoon rather than a reproducible
one. After editing `composer.json`, run `composer update` instead; no image rebuild
is needed, because the image contains no application code.

The container runs as root, so generated files are root-owned on a Linux host.
Harmless for a throwaway toolbox, and worth knowing before it is a surprise.

---

## Stack

PHP 8.3 · Docker Compose · PHPUnit 11 · PHPStan at level max, no baseline
