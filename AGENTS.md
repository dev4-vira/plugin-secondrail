# SecondRail for WooCommerce — agent and contributor guide

## Language: English only

Code, comments, identifiers, documents, file names, commit messages and every user-visible string
are English. User-visible strings go through the WordPress translation functions with the text
domain `secondrail-for-woocommerce`.

## Everything runs in Docker

Use the scripts in `bin/`; they wrap `docker compose`. Do not install PHP, Composer or WordPress on
the host. The only host process is the opt-in browser run (`bin/e2e`), which uses the host's Node
and the system Chromium.

## Gates

`bin/check` runs all five: `lint`, `phpunit`, `plugin-check`, `shell`, `build`. A change is done
when all five pass. Write the test first, with the scenario id of `docs/plan.md` in its name or
docblock, and see it fail.

## Rules

- The shippable plugin is `secondrail-for-woocommerce/` and nothing else. It has no build step and
  no runtime dependency; it must parse on PHP 7.4.
- Never commit `.env`. No key, `whsec_` secret, password or SecondRail host name in any tracked
  file, log, report or test output.
- Orders are read and written only through `wc_get_order` and `WC_Order` methods.
- An order's state comes from `Reconciler::reconcile()`, which reads the SecondRail API. The return
  URL's `status` and a webhook body are triggers, never facts.
- PHPUnit runs on the `wordpress_test` database only; `tests/phpunit/wp-tests-config.php` names
  nothing else.
- Machine limits: one heavy operation at a time; never prune Docker images or volumes.
