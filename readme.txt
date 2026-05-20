=== Allscale Checkout for WordPress ===
Contributors: allscale
Tags: woocommerce, payment gateway, crypto, usdt, stablecoin, non-custodial
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 0.0.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept crypto payments on WordPress with a 0.6% fee (minimum $0.10) and instant USDT settlement to your own wallet. Non-custodial. Requires WooCommerce.

== Description ==

Allscale Checkout is a WordPress plugin (built as a WooCommerce payment gateway) that lets your customers pay with crypto while funds settle instantly as USDT stablecoin directly to your wallet — Allscale never holds your money.

= Why use Allscale? =

* **Non-custodial.** Funds go straight to your wallet. No third party holds your money. No account freezes.
* **Low fees.** 0.6% per transaction with a $0.10 minimum (vs. ~3% on traditional processors).
* **Instant settlement.** On-chain USDT — no waiting days for payouts.
* **Permissionless setup.** Sign up, paste your credentials, start accepting payments.

= Features =

* Native WooCommerce payment gateway.
* HMAC-SHA256 request signing and webhook verification (timing-safe).
* Test-connection button + automatic credential validation on save.
* Per-order Allscale Payment meta box: tx hash with block-explorer link, payment-method type, paid / fee / net breakdown.
* Webhook health observation: last-received timestamp, first-webhook celebration, stale-webhook warning.
* HPOS compatible. Block-based checkout compatible.
* Full i18n with text domain `allscale-checkout`.

= Requirements =

* WordPress 5.8+
* WooCommerce 6.0+
* PHP 7.4+
* An [Allscale account](https://allscale.io) with Commerce enabled

== Installation ==

1. Upload the plugin ZIP via *Plugins → Add New → Upload Plugin*.
2. Activate the plugin.
3. Go to *WooCommerce → Settings → Payments → Allscale Checkout*.
4. Paste your API key and secret from your Allscale dashboard.
5. Click **Test connection**, then **Save changes**.
6. Copy the webhook URL shown in settings and paste it into your Allscale store's webhook field.

== Frequently Asked Questions ==

= Does sandbox mode work? =

Sandbox mode has been retired. To test without real payments, create a **test store** in your Allscale dashboard and use its credentials. Both test and production stores share the same `openapi.allscale.io` base URL.

= Can the plugin configure the webhook URL automatically? =

No — Allscale does not expose webhook-management API endpoints. The merchant must paste the URL into the Allscale dashboard manually. The plugin shows when a webhook was last received so misconfigurations surface quickly.

= Are automatic refunds supported? =

No. Allscale is non-custodial; funds settle directly to your wallet. To refund a customer, send the amount back from your wallet, then update the order status in WooCommerce.

= Which currencies are supported? =

USD, AUD, CAD, CNY, EUR, GBP, HKD, JPY, SGD. You can also enable **native USDT pricing** for crypto-first stores.

== Changelog ==

= 0.0.3 =
* **Renamed**: plugin headline framing changed from "for WooCommerce" to "for WordPress". The plugin still requires WooCommerce (and still ships as a WC payment gateway under the hood) — that's now positioned as a dependency rather than the marketing headline. Class names, namespaces, option keys, hook names, and the text domain are unchanged.
* GitHub repository renamed from `allscale-checkout-woocommerce` to `allscale-wordpress-plugin`. Old URLs continue to redirect via GitHub's rename mechanism.

= 0.0.2 =
* Remove `Api_Client::get_intent_status()` — dead code, never called (the return-URL fallback uses `get_intent_details` for full fields). Restore in 5 lines if a future polling path needs it.
* Documentation accuracy: clarify in architecture.md that `Admin::render_notices` ships 4 notice types (settings save result, first webhook, sandbox migration, credentials missing) and the other 3 from the design brief live in their own surfaces (WC-required notice in `Plugin`, currency-unsupported inline on the settings page, activation/get-started via the setup wizard).

= 0.0.1 =
First pre-release of the AllScale Checkout rewrite. Deliberately labeled v0 — not yet production-stable, expect rough edges.

Major changes vs the prior 0.1.x community beta:

* Sandbox toggle removed (Allscale API consolidated to one base URL — use test-store credentials to test).
* Status enum expanded from 6 to all 12 documented Allscale states.
* Return-URL fallback now reads full intent details (the 0.1.x fallback used the wrong endpoint and never worked correctly).
* `redirect_url` moved to top level; `user_id` / `user_name` now sent.
* Allscale error codes mapped to user-facing copy.
* 0.1 USDT minimum payment enforced up-front.
* Settings save validates credentials via `/v1/test/ping` before storing them.
* New: 4-step setup wizard on first activation.
* New: Test connection button in settings (AJAX-backed).
* New: Allscale Payment meta box on each order — status pill, paid/fee/net breakdown, tx hash with block-explorer link, payment-method type, chain badge.
* New: Front-end thank-you status block (confirmed / pending with auto-refresh / failed).
* New: Webhook health observation (last-received timestamp, first-webhook celebration, stale-after-7-days warning).
* New: Native USDT pricing opt-in for crypto-first stores.
* New: Branded "needs WooCommerce" notice with one-click Install or Activate CTA.
* Fixed: Webhook handler now registered unconditionally on `init`, no longer dependent on the gateway constructor running.
* Fixed: `mb_strimwidth` for order descriptions — CJK / emoji product names no longer get sliced mid-character.
* Fixed: Order_Locker mutex around state mutations — webhook + return-URL fallback can't race.
* Fixed: Late failure-state webhooks no longer revert already-paid orders.
* Fixed: `cancelled` added to terminal-status exclusion.
* Fixed: Gateway and Blocks_Integration classes now lazy-loaded after their WooCommerce parents are confirmed available — avoids a fatal when this plugin loads before WooCommerce.
* Full i18n with text domain `allscale-checkout`.

== Upgrade Notice ==

= 0.0.1 =
First v0 release. Sandbox mode has been retired — use test-store credentials. If you're coming from the 0.1.x community beta, the first activation will show a one-time notice if your previous environment setting was sandbox.
