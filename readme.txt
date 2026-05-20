=== Allscale Checkout for WooCommerce ===
Contributors: allscale
Tags: woocommerce, payment gateway, crypto, usdt, stablecoin, non-custodial
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept crypto payments in WooCommerce with 0.5% fees and instant USDT settlement to your own wallet. Non-custodial.

== Description ==

Allscale Checkout is a WooCommerce payment gateway that lets your customers pay with crypto while funds settle instantly as USDT stablecoin directly to your wallet — Allscale never holds your money.

= Why use Allscale? =

* **Non-custodial.** Funds go straight to your wallet. No third party holds your money. No account freezes.
* **Low fees.** 0.5% per transaction (vs. ~3% on traditional processors).
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

= 1.0.1 =
* **Fix critical activation fatal** — `class Gateway extends \WC_Payment_Gateway` was loaded unconditionally at plugin file load, causing a fatal error on sites where the plugin folder name sorts before "woocommerce" (e.g. when installed from a GitHub release ZIP whose folder is `allscale-checkout-woocommerce-1.0.0/`). The class is now loaded lazily inside `Plugin::boot()` after the WC parent class is confirmed available. Matches the same fix already applied to `Blocks_Integration` in 1.0.0.
* **Branded "needs WooCommerce" notice** — Replaced the default red error bar with a brand-styled gradient card that distinguishes "WooCommerce not installed" from "WooCommerce installed but inactive" and provides a one-click CTA (Install or Activate) deep-linked with a valid nonce.

= 1.0.0 =
* First stable release. Full rewrite of the 0.1.x community beta.
* Removed sandbox toggle (Allscale API no longer ships a separate sandbox URL).
* Expanded status enum to the 12 documented Allscale statuses (TIMEOUT, PAYING, TEMP_WALLET_RECEIVED, PENDING_MANUAL_OPERATION, SEND_BACK).
* Return-URL fallback now reads full intent details (status endpoint returns a bare int — the 0.1.x fallback never worked correctly).
* New: Test connection button + AJAX endpoint.
* New: Settings save rejects bad credentials before they're stored.
* New: Per-order Allscale Payment meta box with tx hash + chain explorer link.
* New: Webhook health observation (last received, first-webhook celebration, stale warning).
* Fixed: Webhook handler now registered unconditionally on `init`, not from the gateway constructor.
* Fixed: UTF-8 truncation bug (now uses `mb_strimwidth`).
* Fixed: Race between webhook and return-URL fallback (order locker).
* Fixed: Removed redundant pending-status update.
* Full i18n with text domain `allscale-checkout`.

== Upgrade Notice ==

= 1.0.0 =
Major rewrite. Sandbox mode has been retired — use test-store credentials. The first activation after upgrade will show a one-time notice if your previous setting was sandbox.
