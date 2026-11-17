=== SecondRail for WooCommerce ===
Contributors: secondrail
Tags: payments, credit card, checkout, woocommerce, payment gateway
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take card payments in WooCommerce through SecondRail's hosted payment page.

== Description ==

SecondRail for WooCommerce sends the shopper to SecondRail's hosted payment page to pay by card and
brings them back to the store's order-received page. No card field is rendered on the store.

* Works with the classic checkout and the block checkout.
* The order is marked paid from the SecondRail API, never from what the browser says. A signed
  webhook and a scheduled check confirm the payment when the shopper does not come back.
* Refunds are made from the WooCommerce order screen.

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Go to WooCommerce > Settings > Payments > SecondRail.
3. Enter the API base URL (for example `https://api.example.com/v1`) and a secret key with the
   scopes `payments:read`, `payments:write`, `refunds:read`, `refunds:write`, `action_links:write`,
   `webhooks:read` and `webhooks:write`.
4. Save. The plugin checks the key and registers its webhook endpoint in SecondRail.

== External services ==

This plugin connects to the SecondRail API, the payment service the store owner configured in the
"API base URL" setting. It is required to take payments.

* When the settings are saved: the secret key is used to read the account (`GET /account`) and to
  register a webhook endpoint with the store's webhook URL.
* When a shopper places an order: the order number, the order id, the order total and currency, the
  store name, a random identifier of this store, the plugin version, and the store's return and
  checkout URLs are sent to create a hosted payment page. The shopper then enters the card on
  SecondRail's page; the card never passes through the store.
* To confirm an order: the store reads the payments of that order number from SecondRail when the
  shopper returns, when a webhook arrives and in a scheduled check.
* When a refund is made: the payment id, the amount, the reason text and the order id are sent.

SecondRail's terms of service and privacy policy apply to this data. Their addresses are provided by
the store owner's SecondRail account.

== Frequently Asked Questions ==

= Is a card field shown on my store? =

No. The shopper pays on SecondRail's hosted page.

= What happens if the shopper closes the browser after paying? =

SecondRail sends a signed webhook to the store, and a scheduled check reads the payment. The order
is marked paid either way.

== Changelog ==

= 0.1.0 =
* First release: hosted payment page, webhook, scheduled check and refunds.
