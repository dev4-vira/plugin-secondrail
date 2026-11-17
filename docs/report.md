# SecondRail for WooCommerce 0.1.0 · report

Date: 2026-11-13. Contract: [`plan.md`](plan.md). Second and third passes the same day, after the
owner's reviews: see "Second pass" and "Third pass". Gates, scenarios and the first sandbox table
below are from the third pass unless stated.

**State: acceptance criteria 1–5 met.** `bin/check` passes whole, every scenario R1.1–R10.2 has a
test or a recorded check, R11.1–R11.7 passed against the real sandbox, the repository is
initialized on `main` with no commit, and at the end the stack was stopped, the sandbox key revoked
and the endpoint deleted. No platform service was stopped for this run.

## Gates (`bin/check`)

| Gate | Result |
| --- | --- |
| `lint` (PHPCS 3.13, WPCS 3.4.1, PHPCompatibilityWP `7.4-`) | 7 files, 0 errors, 0 warnings |
| `phpunit` (PHPUnit 9.6.38, wp-phpunit 7.1.3, WooCommerce 11.3.0, database `wordpress_test`) | `OK (85 tests, 424 assertions)` (first pass: 70 / 338; second pass: 78 / 374) |
| `plugin-check` (WordPress.org Plugin Check, latest) | "Checks complete. No errors found." Also no warnings |
| `shell` (`koalaman/shellcheck:stable` over `bin/*`) | No finding |
| `build` | `dist/secondrail-for-woocommerce-0.1.0.zip`: 14 entries, all under `secondrail-for-woocommerce/`; 7 PHP files, `php -l` clean |

`lint`: 7 files, 0 errors, 0 warnings. `plugin-check`: no errors and no warnings. `shell`: no
finding. All five from one `bin/check` run of the third pass, exit 0.

## Scenarios

| Scenario | Test or check | Result |
| --- | --- | --- |
| R1.1 | Recorded: `bin/setup` on a clean clone, then twice more; each exit 0, `http://localhost:8190/` answered 200; `bin/wp plugin list`: WooCommerce 11.3.0 and the plugin active; the shop page shows "SecondRail test product" to a guest | Pass |
| R1.2 | `bin/check lint` | Pass |
| R1.3 | `bin/check phpunit`; `test-plugin.php::test_r1_3_stub_answers_queued_and_flags_others`, `::test_r1_3_runs_on_the_test_database`; `Test_Case::tear_down()` fails any test with an unqueued request | Pass |
| R1.4 | `bin/check plugin-check` | Pass |
| R1.5 | `bin/check shell` | Pass |
| R1.6 | `bin/check build` | Pass |
| R2.1–R2.6 | `test-api-client.php::test_r2_1_*` (2), `test_r2_2_*`, `test_r2_3_*`, `test_r2_4_*`, `test_r2_5_*`, `test_r2_6_*` | Pass |
| R3.1–R3.10 | `test-process-payment.php::test_r3_1_*` … `test_r3_10_*` (R3.6 has three tests: cancel then new link, cancel returns `succeeded`, still open; R3.5 now uses `processing` created 3 minutes ago, and `unknown`) | Pass |
| R3.11 | `test-process-payment.php::test_r3_11_charge_in_flight` | Pass |
| R4.1–R4.10 | `test-reconciler.php::test_r4_1_*` … `test_r4_10_*` (R4.10 has two tests), plus the order-mode key, the waiting flag and the open row | Pass |
| R4.11 | `test-reconciler.php::test_r4_11_lock_held_is_busy`, `::test_r4_11_lock_released` (after a normal run and after an API failure), `::test_r4_11_stale_copy_does_not_complete_twice` | Pass |
| R4.12 | `test-reconciler.php::test_r4_12_processing_by_age` (10 s: `open`, id among the open ids, pending, no note, no flag; 3 min: on hold, `waiting`; unreadable `created_at`: on hold) | Pass |
| R4.13 | `test-reconciler.php::test_r4_13_in_flight_then_failed_stays_pending` | Pass |
| R5.1–R5.5 | `test-return.php::test_r5_1_*` … `test_r5_5_*` | Pass |
| R5.6 | `test-return.php::test_r5_6_lock_held` | Pass |
| R5.7 | `test-return.php::test_r5_7_charge_in_flight` | Pass |
| R6.1–R6.10 | `test-webhook.php::test_r6_1_*` … `test_r6_10_*` | Pass |
| R6.11 | `test-webhook.php::test_r6_11_lock_held_is_503` | Pass |
| R7.1–R7.5 | `test-refund.php::test_r7_1_*` (2, one through `wc_create_refund`), `test_r7_2_*` … `test_r7_5_*` | Pass |
| R7.6 | `test-refund.php::test_r7_6_refused_refund_is_an_error` (`failed` with its message, `cancelled` with the default message, no "sent" note), `::test_r7_6_wc_create_refund_records_nothing` | Pass |
| R7.7 | `test-refund.php::test_r7_7_unknown_refund` | Pass |
| R7.4 (changed) | `test-refund.php::test_r7_4_transport_failure`: transport failure, a 502 HTML body and a 503 envelope, each the exact "outcome is unknown" message | Pass |
| R7.8 | `test-refund.php::test_r7_8_retry_after_refusal` (through `wc_create_refund`: keys `…-500-0` then `…-500-1`, 5.00 refunded, counter 1), `::test_r7_8_counter_moves_only_on_refusal` | Pass |
| R7.9 | `test-refund.php::test_r7_9_idempotency_errors_are_unknown` | Pass |
| R8.1–R8.3, R8.5–R8.7 | `test-settings.php::test_r8_*` (R8.1 asserts exactly the eight event types of decision i) | Pass |
| R8.4 | `test-webhook.php::test_r8_4_webhook_url_constant` (last test of the last file: see decision 11) | Pass |
| R9.1 | `test-plugin.php::test_r9_1_declares_compatibility` | Pass |
| R9.2 | `test-lifecycle.php::test_r9_2_block_checkout` | Pass |
| R9.3 | `test-lifecycle.php::test_r9_3_without_woocommerce` (a separate PHP process loads the plugin with no WooCommerce: no class loaded, no notice, exit 0) | Pass |
| R9.4 | `test-lifecycle.php::test_r9_4_deactivation`, `::test_r9_4_uninstall` | Pass |
| R9.5 | `test-lifecycle.php::test_r9_5_no_direct_order_access` | Pass |
| R10.1 | `test-plugin.php::test_r10_1_headers_and_version` | Pass |
| R10.2 | Recorded: every file except `.env` (and `.git`, `vendor`, `node_modules`) searched for the real values of the sandbox key, the webhook secret, the three passwords and the SecondRail host name: no match. A pattern search for `sk_test_`/`sk_live_`/`whsec_` finds only the made-up unit-test fixtures (`sk_test_unit_key_0001`, `whsec_unit_test_secret`, …) | Pass |

## Real-sandbox run (R11), third pass

After the fixes of the third pass. Key `key_01a1d428de57767eb9bda457f378acd5`; `bin/sandbox
connect`: `livemode=false`, endpoint `we_01a1d428fd0b768a98e550dce4e046b4` registered with exactly
the eight event types of decision i (read back with `GET /webhook_endpoints/{id}`), test event
`ok: true`, status 200. Before `bin/e2e`: 1.35 GB memory available, 2.6 GB disk free. Host Node 24,
`/snap/bin/chromium`, one worker: 7 passed in 5.6 min, no `velocity_exceeded`.

| Scenario | Ids and statuses | Result |
| --- | --- | --- |
| R11.1 classic checkout | order 28 `processing`, `pay_01a1d4298dcc763fa8a109a2285ce81f` `succeeded` with the order number as `external_id`; link `lnk_01a1d4297cbe7646bbd5225a86016e4d` | Pass (46.1 s) |
| R11.2 block checkout | order 29 `processing`, `pay_01a1d42a555f70dabf5e167ea3f19c9e` `succeeded`; link `lnk_01a1d42a458c7562a1b853e95e7f1389` | Pass (54.6 s) |
| R11.3 decline, then another card | order 30 `processing`; `pay_01a1d42b0245758c95a5c5e884070b9e` `failed`, `pay_01a1d42b224475ff916e29723ff59d9c` `succeeded`; one failure note | Pass (51.4 s) |
| R11.4 cancel and return | order 31; first link `lnk_01a1d42bdf11729080e472c20dc883f3` `revoked`, second `lnk_01a1d42c330d75c29e7e0405a3a70abd`; `processing` | Pass (1.2 min) |
| R11.5 webhook only | order 32 `processing` 5 s after the aborted return, `pay_01a1d42d44e2701981a3aef7f74ba428` | Pass (1.0 min) |
| R11.6 refund | order 28: refund `re_01a1d42d9a3e735d8d1845ad48a11b32` amount 500 `succeeded`; 5.00 refunded in WooCommerce | Pass (9.5 s) |
| R11.7 forged return | order 34: its return URL with `status=succeeded` and the payment of R11.1; still `pending`, no transaction id | Pass (35.0 s) |
| R11.8 straight to Processing | orders 28, 29, 30, 32: each `processing`, exactly one "Payment via …" note, no "Order status changed" note, no "Waiting for SecondRail" note (asserted inside R11.1, R11.2, R11.3, R11.5) | Pass |

Read by hand as well: order 30 (the decline) has only "page opened", the failure note, the paid
note, "Payment via …" and the "New order" and "Processing order" emails; no On hold, no Failed, no
"Order on-hold" or "Failed order" email. Order 31 (cancel and return) is the same with two "page
opened" notes. The On hold detour of the first two runs is gone.

Clean-up: `bin/sandbox clean` deleted the endpoint and revoked the key; the platform database shows
0 active keys named `plugin-secondrail-e2e` and 0 undeleted endpoints for `plugin-secondrail-wp`;
`.env` has no sandbox line (mode 0600); `bin/dc stop` at the end. A search of every file except
`.env` for the passwords and the SecondRail host name found nothing; the only key-like strings are
the made-up unit-test fixtures.

## Real-sandbox run (R11), second pass

Repeated after the lock and the refund-status fix, because the lock is on the path of every
payment. Merchant `honeypacks`, sandbox, key `plugin-secondrail-e2e`
(`key_01a1d41872bc70cc90ade5ea4825a27f`) with the seven scopes. `bin/sandbox connect`:
`livemode=false`; endpoint `we_01a1d4189737723cb7cbb4ef67ca7c26`, test event `ok: true`, status
200. `bin/e2e` with 1.32 GB available: host Node 24, `/snap/bin/chromium`, one worker, 7 passed in
5.5 min. No `velocity_exceeded`, no test failed because of the lock, and no `busy` was logged
during the run.

| Scenario | Ids and statuses | Result |
| --- | --- | --- |
| R11.1 classic checkout | order 21 `processing`, `pay_01a1d41918ce76188901fb4613b0565d` `succeeded` with the order number as `external_id`; link `lnk_01a1d419084b75a09953df56b5702294` | Pass (38.5 s) |
| R11.2 block checkout | order 22 `processing`, `pay_01a1d419cc2a767abb2d82fdcb822df0` `succeeded`; link `lnk_01a1d419b81870adb16af7300eaa296e` | Pass (52.3 s) |
| R11.3 decline, then another card | order 23 `processing`; `pay_01a1d41a71ef71b18402cdd093e886f3` `failed`, `pay_01a1d41a90c777b59a6e5b2c64047054` `succeeded`; one failure note | Pass (1.2 min) |
| R11.4 cancel and return | order 24, same order placed twice; first link `lnk_01a1d41b971573f0ba2795e02e4d79c9` `revoked`, second `lnk_01a1d41be33277d590710889ac031fe5`; `processing` | Pass (1.1 min) |
| R11.5 webhook only | order 25 `processing` 4 s after the aborted return, `pay_01a1d41cdbb6745b80d293f6dd6843ac` | Pass (52.7 s) |
| R11.6 refund | order 21: refund `re_01a1d41d1be777e882a29295a12bad28` amount 500 `succeeded`; 5.00 refunded in WooCommerce | Pass (11.1 s) |
| R11.7 forged return | order 27: its return URL with `status=succeeded` and the payment of R11.1; still `pending`, no transaction id | Pass (31.4 s) |

Each paid order (21–25) has exactly one "Payment via Credit or debit card (pay_…)." note, so
`payment_complete()` ran once per order; no lock transient was left behind on orders 21–27.

**Observed, not changed in that pass (fixed in the third pass).** In every paid order the first reconcile saw the
payment still `processing` (row 4 of R4), so the order went to **On hold** and WooCommerce sent the
"New order" and "Order on-hold" emails before the payment succeeded a moment later. In R11.3 the
decline took the order from On hold to **Failed** (row 6, with a "Failed order" email) and the
second card took it back to On hold and then paid. This is what the R4 table says; whether a
short-lived `processing` should leave the order pending instead is a contract decision.

Clean-up: `bin/sandbox clean` deleted the endpoint and revoked the key; the platform database shows
no active key named `plugin-secondrail-e2e` and no undeleted endpoint for
`plugin-secondrail-wp`; `.env` has no sandbox line (mode 0600); `bin/dc stop` at the end.

## Real-sandbox run (R11), first pass

Merchant `honeypacks`, sandbox, key `plugin-secondrail-e2e` with the seven scopes of the contract.
`bin/sandbox connect`: `GET /account` answered `livemode=false`; endpoint
`we_01a1d4002f3e767c89e8551fc6be34a3` registered with `http://plugin-secondrail-wp/?wc-api=secondrail_webhook`;
its test event answered `ok: true`, status 200 (no redirect, so the `HTTP_HOST` fallback of step 4
was not needed). `bin/e2e`: host Node 24, `/snap/bin/chromium`, one worker, 7 passed in 5.2 min.
No `velocity_exceeded` was seen.

| Scenario | Ids and statuses | Result |
| --- | --- | --- |
| R11.1 classic checkout | order 14 `processing`, transaction `pay_01a1d403254e734c854ed9a5381ab179` `succeeded` in SecondRail with the order number as `external_id`; link `lnk_01a1d40314a7764584fccf1ca52292cd` | Pass (36.6 s) |
| R11.2 block checkout | order 15 `processing`, `pay_01a1d403d04577cb97cf12e2cdb5a1ac` `succeeded`; link `lnk_01a1d403bf5771e29ae7eb3ab1f465a0` | Pass (49.2 s) |
| R11.3 decline, then another card | order 16 `processing`; payments `pay_01a1d4046c7f71aeaedb279daef876f4` `failed` and `pay_01a1d4048ab2777b9a3bed9c063feb9f` `succeeded`; one failure note, one paid note | Pass (51.8 s) |
| R11.4 cancel and return | order 17: back on `/checkout/` with the cart, order `pending`; placed again (same order), paid, `processing`; first link `lnk_01a1d405257374988476ca908763c241` `revoked`, second `lnk_01a1d405746e7749b38f24b9fb69c9c8` | Pass (1.0 min) |
| R11.5 webhook only | order 18: the browser's request to `secondrail_return` aborted; `processing` 6 s later through the webhook, transaction `pay_01a1d406774c7100b1aa511f39c9a95f` | Pass (54.2 s) |
| R11.6 refund | order 14: `wc_create_refund` 5.00 with `refund_payment`; refund `re_01a1d406bada755fb1785e768408f11e` amount 500 `succeeded`; WooCommerce shows 5.00 refunded | Pass (11.2 s) |
| R11.7 forged return | order 20 left unpaid on the hosted page; its own return URL with `status=succeeded` and the payment of R11.1: still `pending`, no transaction id | Pass (39.0 s) |

An earlier single run of R11.1 (order 13, `pay_01a1d4026981769d986d297a866d5280` `succeeded`) checked
the harness first. After a ShellCheck fix to `bin/sandbox` (quoting only), `connect` and `clean`
were run once more: endpoint `we_01a1d409213e709dabece7e8436fc088`, test event `ok: true`, then
deleted, and key `key_01a1d409037e70b3a9352becacb91a97` revoked.

Clean-up, verified in the platform database: no active key named `plugin-secondrail-e2e` (both
`key_01a1d4000cea76b385e8da264094a11c` and `key_01a1d409037e70b3a9352becacb91a97` revoked); both
endpoints deleted (`deleted_at` set, `disabled`); `.env` without the two sandbox lines (mode 0600);
the gateway's stored test key cleared and the gateway disabled. `bin/dc stop` at the end.

## Second pass: defects fixed

1. **A refused refund was recorded as made.** `POST /refunds` answers 201 whatever the outcome;
   `Gateway::process_refund` returned `true` on any 2xx. Now a refund `status` of `failed` or
   `cancelled` returns a `WP_Error` with the refund's `error.message` (or "SecondRail could not make
   the refund.") and adds no "sent" note, so `wc_create_refund` deletes its refund (R7.6). `unknown`
   and the other statuses return `true` (R7.7).
2. **Return and webhook could complete the same order twice.** `Reconciler::reconcile` now takes
   the per-order lock before it builds the client or calls the API, raises `Api_Error` `busy` when
   it is held (R4.11), and releases it in a `finally`; the table itself moved, unchanged, to the
   private `Reconciler::apply`. The return already treats an `Api_Error` as `waiting` (R5.6);
   `Webhook::handle` answers 503 for `busy` and 500 for any other `Api_Error` (R6.11).
3. **Found while testing R7.6: a cached refunded total.** `process_refund` built its idempotency key
   from `$order->get_total_refunded()`. Inside `wc_create_refund` that call caches a total that
   includes the refund being made, and WooCommerce does not clear the cache when it deletes a refund
   the gateway refused: the order showed 5.00 refunded for the rest of the request (and longer with
   a persistent object cache). The key now reads the total from the order's data store, which does
   not cache. The key's value is unchanged (R7.1 passes as before).

Decisions of this pass:

- **The order is re-read under the lock** (`wc_get_order` inside `reconcile`). The contract places
  the lock before the API call; but the caller's copy is loaded before the lock, and a copy loaded
  just before another run paid the order would still look pending in memory and run
  `payment_complete()` again once the lock is free. `test_r4_11_stale_copy_does_not_complete_twice`
  failed on the first pass's code with two "SecondRail payment … succeeded" notes, and passes now
  with one "Payment complete." note. The contract was not edited: this completes its rule, it does
  not contradict it.
- The lock is taken after the gateway check, so an order of another gateway is still returned
  `ignored` with no lock and no request (R4.7).
- `busy` keeps the callers' existing paths: `process_payment` answers "We could not start the
  payment. Please try again."; the scheduled check counts it as an API failure and reschedules.

Test-first in this pass: the tests of R4.11 (busy and stale copy), R5.6, R6.11 and R7.6 failed on
the first pass's code and pass now. `test_r4_11_lock_released` and R7.7 passed before the change:
there was no lock to leave behind, and an `unknown` refund already returned `true` with its note.

## Third pass: contract faults fixed

The owner traced the On hold detour to the `payment.created` webhook, delivered at +0.0 s while the
charge is `processing` (it ends at +1.5 s), and found that a refused refund could not be retried for
24 hours. Both were faults of the contract, which the owner corrected; this pass implements it.

**Fault A · every order went through On hold.**
- `includes/class-webhook.php`: new constant `Webhook::EVENT_TYPES` with the eight events of decision
  i; `Webhook::ensure_endpoint` registers those instead of `payment.*` and `refund.*`. The handler
  still accepts any `payment.*`/`refund.*` it receives (an endpoint registered earlier keeps working).
- `includes/class-reconciler.php`: new `Reconciler::in_flight()` and constant `IN_FLIGHT` (120 s).
  In `Reconciler::apply`, a `processing` payment whose `created_at` is 120 s old or less is row 5
  (`open`, its id among the open ids); older, or with a `created_at` `strtotime` cannot read, it is
  row 4 as before.
- `includes/class-webhook.php`, `Webhook::return_target`: only `unpaid` goes to the checkout with
  the notice; everything else, `open` included, goes to order-received.
- `process_payment` needed no change: R3.11 follows from the open id, the 409 `invalid_state` on
  cancel and the existing "still open" branch.

**Fault B · a refused refund replayed for 24 hours.** `includes/class-gateway.php`,
`Gateway::process_refund`: the key ends in `-<_secondrail_refund_failures>` (0 when absent); a refund
`failed` or `cancelled` adds one to the counter; an `Api_Error` never moves it. The "outcome is
unknown" message now covers `network_error`, `invalid_response`, any 5xx, `idempotency_conflict` and
`idempotency_in_progress`; any other 4xx carries the envelope's message.

**Existing tests adjusted:**
- R7.1, both tests: the key now ends in `-0` (`…-200-0`, `…-500-0`).
- R7.4: was one transport failure with a loose "contains unknown" check; now the transport failure,
  a 502 HTML body and a 503, each with the exact message.
- R8.1: `event_types` is the eight events, not `payment.*`/`refund.*`.
- R3.5: the `processing` payment now has an explicit `created_at` three minutes old. It passed before
  too: the fixtures' `created_at` is in 2023, so every `processing` payment of the older tests is old
  and still row 4; R4.4, R4.10 and the waiting-flag test needed no change for the same reason.
- R5: no existing test asserted `open` → checkout, so none changed; R5.7 is new.
- `class-test-case.php`: new helper `ago( $seconds )`, a `created_at` in the platform's format
  (microseconds and `Z`), so the tests also prove `strtotime` reads that format.

**Test-first:** R3.11, R4.12, R4.13, R5.7, R7.1 (key), R7.4, R7.8 (retry), R7.9 and R8.1 failed on the
second pass's code (10 failures) and pass now. `test_r7_8_counter_moves_only_on_refusal` passed
before the change: without a counter there was nothing to move.

**Contract corrected: R11.8.** It asked the order notes to show one status change from Pending
payment to Processing. In WooCommerce 11.3.0, `WC_Order::payment_complete` changes the status with
`set_status( $next_status, false )`, and a `false` note skips the "Order status changed" note; the
change is recorded only by WooCommerce's "Payment via … (pay_…)." note. Every other change
(to On hold, to Failed) does write an "Order status changed" note. R11.8 in `plan.md` now says how
the check reads: one "Payment via" note, no "Order status changed" note, no "Waiting for SecondRail"
note, and the order Processing. The intent is unchanged.

`CHANGELOG.md` was updated for the eight events, the charge in flight and the refund counter.

## Decisions taken where the contract left room

1. **`Api_Error` lives in `class-api-client.php`**, as the layout says. PHPCS's
   `Generic.Files.OneObjectStructurePerFile` is excluded for that file only. With it the plugin has
   six classes; the five "real" ones are the ceiling of the layout.
2. **Exceptions are built, then thrown** (`$error = new Api_Error(…); throw $error;`). WPCS's
   `ExceptionNotEscaped` skips pre-created exceptions; these are never printed.
3. **"One note" (R4.1)** counts the plugin's notes. `payment_complete()` adds WooCommerce's own
   "Order status changed" note, which the plugin does not control.
4. **Logging.** Failed calls are always logged (method, path, status, code, request id); "Debug log"
   adds the successful ones. Never a key, a body or a signature (R2.5, R8.7).
5. **`reconcile()` returns the result string**; an optional by-reference second argument gives the
   ids of payments still `requires_action`/`created`, which step 1 of R3 needs to cancel them.
6. **An API failure during step 1 of R3** ends like a failed link: "We could not start the
   payment. Please try again." and `failure`.
7. **A revoke error other than 404/409** is logged and does not stop the new link (the link expires
   in 30 minutes, and a second payment gets the row-1 note).
8. **Row 1 treats a `refunded` order as paid**, so a refunded order is never paid again.
9. **Rows 3 and 4** take the earliest such payment for the note; `authorized` is checked before
   `processing`/`unknown`.
10. **A webhook event without `livemode`** is ignored like one that contradicts the mode.
11. **R8.4 runs last**: a PHP constant cannot be undefined, so the test that defines
    `SECONDRAIL_WC_WEBHOOK_URL` is the last method of `test-webhook.php`, the last file PHPUnit loads.
12. **Saving the settings** is hooked once, statically, to the gateway instance WooCommerce
    registered (`woocommerce_update_options_payment_gateways_secondrail`).
13. **The read-only box** shows the account from the last successful save (option
    `secondrail_wc_account_<mode>`, not autoloaded, removed on uninstall) instead of calling the API
    on every page view.
14. **`cancel_url` is `wc_get_checkout_url()`**, the store's checkout page, also for orders placed on
    `/classic-checkout/`.
15. **`bin/sandbox clean`** also clears the stored test key and disables the gateway, so the
    revoked key does not stay in the settings.
16. **wp-cli sees the same `wp-config` extras as WordPress** (one YAML anchor per Compose file), so
    the plugin computes the sandbox webhook URL itself when `bin/sandbox` ensures the endpoint.
17. **`bin/check plugin-check`** decides on Plugin Check's own "No errors found": the command exits 0
    even when it reports errors (read in its `Plugin_Check_Command`).
18. **R9.3 is a test**, not a manual check: a child PHP process loads the plugin file with stubbed
    hook functions and no WooCommerce.

## Notes

- **Test-first.** Every requirement's tests were written first and seen failing, except: the
  harness tests of R1.3 (they test the stub itself), R9.3 and the deactivation half of R9.4, whose
  code (the bootstrap guard, `remove_endpoints`, `unschedule_all`) had been written with earlier
  tasks; they passed on their first run. Two R4 tests were corrected after their first run because
  the test was wrong, not the code: R4.10 did not mark the running action complete as Action
  Scheduler does, and a test expected `processing` on an order with no line items, which WooCommerce
  completes.
- **Known limit.** `reconcile` takes a per-order lock, the transient `secondrail_wc_lock_<order
  id>` (60 s, deleted in a `finally`), and re-reads the order under it. A transient is not atomic:
  `get_transient` and `set_transient` are two separate queries, so two requests that check the lock
  within the same millisecond or so can both take it and both complete the order (two status
  changes, two "Payment via…" notes, two emails). The lock narrows that window from the length of
  an API call to that gap; closing it would need an atomic primitive (`add_option` or direct SQL,
  which R9.5 forbids). A crashed request leaves the lock for at most 60 s; meanwhile the return goes
  to order-received, the webhook answers 503 and is redelivered, and the scheduled check retries.
- **Known limit: the two-minute window uses the store's clock.** `created_at` comes from SecondRail
  and "now" from WordPress. A store clock running more than two minutes ahead treats every
  `processing` payment as old (back to On hold); one running behind keeps a stuck charge open
  longer. While a payment is open nothing changes the order: if no `payment.*` outcome webhook
  arrives, the scheduled check at 15 minutes (then 1, 6 and 24 hours) settles it, and a shopper who
  submits again in that window gets "Try again in a few minutes."
- **Known limit: an endpoint registered before this pass** keeps its `payment.*`/`refund.*`
  subscription until "Reconnect webhook" is pressed or its URL changes, so `payment.created` still
  reaches such a store; R4.12 keeps that harmless (the charge is open, not waiting). Version 0.1.0
  was never released, so no real store has one.
- **Known limit: the refund counter is per order**, in `_secondrail_refund_failures`. It only grows,
  so each refusal gives the next try a new key; two refusals in a row and then a lost answer still
  replay correctly, because the key in force is the one the lost request used.
- PHPUnit runs share the WordPress files volume, so its failure-path log lines land in
  `wp-content/uploads/wc-logs/` of the development site. They hold no secret.
- `wp-cli` printed "sendmail: can't connect" once while saving options: the development stack has
  no mail server. Harmless.
- Disk and memory were checked before `bin/setup` (5.3 GB free, 1.76 GB available) and before
  each `bin/e2e` (1.54 GB, then 1.32 GB, then 1.35 GB available with 2.6 GB of disk). No platform
  service was stopped for the plugin in any pass.
- `bin/check phpunit` needs the `db` service running (`bin/dc up -d --wait db`); with the stack
  stopped it fails to connect.

## Pending

- From the contract, for the owner: the production API host (a default for "API base URL"), the
  URLs of SecondRail's terms and privacy policy for `readme.txt`, a gateway icon and the
  WordPress.org assets and submission, and whether to support WooCommerce before 11.
- Not run here: the settings form saved through the WooCommerce admin screen in a browser, and the
  "Reconnect webhook" button clicked in a browser. Both code paths are covered by PHPUnit (R8).
- No commit was made, by instruction: the repository is initialized on `main` with everything
  untracked.
