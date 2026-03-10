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
- **Sandbox mode** — Test the full flow without real transactions.
- **Currency auto-detection** — Automatically uses your WooCommerce store currency for pricing.
- **Secure by design** — All API signing done server-side, timing-safe signature comparison.

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
git clone https://github.com/shawnpang/allscale-checkout-woocommerce.git allscale-checkout
```

## Setup Guide

### Step 1: Get your Allscale API credentials

1. Create an account at [allscale.io](https://allscale.io).
2. Enable **Allscale Commerce** in your dashboard.
3. Create a **Store** and configure your USDT receiving wallet address.
4. Generate an **API Key** and **API Secret** (the secret is shown only once — save it).

### Step 2: Enable the payment gateway in WooCommerce

1. In your WordPress admin, go to **WooCommerce → Settings** in the left sidebar.
2. Click the **Payments** tab.
3. Find **Allscale Checkout** in the list of payment providers and click **Enable**, then **Manage**.

> **Tip:** You can also get there from **Plugins → Installed Plugins** and clicking the **Settings** link under Allscale Checkout.

![Allscale Checkout in WooCommerce Payments settings](assets/woocommerce-payments-setup.png)

### Step 3: Configure the plugin

1. Enter your **API Key** and **API Secret** from Step 1.
2. Select your **Environment** (Sandbox for testing, Production for live payments).
3. Click **Save changes**.

### Step 4: Set up the webhook

1. Copy the **Webhook URL** shown at the bottom of the plugin settings page. It looks like:
   ```
   https://yoursite.com/wc-api/allscale_checkout
   ```
2. In your Allscale dashboard, paste this URL as your store's webhook endpoint.

That's it — your store is now accepting crypto payments.

## Development

```bash
# Clone the repo
git clone https://github.com/shawnpang/allscale-checkout-woocommerce.git
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
