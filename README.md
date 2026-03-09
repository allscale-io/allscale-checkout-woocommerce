# Allscale Checkout for WooCommerce

A WordPress/WooCommerce payment gateway plugin that lets merchants accept crypto payments via [Allscale Checkout](https://allscale.io). Prices are displayed in your store's local currency, and funds settle instantly as **USDT stablecoin** directly to the merchant's wallet.

## Why Allscale?

- **Non-custodial** — Funds go straight to your wallet. No platform holds your money.
- **Low fees** — 0.5% per transaction (vs ~3-5% on traditional processors).
- **Instant settlement** — On-chain USDT, no waiting days for payouts.
- **No account freezes** — Your funds are on-chain and always accessible.
- **Permissionless setup** — Self-custodial, so you can start accepting payments right away.

## How It Works

1. Customer places an order on your WooCommerce store and selects "Pay with Allscale".
2. The plugin creates a checkout intent via the Allscale API.
3. Customer is redirected to a hosted Allscale checkout page to complete payment using their Allscale account or a crypto wallet (MetaMask, Trust Wallet, etc.).
4. Payment confirms on-chain and Allscale notifies your store via webhook.
5. The WooCommerce order is automatically marked as paid.

## Features

- **WooCommerce payment gateway** — Shows up as a payment option at checkout.
- **Automatic order management** — Orders update in real time based on payment status.
- **Webhook verification** — Cryptographically verifies all incoming webhook notifications (HMAC-SHA256).
- **Status polling fallback** — Polls the Allscale API for payment status in case a webhook is missed.
- **Sandbox mode** — Test the full flow without real transactions.
- **Configurable currency** — Choose which fiat currency to denominate prices in.
- **Secure by design** — API secrets stored encrypted in WordPress, all signing done server-side, timing-safe signature comparison.

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- An Allscale account with Commerce enabled ([sign up](https://allscale.io))

## Installation

1. Download the latest release `.zip` file from [Releases](../../releases).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the `.zip` file and click **Install Now**.
4. Activate the plugin.

Or clone directly into your plugins directory:

```bash
cd wp-content/plugins/
git clone https://github.com/YOUR_USERNAME/allscale-checkout-woocommerce.git allscale-checkout
```

## Configuration

1. Go to **WooCommerce → Settings → Payments → Allscale Checkout**.
2. Enable the payment method.
3. Enter your **API Key** and **API Secret** (obtained from the Allscale dashboard).
4. Select your **environment** (Sandbox for testing, Production for live).
5. Choose your **currency** (must match your WooCommerce store currency).
6. Set your **Webhook URL** in the Allscale dashboard to:
   ```
   https://yoursite.com/wc-api/allscale_checkout
   ```
7. Save changes and you're ready to accept payments.

## Allscale Setup

1. Create an account at [allscale.io](https://allscale.io).
2. Enable **Allscale Commerce** in your dashboard.
3. Create a **Store** and configure your USDT receiving wallet address.
4. Generate an **API Key** and **API Secret** (the secret is shown only once — save it).
5. Set your webhook URL to point to your WordPress site (see Configuration above).

## Development

```bash
# Clone the repo
git clone https://github.com/YOUR_USERNAME/allscale-checkout-woocommerce.git
cd allscale-checkout-woocommerce

# For local WordPress development, symlink into your plugins directory
ln -s $(pwd) /path/to/wordpress/wp-content/plugins/allscale-checkout
```

## License

GPLv2 or later — see [LICENSE](LICENSE) for details.

## Links

- [Allscale Website](https://allscale.io)
- [Allscale API Documentation](https://github.com/allscale-io/allscale-checkout-skill)
- [WooCommerce Payment Gateway API](https://woocommerce.com/document/payment-gateway-api/)
