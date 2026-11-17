# SecondRail for WooCommerce · contract for version 0.1.0

Spec-driven contract, in the format the SecondRail platform uses for its phases. Read this file top
to bottom before writing code. Every requirement has scenarios; every scenario becomes at least one
test with the scenario id in its name or docblock.

Everything in this repository is written in English: code, comments, documents, file names and
user-visible strings (which go through the WordPress translation functions).

## Goal

A WooCommerce payment gateway that takes card payments through SecondRail's hosted payment page:

1. At checkout (classic and block) the shopper is sent to the hosted page, pays there, and comes
   back to the store's order-received page. No card field is ever rendered on the store.
2. The order is marked paid from the SecondRail API, never from what the browser says, and also
   when the shopper never comes back (webhook, then a scheduled check).
3. The merchant refunds from the WooCommerce order screen.
4. The whole development environment runs in Docker, in this repository.

## Context (verified on 2026-11-13)

| # | Fact | Source |
| --- | --- | --- |
| 1 | WooCommerce 11.3.0 is the current release (2026-11-11); it requires WordPress 7.0 and PHP 7.4. WordPress is at 7.1.4. | `api.wordpress.org` |
| 2 | The block checkout needs a payment method registered on both sides: a PHP class extending `AbstractPaymentMethodType` and a script calling `registerPaymentMethod` from `window.wc.wcBlocksRegistry`. The payment is still processed by `WC_Payment_Gateway::process_payment`, which returns a `redirect`. | developer.woocommerce.com, "Payment method integration" |
| 3 | WooCommerce asks gateways to make `process_payment` safe to repeat: one idempotency key per order attempt, the provider's id saved on the order, a status lookup before charging again, **On hold** when the provider will confirm later, and the paid amount checked against the order total. | developer.woocommerce.com, "Payment Gateway API", section "Retries and uncertain outcomes" |
| 4 | SecondRail's hosted payment link accepts `external_id`, `metadata`, `auto_return` and `cancel_url`, copies the order data to the payment and returns the shopper to `return_url` with `payment_id` and `status`. | Platform repository, `docs/plan/phases/F24-hosted-checkout-for-plugins.md` |
| 5 | SecondRail supports USD, EUR, GBP, CAD, MXN and PEN, all with two decimals. Every write needs an `Idempotency-Key`. Webhooks are signed `t=<unix>,v1=<hex>[,v0=<hex>]` with HMAC-SHA256 over `"<t>.<raw body>"`. | Platform repository, `backend/apps/core/money.py`, `docs/plan/03-api-and-contracts.md` §3 and §10 |
| 6 | The WordPress test suite runs on PHPUnit 9.6 with `yoast/phpunit-polyfills` 1.x. | `wordpress-develop` `composer.json` |
| 7 | This machine: 2 vCPU, 7.6 GB with under 1 GB free and the swap full, 6 GB of disk free, ARM64, Docker 29 and Compose 5. No PHP or Composer on the host. Node 24 and the system Chromium (`/snap/bin/chromium`) exist on the host. | Inspected |
| 8 | From a new container, `https://<SecondRail host>/api/v1/health` answers 200. The `honeypacks` sandbox merchant has the `allow_insecure_sandbox_webhooks` flag on and no webhook endpoint (limit 16). | Inspected |

## Preconditions

- Docker and Compose on the host.
- For the real-sandbox run only (R11): the SecondRail development stack running at
  `../orqbit` with phase F24 in its working tree.

## Out of scope for 0.1.0

- Card fields on the store (`secondrail.js`), saved cards, WooCommerce Subscriptions, PayPal,
  wallets, manual capture from WooCommerce, disputes.
- Creating a WooCommerce refund for a refund made in the SecondRail console (it becomes an order
  note).
- Publishing to WordPress.org, brand assets, a gateway icon, translations files.
- CI, a Git remote, and support for WooCommerce versions that were not run here.
- Any change to SecondRail beyond F24. If the plugin needs something the API does not give, stop
  that requirement and write it in the report.

## Fixed decisions

| # | Decision |
| --- | --- |
| a | **Names.** Plugin "SecondRail for WooCommerce"; slug, folder and text domain `secondrail-for-woocommerce`; gateway id `secondrail`; PHP namespace `SecondRail\WooCommerce`; options prefixed `secondrail_wc_`; order meta prefixed `_secondrail_`. |
| b | **The shippable plugin is the folder `secondrail-for-woocommerce/`.** Everything else in the repository is development tooling and never ships. The zip is that folder and nothing more. |
| c | **No build step and no runtime dependency.** Plain PHP that parses on 7.4 and one plain JavaScript file that uses the globals WooCommerce exposes (`window.wc.wcBlocksRegistry`, `window.wc.wcSettings`, `window.wp.element`, `window.wp.htmlEntities`). Composer and npm install development tools only. |
| d | **Redirect flow only**, through `POST /v1/action_links` with `purpose=payment_link` and `auto_return=true`. |
| e | **One function decides an order's state**: `Reconciler::reconcile( $order )`. It reads the order's payments from the API and applies the table in R4. The return handler, the webhook handler, the scheduled check and `process_payment` all call it. The return URL's `status` and a webhook body are triggers, never facts. |
| f | **Order binding.** `external_id` is the order number. `metadata` carries `wc_order_id`, `wc_site` (a random UUID created once per install, option `secondrail_wc_site_id`), `source` = `woocommerce` and `plugin_version`. The plugin uses a payment only when `wc_site` and `wc_order_id` both match. |
| g | **API base URL is a required setting with no default**, because the production host is not final. It includes the version segment (`https://api.example.com/v1`). Every request sends `SecondRail-Version: 2026-11-01`. |
| h | **Two keys and a mode switch**: "Test mode", "Test secret key", "Live secret key". The webhook endpoint id and secret are stored per mode in `secondrail_wc_webhook_test` and `secondrail_wc_webhook_live` (not autoloaded), never in the settings form. |
| i | **The webhook endpoint is registered by the plugin** (`POST /v1/webhook_endpoints`) for the eight events that carry an outcome: `payment.succeeded`, `payment.failed`, `payment.cancelled`, `payment.authorized`, `payment.captured`, `payment.unknown`, `refund.succeeded` and `refund.failed`. `payment.created` is left out on purpose: it reaches the store while the charge is still in flight (R4). Its URL is `WC()->api_request_url( 'secondrail_webhook' )` unless the constant `SECONDRAIL_WC_WEBHOOK_URL` is defined (sites behind a proxy or tunnel, and this repository's sandbox run). |
| j | **A link lives 30 minutes** (`expires_in=1800`), half of WooCommerce's default stock hold, and every new attempt revokes the previous link first. |
| k | **Development stack: Docker Compose, pinned images**, no `wp-env` (it needs Node on the host and starts two WordPress and two databases, which this machine cannot afford). |
| l | **Browser tests use the host's Node and the system Chromium**, the arrangement of the platform's `frontend/e2e/run.sh`. No browser image is pulled. This is the only piece that does not run in a container, and it is opt-in. |
| m | **PHP 7.4 is enforced statically** with `PHPCompatibilityWP` (`testVersion` `7.4-`). The runtime here is PHP 8.3. |
| n | **Supported claim is what was run**: `Requires at least: 7.0`, `Requires PHP: 7.4`, `WC requires at least: 11.0`, `WC tested up to: 11.3`. |

## SecondRail contract used

Base `<api_base>` (decision g). Headers on every request: `Authorization: Bearer <secret key>`,
`Accept: application/json`, `SecondRail-Version: 2026-11-01`,
`User-Agent: SecondRail-WooCommerce/<version>`; on writes also `Content-Type: application/json`
and `Idempotency-Key`. Every response is the envelope `{code, message, data, meta}`; success is a
2xx status with `code` `ok`; `meta.request_id` goes to the log.

| Call | Used for | Notes |
| --- | --- | --- |
| `GET /account` | "Test connection" on save | `name`, `livemode`, `key.scopes`. |
| `POST /action_links` | Start a payment | Body in R3. Response `id` (`lnk_…`), `url`, `status`, `auto_return`, `external_id`. |
| `POST /action_links/{id}/revoke` | Retire the previous link | 409 `invalid_state` and 404 are not errors here. |
| `GET /payments?external_id=<n>&limit=100` | Reconcile | Each payment: `id`, `status`, `amount`, `currency`, `metadata`, `card.brand`, `card.last4`, `error.message`, `created_at`. |
| `POST /payments/{id}/cancel` | Close an abandoned 3DS | The payment may come back `succeeded`: always reconcile again afterwards. |
| `POST /refunds` | Refund | `payment`, `amount`, `reason`, `note`, `metadata`. 409 `exceeds_refundable`, 409 `invalid_state`, 422 `unsupported_operation`, 503 `provider_unavailable`. The answer is 201 whatever the refund's outcome: its `status` can be `pending`, `processing`, `succeeded`, `unknown`, and also `failed` or `cancelled` when the processor refuses it at once (verified in the platform's `apps/payments/services/refunds.py`). |
| `GET /payments/{id}` | Find the order of a console refund | |
| `POST /webhook_endpoints` | Register the webhook | `url`, `description`, `event_types`. Returns `secret` once. 400 `validation_error` on a URL SecondRail refuses; 409 `invalid_state` at 16 endpoints. |
| `DELETE /webhook_endpoints/{id}` | Replace or remove it | Best effort. |

Idempotency (verified in the platform's `apps/payments/idempotency.py`): an answer is stored and
replayed for 24 hours to the same key and body, a 201 that carries a refused refund included. The
same key with another body is 409 `idempotency_conflict`; while the first request is still running
it is 409 `idempotency_in_progress`. A 4xx raised before anything was created is not stored.

Payment statuses: `created`, `requires_action`, `processing`, `authorized`, `succeeded`, `failed`,
`cancelled`, `unknown`. Key scopes the merchant must grant: `payments:read`, `payments:write`,
`refunds:read`, `refunds:write`, `action_links:write`, `webhooks:read`, `webhooks:write`.

## Repository layout

```
secondrail-for-woocommerce/          the plugin (decision b)
  secondrail-for-woocommerce.php     headers, compatibility declarations, bootstrap
  includes/class-api-client.php      HTTP, envelope, Api_Error
  includes/class-gateway.php         WC_Payment_Gateway: settings, process_payment, process_refund
  includes/class-reconciler.php      order state from the API (R4), scheduled check
  includes/class-webhook.php         signature, webhook and return handlers, endpoint registration
  includes/class-blocks-support.php  AbstractPaymentMethodType
  assets/js/blocks.js                registerPaymentMethod
  readme.txt  uninstall.php  LICENSE
tests/phpunit/                       bootstrap, wp-tests-config.php, Http_Stub, test classes
tests/e2e/                           Playwright: package.json, config, fixtures, specs
docker/tools/Dockerfile              PHP 8.3 + wp-cli + Composer
docker/db/  docker/wordpress/        test database init, Apache worker limits
bin/                                 dc, setup, wp, composer, check, sandbox, e2e
compose.yml  compose.sandbox.yml  .env.example
composer.json  composer.lock  phpcs.xml.dist  phpunit.xml.dist
docs/plan.md  docs/report.md  README.md  AGENTS.md  CHANGELOG.md  .gitignore
```

Five classes are the ceiling. Add a file only when a requirement cannot live in these.

## Development stack

| Service | Image | Notes |
| --- | --- | --- |
| `db` | `mariadb:11.4.13` | `--innodb-buffer-pool-size=64M --performance-schema=OFF --max-connections=30`; `mem_limit: 256m`; health check; named volume; an init script creates the database `wordpress_test` and grants it to the WordPress user. No published port. |
| `wordpress` | `wordpress:7.1.4-php8.3-apache` | Published on `127.0.0.1:${WP_PORT:-8190}` only; `mem_limit: 512m`; Apache prefork capped at 4 workers; named volume for `/var/www/html`; the plugin folder mounted read-only at `wp-content/plugins/secondrail-for-woocommerce`; `WP_HOME` and `WP_SITEURL` = `http://localhost:${WP_PORT}`; `WP_DEBUG` and `WP_DEBUG_LOG` on; automatic updates off. |
| `tools` (profile `tools`) | `docker/tools/Dockerfile`: `FROM wordpress:cli-2.12.0-php8.3`, Composer copied from `composer:2.11.2`, `zip`, `memory_limit=512M`, `COMPOSER_HOME=/tmp/composer` | Mounts the repository at `/repo`, the WordPress volume and the plugin folder. Runs as `33:33` for `wp` and PHPUnit (the owner of the WordPress volume; PHPUnit therefore writes nothing into `/repo`: no result cache, no coverage files) and as the host user for Composer, PHPCS and the zip (the owner of the repository). |
| `node` (profile `tools`) | `node:26.13.0-trixie-slim` (already on this machine) | Only `npm ci` for `tests/e2e`, as the host user. |

`compose.sandbox.yml` is an override used only for the real-sandbox run: it attaches `wordpress`
to the external network `secondrail_default` with the alias `plugin-secondrail-wp` and defines
`SECONDRAIL_WC_WEBHOOK_URL` as `http://plugin-secondrail-wp/?wc-api=secondrail_webhook`, the
address the SecondRail worker can reach.

`.env` (mode 0600, git-ignored) holds `WP_PORT`, random database and admin passwords created by
`bin/setup`, and for the sandbox run `SECONDRAIL_API_BASE` and `SECONDRAIL_TEST_SECRET_KEY`. No
tracked file ever contains a key, a password or the SecondRail host name.

Scripts (`bash`, `set -euo pipefail`, each a few lines):

| Script | Does |
| --- | --- |
| `bin/dc` | `docker compose` with the right files (adds `compose.sandbox.yml` when `.env` has `SECONDRAIL_API_BASE`). |
| `bin/setup` | Idempotent. Creates `.env`; starts `db` and `wordpress`; waits for the core files; `wp core install`; installs and activates WooCommerce `11.3.0`; activates the plugin; store in the US with USD, guest checkout on, `woocommerce_coming_soon` = `no`, pretty permalinks; one virtual product at 19.99; a page `/classic-checkout/` with `[woocommerce_checkout]` beside the default block checkout page; every other gateway disabled; `composer install`. |
| `bin/wp`, `bin/composer` | The tool inside `tools` with the right user. |
| `bin/check [lint\|phpunit\|plugin-check\|shell\|build]` | Runs one gate, or all five in that order. |
| `bin/sandbox connect\|clean` | R11. |
| `bin/e2e` | R11. |

Development dependencies (latest lines checked on Packagist and npm on 2026-11-13):

| Package | Constraint | Note |
| --- | --- | --- |
| `phpunit/phpunit` | `^9.6` | The WordPress test suite does not run on PHPUnit 10 or later (fact 6). |
| `yoast/phpunit-polyfills` | `^1.1` | The line the WordPress test bootstrap requires. |
| `wp-phpunit/wp-phpunit` | `^7.1` | The WordPress test library; it takes the config path from `WP_PHPUNIT__TESTS_CONFIG`. |
| `squizlabs/php_codesniffer` | `^3.13` | The line WordPress core pins beside WPCS 3.4. Not PHPCS 4. |
| `wp-coding-standards/wpcs` | `^3.4` | |
| `phpcompatibility/phpcompatibility-wp` | `^2.1` | |
| `dealerdirect/phpcodesniffer-composer-installer` | `^1.2` | Registers the standards; allow it in `config.allow-plugins`. |
| `@playwright/test` (npm, in `tests/e2e`) | `1.65.0` | The version the platform's suite uses. |

## Requirements and scenarios

### R1 · The stack starts from nothing and every gate runs in Docker

- **R1.1** On a clean clone, `bin/setup` ends with WordPress answering 200 on
  `http://localhost:${WP_PORT}/`, WooCommerce 11.3.0 and the plugin active, and the store front
  showing the product to a guest. Running it again changes nothing and fails nowhere.
- **R1.2** `bin/check lint` runs PHPCS with `WordPress` (WPCS), `PHPCompatibilityWP`
  (`testVersion` `7.4-`) and the plugin's text domain and prefixes configured, over
  `secondrail-for-woocommerce/`, with no error and no warning.
- **R1.3** `bin/check phpunit` runs the suite inside `tools` against WordPress and WooCommerce
  loaded from the volume and the database `wordpress_test`. `tests/phpunit/wp-tests-config.php`
  names `wordpress_test` and nothing else: the WordPress test bootstrap empties the database it is
  given, so it must never be pointed at `wordpress`. No test reaches the network: `Http_Stub`
  answers through the `pre_http_request` filter, records every request, and fails the test on a
  request nobody queued.
- **R1.4** `bin/check plugin-check` installs the WordPress.org "Plugin Check" plugin and runs it
  on the plugin with no error. Read the exact command from `wp help plugin check`.
- **R1.5** `bin/check shell` runs `koalaman/shellcheck:stable` over `bin/*` with no finding.
- **R1.6** `bin/check build` writes `dist/secondrail-for-woocommerce-0.1.0.zip`. Its listing holds
  only the plugin folder; every PHP file in it passes `php -l`.

### R2 · API client

`Api_Client` wraps `wp_remote_request`: timeout 15 s on reads, 45 s on writes; no redirect
following; JSON in and out. There is one client per mode, built with that mode's key. A write whose
key this contract does not spell out sends a random UUID as `Idempotency-Key`. A non-2xx status, a
`code` other than `ok` or a body that is not the envelope raises `Api_Error` with the HTTP status,
`code`, `message`, `data` and `request_id`. A transport failure raises `Api_Error` with `code`
`network_error`. With "Debug log" on, each call logs method, path, HTTP status, `code` and
`request_id` through `wc_get_logger()` (source `secondrail`); a key, a body or a signature is never
logged.

- **R2.1** A write sends the bearer key, the version header, the user agent, `Idempotency-Key` and
  the JSON body; a read sends no `Idempotency-Key` and no body.
- **R2.2** A 201 envelope returns `data`.
- **R2.3** A 409 `idempotency_conflict` envelope raises `Api_Error` with status 409, that `code`,
  the envelope's `message` and `meta.request_id`.
- **R2.4** A `WP_Error` from the transport, a 502 with an HTML body and a 200 with invalid JSON
  each raise `Api_Error` (`network_error` for the first, `invalid_response` for the others).
- **R2.5** With the debug log on, the log lines of a call contain the path and the request id and
  do not contain the key or the body.
- **R2.6** A trailing slash on the configured base does not double the slash in the URL.

### R3 · Checkout: `process_payment`

The gateway `supports` `products` and `refunds`. It is available only when it is enabled, the base
URL and the current mode's key are set, and the store currency is one of USD, EUR, GBP, CAD, MXN,
PEN. The amount sent is the order total in minor units: `(int) round( total * 100 )`.

Steps for an order:

1. If the order has `_secondrail_link_id`, call the reconciler first. This step talks to the API
   with the key of the order's stored mode; step 2 uses the gateway's current mode.
   - Paid, or waiting for confirmation (R4 rows 1 to 4) → return `success` with the
     order-received URL. No new link.
   - A payment is still open (R4 row 5) → `POST /payments/{id}/cancel` for each (a 409
     `invalid_state` answer is not an error here: a charge in flight cannot be cancelled), then
     reconcile again and re-evaluate from the top once. If one is still open, add the notice
     "We could not confirm your previous payment attempt yet. Try again in a few minutes." and
     return `failure`.
   - Otherwise revoke the previous link.
2. Create the link with `Idempotency-Key` `wc-<wc_site>-<order id>-<attempt>`, where `attempt` is
   `_secondrail_attempt` + 1:

   ```json
   {
     "purpose": "payment_link",
     "amount": 1999,
     "currency": "USD",
     "description": "Order 1001 - Store name",
     "external_id": "1001",
     "metadata": {"wc_order_id": "57", "wc_site": "<uuid>", "source": "woocommerce", "plugin_version": "0.1.0"},
     "return_url": "<wc-api secondrail_return>?order_id=57&key=wc_order_…",
     "cancel_url": "<checkout page URL>",
     "auto_return": true,
     "expires_in": 1800
   }
   ```

   - `Api_Error` 409 `idempotency_conflict` (the order changed since the lost request) → one more
     try with `attempt` + 1.
   - Any other `Api_Error` → log it, add the notice "We could not start the payment. Please try
     again.", return `failure`. Nothing is saved, so the next submit repeats the same key.
   - A response whose `auto_return` is not `true` or whose `external_id` is not the one sent means
     the API predates F24: revoke the link, log "This SecondRail API does not support hosted
     checkout fields", return `failure`.
3. Save `_secondrail_link_id`, `_secondrail_attempt` and `_secondrail_mode`; add the order note
   "SecondRail payment page opened (lnk_…)."; schedule the check of R4; return `success` with the
   link `url` as `redirect`. The order stays **Pending payment** and the cart is not emptied here.

Scenarios:

- **R3.1** First submit: one `POST /action_links` with exactly that body and key; the three meta
  values saved; `redirect` is the link URL; the order is pending.
- **R3.2** The API fails on the link: `failure`, one error notice, no meta, no note.
- **R3.3** Second submit, previous payments all `failed`: the previous link is revoked, a new one
  is created with attempt 2 in the key, and `_secondrail_link_id` is the new id.
- **R3.4** Second submit, a previous payment `succeeded` for the right amount: no revoke, no new
  link; the order is paid; `redirect` is the order-received URL.
- **R3.5** Second submit, a previous payment `unknown`, or `processing` for more than two minutes:
  no new link; the order is on hold; `redirect` is the order-received URL.
- **R3.6** Second submit, a previous payment `requires_action`: it is cancelled, the reconciler
  runs again, and a new link is created. If the cancel returns the payment `succeeded`, R3.4
  applies instead.
- **R3.7** `idempotency_conflict` on the link: a second request goes out with the next attempt in
  the key, and that attempt is the one saved.
- **R3.8** A response without `auto_return: true`: the link is revoked and the result is `failure`.
- **R3.9** Total 19.99 → 1999; 0.07 → 7; 1234.50 → 123450. Store currency `JPY` → the gateway is
  not available.
- **R3.10** A 404 or a 409 `invalid_state` on revoke does not stop the new link.
- **R3.11** Second submit while a charge is in flight (a `processing` payment created seconds
  ago): the cancel answers 409 `invalid_state`, no link is revoked or created, the notice "We could
  not confirm your previous payment attempt yet. Try again in a few minutes." is added, the result
  is `failure`, and the order is still pending.

### R4 · Reconciler and scheduled check

`reconcile( $order )` ignores an order whose payment method is not `secondrail`. It talks to the
API with the key of the order's `_secondrail_mode`, not of the gateway's current mode, so a test
order still reconciles after the store goes live. It lists the payments with the order number as
`external_id`, keeps those whose `wc_site` and `wc_order_id` match (decision f), and applies the
first row that fits:

| # | Payments of the order | Effect | Returns |
| --- | --- | --- | --- |
| 1 | The order is already paid | A `succeeded` payment other than `_secondrail_payment_id` adds, once, the note "Second successful payment pay_… for this order. Refund it in SecondRail." | `paid` |
| 2 | One or more `succeeded` | Take the earliest. Amount and currency equal the order's → save `_secondrail_payment_id`, `payment_complete( pay id )`, note with card brand and last 4. They differ → save the id, set **On hold**, note "Paid amount differs from the order total. Review before fulfilling." Any further `succeeded` payment gets the note of row 1. | `paid` or `review` |
| 3 | One `authorized` | **On hold**, set `_secondrail_waiting`, note "Authorized in SecondRail; capture it there." | `waiting` |
| 4 | One `unknown`, or one `processing` created more than two minutes ago | **On hold**, set `_secondrail_waiting`, note "Waiting for SecondRail to confirm the payment." | `waiting` |
| 5 | One `requires_action` or `created`, or one `processing` created two minutes ago or less | Nothing | `open` |
| 6 | None, or only `failed` and `cancelled` | An order on hold with `_secondrail_waiting` goes to **Failed** and loses the flag. Each failed payment adds, once, "Payment pay_… failed: <error message>". Otherwise the order is not touched. | `unpaid` |

Rows 1 and 2 clear `_secondrail_waiting`. Notes are added once per payment id (remember the ids
in `_secondrail_noted`). Calling the reconciler twice in a row changes nothing the second time.

**A charge in flight is not a payment waiting.** Every card charge is `processing` for the second
or two the processor takes. A reconcile that lands in that window must not put the order on hold:
WooCommerce would email the shopper "on hold" for a payment about to succeed, and a decline would
then turn the order **Failed**. It happened on every order of the first two sandbox runs, because
the `payment.created` webhook arrived mid-charge. So `processing` counts as waiting only when the
payment's `created_at` is more than 120 seconds old (an unreadable `created_at` counts as old);
before that the payment is open, like `requires_action`. Its id is among the open ids the
reconciler hands back.

**One reconcile per order at a time.** The return and the webhook arrive within a second or two
of each other, each holding its own copy of the order, so without a guard both would run
`payment_complete()` (two status changes, two notes, two emails to the shopper). `reconcile`
therefore takes a per-order lock before it calls the API: the transient
`secondrail_wc_lock_<order id>`, 60 seconds, deleted in a `finally`. When the lock is already held
it raises `Api_Error` with `code` `busy`, sends no request and changes nothing. The callers already
treat an `Api_Error` correctly: the return goes to the order-received page, the scheduled check
runs again later, `process_payment` asks the shopper to try again, and the webhook answers 503 so
SecondRail delivers it again. A transient is not atomic; it narrows the overlap from the length of
an API call to about a millisecond, which is what WooCommerce's own gateways do.

The scheduled check is one Action Scheduler action, `secondrail_wc_reconcile`, group `secondrail`,
with the order id and a run counter. It is scheduled 15 minutes after a link is created, replacing
any check still pending for that order. When the result is `waiting`, `open` or `review`, or the
API failed, it schedules itself again after 1 hour, then 6 hours, then 24 hours, and then stops.

- **R4.1** A `succeeded` payment with the order's amount and currency: the order is paid, the
  transaction id is the payment id, one note. A second call adds nothing.
- **R4.2** A `succeeded` payment with another amount: on hold, the review note, not paid.
- **R4.3** Two `succeeded` payments: paid with the earliest; one "second successful payment" note
  for the other.
- **R4.4** `unknown`, then on a later call `failed`: on hold, then failed, with one failure note.
- **R4.5** Only a `failed` payment on a pending order: still pending; one failure note.
- **R4.6** A payment with the same `external_id` and another `wc_site`, or another `wc_order_id`,
  is ignored.
- **R4.7** An order paid with another gateway is not read and not touched (no request is sent).
- **R4.8** An `authorized` payment: on hold with its note.
- **R4.9** A `succeeded` payment on an order WooCommerce already cancelled: the order is paid.
- **R4.10** Creating a link schedules exactly one action. With `waiting` results the action runs
  four times in all and schedules nothing after the fourth; with `paid` it schedules nothing more.
- **R4.11** With the order's lock held, `reconcile` raises `Api_Error` `busy`, sends no API request
  and leaves the order untouched. The lock is free again after a normal run and after a run in
  which the API failed.
- **R4.12** A `processing` payment created 10 seconds ago: the order stays pending, with no note
  and no `_secondrail_waiting`; the result is `open` and the payment's id is among the open ids.
  The same payment created 3 minutes ago: on hold, result `waiting`. A `processing` payment with
  an unreadable `created_at`: on hold.
- **R4.13** A pending order whose only payment went `processing` (seconds old) and then `failed`
  is still pending after both calls, with one failure note: it was never on hold and is not
  **Failed**.

### R5 · Return from the hosted page

`GET <wc-api secondrail_return>?order_id=&key=[&payment_id=&status=]`.

1. No such order, or `key` does not match the order key (`hash_equals`) → redirect to the shop
   page. Nothing else happens.
2. Reconcile. An `Api_Error` counts as `waiting`.
3. `unpaid` → the error notice "The payment was not completed. You can try again." and a redirect
   to the checkout page. Anything else (`paid`, `review`, `waiting`, or `open`: a charge still in
   flight) → redirect to the order-received URL.

- **R5.1** A wrong key: no API request, redirect to the shop page.
- **R5.2** A right key and a `succeeded` payment: the order is paid; redirect to order-received.
- **R5.3** A right key and only a `failed` payment: one error notice; redirect to checkout.
- **R5.4** `status=succeeded` in the query while the API says `failed`: the order is not paid.
- **R5.5** The API is down: redirect to order-received; the order is still pending.
- **R5.6** The order's lock is held (the webhook is reconciling it): redirect to order-received,
  no API request, the order untouched.
- **R5.7** A right key and a `processing` payment created seconds ago: redirect to
  order-received with no error notice; the order is still pending.

### R6 · Webhook

`POST <wc-api secondrail_webhook>`.

1. Read the raw body. Larger than 256 KB → 400.
2. Parse `SecondRail-Signature`. Accept when `t` is within 300 seconds of now and any `v1` or `v0`
   equals `hash_hmac( 'sha256', "<t>.<raw body>", <secret> )` for the stored test or live secret
   (`hash_equals`). Otherwise 400, with no further work. The secret that matched gives the mode.
3. Type `ping` → 200. An event whose `livemode` contradicts that mode → 200, ignored.
4. `payment.*`: `data.object.metadata` must carry this install's `wc_site`, and `wc_order_id` an
   order paid with `secondrail` whose `_secondrail_mode` is the matched mode; otherwise 200,
   ignored. Then reconcile that order.
5. `refund.*`: find the order from the refund's `metadata` or, when it has none, from
   `GET /payments/{refund.payment}`. `refund.failed` adds, once per refund id, "Refund re_…
   failed in SecondRail: <error message>. Handle it manually." `refund.succeeded` whose `source`
   is not `woocommerce` adds, once, "Refunded <amount> in SecondRail (re_…). Record it in
   WooCommerce if needed." Everything else is ignored.
6. An `Api_Error` while handling → 500, so SecondRail retries; with `code` `busy` (R4, the order's
   lock is held) → 503. Otherwise 200.

- **R6.1** A valid `v1` → 200. A valid `v0` beside a wrong `v1` (secret rotation) → 200.
- **R6.2** A wrong signature, a missing header, a `t` 301 seconds old, a body changed by one byte
  → 400 each, and no API request.
- **R6.3** `ping` → 200 and nothing else.
- **R6.4** `payment.succeeded` for an order of this install → the order is paid.
- **R6.5** `payment.succeeded` with another `wc_site` → 200, no API request, no order touched.
- **R6.6** A live event verified with the test secret → 200, ignored.
- **R6.7** `refund.failed` → one note; the same event again adds none.
- **R6.8** `refund.succeeded` with no metadata → one `GET /payments/{id}`, one note on the order.
  With `source` `woocommerce` → no note.
- **R6.9** The API fails during the reconcile → 500.
- **R6.10** A body of 256 KB + 1 byte → 400.
- **R6.11** `payment.succeeded` while the order's lock is held → 503, and the order is untouched.

### R7 · Refunds: `process_refund`

`POST /refunds`, with the key of the order's `_secondrail_mode`, for the payment in
`_secondrail_payment_id`, the amount in minor units, `reason`
`requested_by_customer`, the merchant's reason text as `note`, `metadata` with `source`,
`wc_order_id` and `wc_site`, and `Idempotency-Key`
`wc-refund-<wc_site>-<order id>-<total refunded on the order, in minor units>-<n>`, where `n` is
the order's `_secondrail_refund_failures` (0 when absent). Repeating the same refund after a lost
answer therefore replays the first one instead of refunding twice.

The API answers 201 whatever the refund's outcome, so the refund's `status` decides the result. A
`status` of `failed` or `cancelled` means no money moved: add one to `_secondrail_refund_failures`
on the order, return a `WP_Error` with the refund's `error.message` (or "SecondRail could not make
the refund." when there is none) and add no "sent" note, so WooCommerce does not record a refund
that did not happen. The counter is what lets the merchant try again: SecondRail replays a stored
answer for 24 hours, a refused refund included, so the same key would return the same refusal
whatever had changed since. Any other status (`pending`, `processing`, `succeeded`, `unknown`)
returns `true`; if such a refund fails later, the `refund.failed` webhook adds its note (R6).

An `Api_Error` never moves the counter. When the outcome cannot be known (`network_error`,
`invalid_response`, any 5xx, 409 `idempotency_conflict`, 409 `idempotency_in_progress`) the
`WP_Error` says "The refund outcome is unknown. Check the payment in SecondRail before trying
again.": the same key must stay in place, so that a retry cannot refund twice. Any other 4xx
carries the envelope's `message`; the platform stored nothing, so a retry is a new request.

- **R7.1** A refund of 5.00 on a paid order: one request with that body and key; returns `true`;
  the note "Refund re_… of 5.00 sent to SecondRail (<status>)."
- **R7.2** 409 `exceeds_refundable` → `WP_Error` carrying the envelope's `message`.
- **R7.3** An order without `_secondrail_payment_id` → `WP_Error`, no request.
- **R7.4** A transport failure, an answer that is not the envelope, and a 503 → each a `WP_Error`
  saying the outcome is unknown and SecondRail must be checked before trying again.
- **R7.5** No amount, or zero → `WP_Error`, no request.
- **R7.6** A 201 whose refund is `failed` (and one that is `cancelled`) → `WP_Error` carrying the
  refund's error message, and no "sent" note. Through `wc_create_refund` with `refund_payment`,
  the order ends with nothing refunded.
- **R7.7** A 201 whose refund is `unknown` → `true`, and the "sent" note shows `(unknown)`.
- **R7.8** After a refused refund of 5.00, a second refund of 5.00 goes out with another
  `Idempotency-Key` (the counter is 1); the API accepts it, and the order shows 5.00 refunded. A
  refund that is accepted, a transport failure and a 409 `exceeds_refundable` leave the counter
  where it was.
- **R7.9** 409 `idempotency_conflict` and 409 `idempotency_in_progress` → the "outcome is unknown"
  `WP_Error`; the counter does not move.

### R8 · Settings and connection

Fields: "Enable", "Title" (default "Credit or debit card"), "Description" (default "You will be
taken to a secure page to complete your payment."), "API base URL", "Test mode", "Test secret key",
"Live secret key", "Debug log". The two key fields use a custom field type whose input always
renders empty, with a placeholder when a key is stored, and which keeps the stored key when it is
submitted empty (`generate_<type>_html` and `validate_<type>_field` of `WC_Settings_API`). Below
the form, a read-only box: the account name and environment, and the webhook's state and URL, with
a "Reconnect webhook" button (nonce and `manage_woocommerce`).

Saving the form, for the current mode: a key must start with `sk_test_` or `sk_live_` to match the
mode; `GET /account` must answer; then the webhook is ensured. Ensuring means: when no endpoint is
stored for the mode, or the stored URL differs from the current one, delete the stored endpoint
(best effort) and create a new one, and store its id, URL and secret. A failure is a settings error
that shows the envelope's `message`; the form is still saved.

- **R8.1** Saving a valid test key: one `GET /account`, one `POST /webhook_endpoints` with the
  webhook URL and exactly the eight event types of decision i; the id and secret land in
  `secondrail_wc_webhook_test`. Saving again sends no second `POST /webhook_endpoints`.
- **R8.2** A live key in the test field → a settings error, and no request.
- **R8.3** `GET /account` answers 401 → a settings error with the message, and no webhook.
- **R8.4** With `SECONDRAIL_WC_WEBHOOK_URL` defined, that URL is the one registered.
- **R8.5** The stored URL differs → `DELETE` of the old endpoint, then one `POST`.
- **R8.6** "Reconnect webhook" without a valid nonce, or by a user without `manage_woocommerce`,
  does nothing.
- **R8.7** A stored key is never printed into the settings page source, saving the form with the
  key field empty keeps the stored key, and the key never appears in the log.

### R9 · Block checkout, compatibility and lifecycle

- **R9.1** The plugin declares compatibility with `custom_order_tables` and `cart_checkout_blocks`
  on `before_woocommerce_init`, and WooCommerce lists it as compatible with both.
- **R9.2** With the gateway enabled, the `secondrail` payment method type is registered and
  active, its script handle is registered with its WooCommerce and WordPress dependencies, and its
  data carries the title, the description and the supported features. With the gateway disabled it
  is not active.
- **R9.3** Without WooCommerce the plugin loads no class and raises no error (the header
  `Requires Plugins: woocommerce` plus a guard in the bootstrap).
- **R9.4** Deactivation deletes the stored webhook endpoints (best effort, no error when the API
  is unreachable) and unschedules the plugin's actions. `uninstall.php` deletes the plugin's
  options. Neither touches orders.
- **R9.5** Every order read and write goes through `wc_get_order` and the `WC_Order` methods; the
  code contains no `get_post_meta`, `update_post_meta` or direct SQL.

### R10 · Packaging and documents

- `readme.txt` in WordPress.org format, with an "External services" section that states which data
  goes to SecondRail and when. The links to SecondRail's terms and privacy policy are an item for
  the owner: do not invent URLs.
- `LICENSE` is the GPL version 2 text, downloaded from gnu.org, not retyped.
- `README.md` (how to run the stack, the gates and the sandbox run), `AGENTS.md` (language rule,
  "everything in Docker", the gates, "never commit `.env`"), `CHANGELOG.md`, `.gitignore`
  (`.env`, `vendor/`, `node_modules/`, `dist/`, test output).
- `docs/report.md`: scenario → test → result, the sandbox run with ids and statuses, decisions
  taken where this contract left room, and what is pending.

- **R10.1** The plugin header has the values of decision n, `Version: 0.1.0`,
  `License: GPL-2.0-or-later`, `Text Domain: secondrail-for-woocommerce` and
  `Requires Plugins: woocommerce`; the version in the header, in `readme.txt` and in the plugin's
  constant are equal.
- **R10.2** No file other than the git-ignored `.env` contains a secret key, a `whsec_` secret, a
  password or the SecondRail host name.

### R11 · Real sandbox

Opt-in, against the SecondRail development stack. It is the proof that the plugin works.

`bin/sandbox connect`:

1. Reads `SECONDRAIL_DOMAIN` and `SECONDRAIL_ROUTING_MODE` from `../orqbit/backend/.env` (those
   two lines only) and writes `SECONDRAIL_API_BASE` to `.env`: `https://<domain>/api/v1` in `path`
   mode, `https://api.<domain>/v1` in `subdomain` mode.
2. Creates a key for the merchant `honeypacks` in sandbox, named `plugin-secondrail-e2e`, with the
   seven scopes of this contract, by running in `../orqbit`
   `docker compose exec -T web-api python manage.py shell -c …` with
   `apps.iam.services.api_keys.create`. The secret is the last line printed; it goes straight into
   `.env` and is never echoed.
3. Restarts `wordpress` with `compose.sandbox.yml`, sets the gateway's settings with `bin/wp`
   (base URL, test mode, the key, enabled) and ensures the webhook through the plugin's own code.
4. Sends the endpoint's test event (`POST /webhook_endpoints/{id}/test`) and expects
   `ok: true`. If WordPress answers with a redirect, make `WP_HOME` and `WP_SITEURL` follow
   `HTTP_HOST` in the sandbox override and try again.

`bin/sandbox clean` deletes the endpoint, revokes the key (`api_keys.revoke`) and removes the two
lines from `.env`.

`bin/e2e` installs `@playwright/test` 1.65.0 in `tests/e2e` with the `node` service on first use
and runs the specs with the host's Node and `CHROMIUM=/snap/bin/chromium`, one worker. Card entry
copies the platform's helper (`fillCardForm` in
`../orqbit/frontend/e2e/support/fixtures.ts`): the three Basis Theory iframes inside
`card-form`, then `card-submit`. Test cards: `4242424242424242` approves, `4000000000000002`
declines. Order state is read with `bin/wp`.

- **R11.1** Classic checkout (`/classic-checkout/`): place the order → hosted page → 4242 → back
  on the order-received page. The order is **Processing**, its transaction id is a `pay_…`, and
  that payment is `succeeded` in SecondRail with the order number as `external_id`.
- **R11.2** Block checkout (`/checkout/`): the same.
- **R11.3** Decline, then "Try another card" with 4242 on the same page: one order, paid once,
  with one failure note.
- **R11.4** "Cancel and return" on the hosted page: back on the store's checkout with the cart
  intact and the order pending. Placing the order again and paying: the order is paid, and the
  first link is `revoked`.
- **R11.5** Pay with 4242 while the test aborts the browser's request to `secondrail_return`: the
  order becomes **Processing** within 90 seconds, through the webhook alone.
- **R11.6** On the order of R11.1, `wc_create_refund` with `refund_payment` for 5.00, run through
  `bin/wp`: SecondRail has a refund of 500 for that payment that reaches `succeeded`, and the
  order shows 5.00 refunded.
- **R11.7** A forged return pays nothing: place a second order and leave its hosted page unpaid,
  then open that order's return URL (its own id and key) with `status=succeeded` and the
  `payment_id` of the payment of R11.1. The second order stays **Pending payment**.

- **R11.8** In R11.1, R11.2, R11.3 and R11.5 the order's notes show one status change, from
  **Pending payment** to **Processing**: the order was never **On hold** and, in R11.3, never
  **Failed**. No note says "Waiting for SecondRail to confirm the payment." How it reads in the
  notes (WooCommerce 11.3.0, `WC_Order::payment_complete`): that change is made with
  `set_status( …, false )`, which writes no "Order status changed" note; it is recorded only by
  WooCommerce's "Payment via … (pay_…)." note. So the check is: exactly one "Payment via" note, no
  "Order status changed" note at all (every other change writes one), no "Waiting for SecondRail"
  note, and the order **Processing**.

A payment refused with `velocity_exceeded` is the sandbox's own limit: wait, run that test again,
and say so in the report.

## Deliverables

Everything under "Repository layout", plus `composer.lock` and `tests/e2e/package-lock.json`.

## Tasks (in order; each ends with its gate green)

1. `git init -b main`, `.gitignore`, `.env.example`, Compose files, `docker/`, `bin/dc`,
   `bin/setup`, `bin/wp`, `bin/composer` (R1.1).
2. `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`, the test bootstrap and `Http_Stub`,
   `bin/check`; a plugin skeleton that activates (R1.2 to R1.6, R9.1, R9.3, R10.1).
3. `Api_Client` (R2).
4. `Reconciler` and the scheduled check (R4).
5. `Gateway::process_payment` (R3), the return handler (R5).
6. Webhook handler and endpoint registration, settings (R6, R8).
7. `process_refund` (R7).
8. Block support and lifecycle (R9.2, R9.4, R9.5).
9. `readme.txt`, `LICENSE`, `README.md`, `AGENTS.md`, `CHANGELOG.md` (R10).
10. `bin/sandbox`, `bin/e2e`, the specs; the real-sandbox run (R11); `bin/sandbox clean`.
11. `docs/report.md`.

Write each test before the code it covers and see it fail first. When a WooCommerce or WordPress
detail is in doubt, the source installed in the `wordpress` volume is the authority, not memory.

## Acceptance criteria

1. `bin/check` passes whole: lint, PHPUnit, Plugin Check, ShellCheck and build.
2. Every scenario R1.1 to R10.2 has a test or a recorded check with its id; all pass.
3. R11.1 to R11.8 pass against the real sandbox, with ids and statuses in the report and no secret
   and no host name. A scenario that could not run is reported as not run, with the reason.
4. The repository is initialized on `main` with no commit, and `.env` does not appear in
   `git status` (it is ignored).
5. At the end the stack is stopped (`bin/dc stop`), the sandbox key is revoked and the endpoint
   deleted, and every SecondRail service that was stopped is running again.

## Machine limits and safety

- One heavy operation at a time. Never run a gate here while a test run or a build of the platform
  is going.
- Before `bin/setup`: at least 3 GB of disk free (`df -h /`) and 700 MB of memory available
  (`free -m`). Before `bin/e2e`: 1 GB available. When memory is short, stop the platform's
  `web-admin` and `web-console` (the checkout flow does not use them) with
  `docker compose stop web-admin web-console` in `../orqbit`, and start them again afterwards.
- Never stop, remove or reconfigure a `honeypacks-*` container, a `secondrail-prod_*` network or
  any other service of the platform. Never run `docker system prune`, `docker volume prune` or
  `docker image prune`.
- `wordpress` is published on `127.0.0.1` only. If `8190` is taken, set another `WP_PORT`.
- No PAN, key or secret in code, logs, reports or test output.

## Pending for the owner

- The production API host, to give "API base URL" a default.
- The URLs of SecondRail's terms and privacy policy for `readme.txt`.
- A gateway icon and the WordPress.org assets; the WordPress.org submission.
- Whether to support WooCommerce versions older than 11.

## Definition of Done

Acceptance criteria 1 to 5 met and `docs/report.md` written. No commit is made in this run: the
repository is left initialized, with everything untracked.
