# Allscale Checkout for WooCommerce

A WordPress / WooCommerce payment gateway that lets merchants accept crypto payments via [Allscale Checkout](https://allscale.io). Prices are displayed in your store's local currency and funds settle instantly as **USDT stablecoin** directly to the merchant's own wallet — Allscale never holds your money.

## Why Allscale?

- **Non-custodial.** Funds go straight to your wallet. No platform holds your money. No account freezes.
- **Low fees.** 0.5% per transaction (vs. ~3% on traditional processors).
- **Instant settlement.** On-chain USDT — no multi-day payout delays.
- **Permissionless setup.** Sign up, paste your credentials, start accepting payments.

## How it works

1. Customer places an order on your WooCommerce store and selects **Pay with Crypto (Allscale)**.
2. The plugin creates a checkout intent via the Allscale API.
3. Customer is redirected to a hosted Allscale checkout page to pay with their Allscale account or a crypto wallet (MetaMask, Trust Wallet, etc.).
4. Payment confirms on-chain. Allscale sends a signed webhook to your store and the order is marked paid.
5. Customer is redirected back to your "thank you" page; the plugin also double-checks the payment status as a safety net.

### Payment confirmation paths

The plugin confirms every payment via two independent paths so an order never gets stuck:

- **Webhook (server-to-server)** — Allscale's primary delivery channel. Verified with HMAC-SHA256 and nonce de-duplication. Works even if the customer closes their browser after paying.
- **Return-URL fallback** — When the customer lands on the thank-you page, the plugin asks the Allscale API for the current intent details and reconciles the order. Ensures the buyer sees "Payment confirmed" immediately even if the webhook is briefly delayed.

Both paths share a single status-mapping decision table and run inside an order lock, so they never duplicate notes or double-complete an order.

## Features

- WooCommerce payment gateway showing up natively in `WooCommerce → Settings → Payments`.
- HMAC-SHA256 request signing and webhook verification (timing-safe).
- Native USDT-only pricing option for crypto-first stores.
- "Test connection" button + automatic credential validation on save.
- Allscale Payment meta box on each paid order: tx hash with block-explorer link, payment-method type, paid / fee / net breakdown, chain ID.
- Webhook health observation: last-received timestamp surfaced in settings, first-webhook celebration notice, stale-webhook warning after 7 days.
- HPOS compatible. Block-based checkout compatible.
- Full i18n with text domain `allscale-checkout`.

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- An [Allscale account](https://allscale.io) with Commerce enabled

> **First time?** Sign up at [allscale.io](https://allscale.io). Allscale is non-custodial, charges only 0.5% per transaction, and settles instantly as USDT to your own wallet.

## Installation

1. Download the latest release ZIP from [GitHub](https://github.com/allscale-io/allscale-checkout-woocommerce/archive/refs/heads/main.zip).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, click **Install Now**, then **Activate**.
3. Open **WooCommerce → Settings → Payments → Allscale Checkout** and follow the in-page welcome.

## Setup

### 1. Get your Allscale credentials

1. Create an account at [allscale.io](https://allscale.io).
2. Enable **Allscale Commerce** in your dashboard.
3. Create a **store** and configure your USDT receiving wallet address.
4. Generate an **API key** and **API secret** (the secret is shown only once — save it).

### 2. Configure the plugin

1. Go to **WooCommerce → Settings → Payments → Allscale Checkout**.
2. Paste your **API key** and **API secret**.
3. Click **Test connection** to verify the credentials.
4. Click **Save changes**. The plugin will validate credentials again on save and reject the save with a specific error if the credentials are wrong.

> **Testing without real payments?** Sandbox mode has been retired. Create a **test store** in your Allscale dashboard and use that store's credentials — both production and test stores share the same `openapi.allscale.io` base URL.

### 3. Configure the webhook

1. Copy the webhook URL shown in the plugin settings. It looks like:
   ```
   https://yoursite.com/wc-api/allscale_checkout
   ```
2. In your Allscale dashboard, paste this URL into your store's webhook setting.
3. The Allscale API does not expose webhook management endpoints — this step has to happen in the dashboard. After your first successful payment, the plugin will show a "First webhook received" confirmation in the WordPress admin.

## Refunds

Allscale is **non-custodial** — funds settle directly to your wallet, never to a platform account. Automatic refunds via WooCommerce are therefore not supported. To refund a customer:

1. Send the refund amount back to the customer manually from your wallet.
2. In WooCommerce, update the order status to **Refunded**.

## Abandoned orders

If a customer starts checkout but never completes payment, the order stays as "Pending payment." WooCommerce automatically cancels unpaid pending orders based on your **Hold stock** setting at *WooCommerce → Settings → Products → Inventory → Hold stock (minutes)*. The default is 60 minutes.

## Architecture

For implementation details — module-by-module design, known-issue resolutions, migration plan, and what's deliberately out of scope — see [`docs/architecture.md`](docs/architecture.md).

For the UI specification driving the admin design, see [`docs/design-brief.md`](docs/design-brief.md).

## Development

```bash
git clone https://github.com/allscale-io/allscale-checkout-woocommerce.git
cd allscale-checkout-woocommerce

# For local development, symlink into your WP plugins directory:
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/allscale-checkout
```

The plugin uses PHP namespaces (`Allscale\Checkout\`) and explicit `require_once` (no autoloader) so the file count stays small and traceable.

## License

GPLv2 or later — see [LICENSE](LICENSE) for details.

## Links

- [Allscale](https://allscale.io)
- [Allscale API documentation](https://docs.allscale.io/allscale-checkout/getting-started)
- [Allscale Checkout integration guide](https://github.com/allscale-io/allscale-checkout-skill) (for AI coding agents)
- [WooCommerce Payment Gateway API](https://woocommerce.com/document/payment-gateway-api/)
