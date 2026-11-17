# Changelog

## 0.1.0 — Unreleased

First version, contract in [`docs/plan.md`](docs/plan.md).

### Added

- Payment gateway `secondrail` for the classic and the block checkout: the order is sent to
  SecondRail's hosted payment page (`POST /action_links` with `auto_return`) and the shopper comes
  back to the order-received page.
- `Reconciler`: the order's state comes from `GET /payments?external_id=`, filtered by this
  install's id and the order id. It runs on return, on every signed webhook, before a new attempt,
  and in a scheduled check (15 minutes, then 1, 6 and 24 hours while the outcome is pending).
- Signed webhook handler and automatic endpoint registration on save, for the eight events that
  carry an outcome (`payment.created` is left out: it arrives while the charge is in flight).
- A `processing` payment younger than two minutes is a charge in flight: the order stays pending.
- Refunds: a refused refund (`failed`, `cancelled`) is an error and moves the idempotency key, so
  the merchant can try again; an unknown outcome keeps the key.
- Refunds from the order screen, with an idempotency key that replays a refund after a lost answer.
- Settings with masked secret keys, a connection check, and a "Reconnect webhook" button.
- Development stack in Docker Compose, PHPUnit with an HTTP stub, PHPCS, Plugin Check, ShellCheck,
  a zip build, and a Playwright run against the SecondRail sandbox.
