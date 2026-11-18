# SecondRail for WooCommerce

A WooCommerce payment gateway that takes card payments through SecondRail's hosted payment page.
The shopper pays on SecondRail's page and comes back to the store's order-received page; no card
field is ever rendered on the store. The order is marked paid from the SecondRail API (on return,
by webhook, or by a scheduled check), never from what the browser says.

The shippable plugin is the folder [`secondrail-for-woocommerce/`](secondrail-for-woocommerce/).
Everything else is development tooling. The contract for version 0.1.0 is
[`docs/plan.md`](docs/plan.md); the run that implemented it is [`docs/report.md`](docs/report.md).

## Requirements

Docker with Compose. Nothing else runs on the host, except the opt-in browser tests (`bin/e2e`),
which use the host's Node and the system Chromium (`/snap/bin/chromium`).

## Development stack

```bash
bin/setup          # idempotent: .env, MariaDB, WordPress, WooCommerce 11.3.0, the plugin, a test store
bin/dc ps          # docker compose with the right files
bin/wp option get woocommerce_currency
bin/dc stop        # stop the stack
```

WordPress answers on `http://localhost:${WP_PORT}` (8190 by default, `127.0.0.1` only). The admin
user is `admin`; its password is in `.env` (mode 0600, git-ignored). The store has one virtual
product at 19.99, the block checkout at `/checkout/` and the classic checkout at
`/classic-checkout/`.

| Service | Image | Notes |
| --- | --- | --- |
| `db` | `mariadb:11.4.13` | 256 MB; also holds the `wordpress_test` database for PHPUnit |
| `wordpress` | `wordpress:7.1.4-php8.3-apache` | 512 MB; Apache capped at 4 workers; the plugin mounted read-only |
| `tools` | `docker/tools/Dockerfile` | wp-cli, Composer, PHP 8.3; profile `tools` |
| `node` | `node:26.13.0-trixie-slim` | `npm ci` for `tests/e2e`; profile `tools` |

## Quality gates

```bash
bin/check              # all five, in this order
bin/check lint         # PHPCS: WordPress Coding Standards and PHPCompatibilityWP (PHP 7.4+)
bin/check phpunit      # WordPress + WooCommerce test suite on the wordpress_test database
bin/check plugin-check # WordPress.org Plugin Check
bin/check shell        # ShellCheck over bin/*
bin/check build        # dist/secondrail-for-woocommerce-0.1.0.zip, every PHP file linted
```

PHPUnit never reaches the network: `tests/phpunit/class-http-stub.php` answers every request
through `pre_http_request` and fails the test on a request nobody queued.

## Real-sandbox run

Against the SecondRail development stack in `../orqbit` (it must be running):

```bash
SECONDRAIL_MERCHANT=demo bin/sandbox connect   # API base and a sandbox key into .env, settings, webhook endpoint, test event
bin/e2e               # Playwright specs (host Node, system Chromium, one worker)
bin/sandbox clean     # delete the endpoint, revoke the key, remove both lines from .env
bin/dc stop
```

`SECONDRAIL_MERCHANT` is the merchant the store charges for. Test runs use `demo`, the platform's
test merchant. Without it the store connects to `honeypacks`, the merchant used for demos, so
orders placed by hand show up in that merchant's dashboard.

`bin/sandbox connect` attaches WordPress to the SecondRail network (`compose.sandbox.yml`) so the
SecondRail worker can deliver webhooks to `http://plugin-secondrail-wp/?wc-api=secondrail_webhook`.

## Layout

```
secondrail-for-woocommerce/   the plugin
tests/phpunit/                PHPUnit: bootstrap, wp-tests-config.php, Http_Stub, tests
tests/e2e/                    Playwright specs
docker/                       tools image, test database init, Apache limits
bin/                          dc, setup, wp, composer, check, sandbox, e2e
```
