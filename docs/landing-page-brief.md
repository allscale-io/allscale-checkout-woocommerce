# Landing Page Content Brief — Allscale Checkout for WordPress

> A content brief for Claude Design. **Visual style, layout, typography, colors, components, URL** are all the designer's call — match the existing Allscale family (`allscale.io`, `labs.allscale.io`). This document only specifies **what the page needs to say** and **how the content is structured**.

---

## 1. Product, in one paragraph

**Allscale Checkout for WordPress** is a free, open-source WordPress plugin that turns any WooCommerce-powered WordPress store into a crypto payment acceptor. Customers pay in USDT via a hosted Allscale checkout page; the merchant receives USDT directly to their own on-chain wallet within seconds. Non-custodial — Allscale never holds the merchant's money, and there's no account that can be frozen, no payout schedule, and no chargeback risk. Fee: 0.6% per transaction with a $0.10 minimum.

The plugin is distributed on GitHub (free download) and requires a free Allscale account to function.

---

## 2. The audience and the persona switcher

The page targets WordPress merchants in three rough buckets. The hero should include a **persona switcher** (3-way toggle) that doesn't change the headline but **does change**:

(a) a one-line tagline under the hero subhead
(b) an example "order" visual (a stylized order card or product mockup)
(c) the per-pillar accent in the "Why us" section below

**Persona 1 — Ecommerce sellers**
*Physical goods, dropshipping, retail.*
- One-liner: *"Your store, your customers, your wallet. Stop sending 3% to your processor every time someone checks out."*
- Example order: a physical-product order (e.g., "$45 hoodie", "$180 sneakers", or a generic packaged-goods illustration).
- Pain we hit: 3% Stripe/PayPal fees compound on high-volume stores.

**Persona 2 — Virtual services**
*Online courses, consulting, digital downloads, SaaS.*
- One-liner: *"Invoice, deliver, get paid. Settle in seconds, no payout schedule, no chargebacks on completed work."*
- Example order: a service-style order (e.g., "$120 1-hour consult", "$200 design package", "$49 online course").
- Pain we hit: cashflow is starved by 3-day payout schedules; chargeback risk on intangibles.

**Persona 3 — Crypto businesses**
*NFT shops, hardware-wallet retailers, on-chain education, DeFi-adjacent stores.*
- One-liner: *"Already in the stablecoin economy. Accept payments in the same USDT you settle in."*
- Example order: a crypto-native order (e.g., "$2,500 hardware miner", "$300 cold-storage wallet", "$50 NFT mint").
- Pain we hit: existing custodial gateways are off-ramps into fiat, which is the opposite of where these businesses want their treasury.

The default persona on first load is **Ecommerce sellers** (broadest reach).

---

## 3. Page structure & content per section

### 3.1 Hero

**Headline (constant across personas):**
> Accept crypto on WordPress.

**Subhead (constant):**
> Paid directly to your wallet. 0.6% fees. Instant USDT settlement.

**Persona switcher:**
> For [ Ecommerce sellers ▾ | Virtual services | Crypto businesses ]
>
> *(see §2 for the per-persona tagline + order example that appears below)*

**Primary CTA button:** *Download for WordPress →*
**Secondary CTA (link or ghost button):** *Read the documentation*

**Microcopy under CTAs (constant):**
> Requires WooCommerce · Free and open source on GitHub

**Hero visual hint:** The settings page screenshot ([`docs/screenshots/settings-healthy.png`](../docs/screenshots/settings-healthy.png)) is the strongest single visual we have — designer can use it directly or composite it next to a persona-specific order card.

---

### 3.2 Why Allscale — three pillars

Three columns / cards. Each is a heading + 1-paragraph body + a persona-specific accent line that swaps with the hero switcher.

---

**Pillar 1 — Non-custodial. Your funds, your wallet, always.**

> Payments settle on-chain straight to the USDT wallet address you control. Allscale never holds your money. There's nothing to freeze, no payout schedule, no rolling reserve, and no compliance officer can pause your account. The merchant of record on every transaction is you.

Per-persona accent line:
- **Ecommerce:** *Your revenue isn't trapped during a refund dispute.*
- **Virtual services:** *Completed work means received payment — no "pending" for 5–10 business days.*
- **Crypto businesses:** *Your treasury already runs on-chain. Your payment processor should too.*

---

**Pillar 2 — 0.6% per transaction. With a $0.10 minimum. That's it.**

> Stripe charges 2.9% + $0.30 per transaction. PayPal charges 3.4% + a fixed fee. Custodial crypto gateways charge ~1%. Allscale charges 0.6%, period. No tiers, no international-card markup, no chargeback-insurance line items, no monthly subscription.

Per-persona accent line:
- **Ecommerce:** *On a $1,000-a-day store, that's $24 daily in fees vs $90 with Stripe.*
- **Virtual services:** *Higher-ticket sales compound the savings every transaction.*
- **Crypto businesses:** *Wallet-to-wallet settlement, priced like wallet-to-wallet.*

---

**Pillar 3 — Instant settlement, on-chain.**

> Funds arrive in seconds, not days. There's no 1–3 business-day payout, no minimum payout threshold, no weekend pause, no holiday delay. Your money is yours the moment the on-chain transaction confirms.

Per-persona accent line:
- **Ecommerce:** *Cash-flow positive from minute one of every sale.*
- **Virtual services:** *Funds in your wallet before the consult ends.*
- **Crypto businesses:** *Settlement = where your business already operates.*

---

**Tone for this section:** The pillars should subtly contrast with "custodial payment processors" without naming Stripe / PayPal / BitPay directly in the body copy. The fee comparison in Pillar 2 is the one place where we name Stripe / PayPal explicitly — that's a factual statement, not a competitor swipe.

---

### 3.3 How it works — three steps

Three numbered steps, illustrated. Tight copy:

1. **Customer picks Allscale at checkout.** It appears in your WooCommerce payment method list alongside other gateways. You can customize the label and description.

2. **They pay on Allscale's hosted page.** Wallet scan, WalletConnect, or Allscale Pay — your customer's choice. No card data ever touches your server.

3. **You receive USDT, in seconds.** The transaction confirms on-chain, your wallet balance goes up, the WooCommerce order is marked paid automatically. A webhook to your store also fires for redundancy.

---

### 3.4 Features showcase

Four feature cards, each pairing **one of the existing screenshots** with a short caption. Screenshots are at `docs/screenshots/` in the plugin repo.

---

**Feature 1: Configure once, get on with selling.**

Screenshot: `settings-healthy.png`

> Paste your API credentials, copy the webhook URL into your Allscale dashboard, and you're live. The settings page lays out the whole config on one screen — no nested submenus, no walls of help text. Green pills confirm credentials are verified and webhooks are arriving.

---

**Feature 2: Guided setup in three minutes.**

Screenshot: `wizard-webhook.png`

> A 4-step wizard walks you from activation to first payment. The plugin tests your credentials against Allscale's API before saving them, so you never ship a typo to production.

---

**Feature 3: Every payment, fully auditable.**

Screenshot: `order-meta-box.png`

> Each order shows the on-chain transaction hash (one click to the block explorer for Ethereum, Polygon, BNB Chain, Base, Optimism, or Arbitrum), the chain it cleared on, the payment method the customer chose, and the exact USDT amount you received after fees.

---

**Feature 4: Plays well with WordPress's ecosystem.**

Screenshot: `wc-required-notice.png`

> Compatible with WooCommerce's High-Performance Order Storage (HPOS), the block-based checkout, and the classic checkout. If WooCommerce isn't installed yet, the plugin doesn't crash — it shows you a one-click install prompt and waits politely.

---

**Below the feature cards, a short paragraph of secondary features (no screenshots):**

> Other niceties: webhook health monitoring (last-received timestamp + stale-after-7-days warning), per-order block-explorer links across six EVM chains, full i18n via the WordPress text-domain system, idempotent migrations from any previous community plugin, and a thank-you page status block that auto-refreshes while the customer's payment is confirming.

---

### 3.5 Pricing

A clean, single-block pricing section. No tiers — there's only one price.

**Headline:** *Simple pricing.*

**Big number (the focal visual):**
> **0.6%** per transaction
> **$0.10** minimum per transaction

**Body copy:**
> No setup fee. No monthly fee. No chargeback insurance line items. No markup on international cards. No tiered pricing that gets worse as you grow. You pay 0.6% of each transaction, with a $0.10 minimum to cover the on-chain gas Allscale pays on your behalf.

**Comparison microcopy (optional, designer's call whether to include):**
> *For reference: Stripe charges 2.9% + $0.30. PayPal charges 3.4% + $0.49. Custodial crypto processors charge ~1%.*

---

### 3.6 FAQ

Six entries. Each is a question + 2–4 line answer.

---

**Q: Do my customers need a crypto wallet?**

A: Yes. They pay using MetaMask, Trust Wallet, any WalletConnect-compatible wallet, or via their own Allscale account. They don't need a credit card or a bank account.

---

**Q: Why USDT only? What about Bitcoin or other tokens?**

A: USDT is a stablecoin — its price is pegged to the US dollar, so your customer pays the exact amount you list and you receive the exact amount they paid. No volatility window between the sale and the settlement. Other stablecoins (USDC, etc.) are on Allscale's roadmap; volatile assets like Bitcoin aren't a fit for routine ecommerce.

---

**Q: How do refunds work?**

A: Manually. Because Allscale is non-custodial, there's no platform-held balance to refund from automatically — the money is already in your wallet. You send the refund amount back to the customer's address from your wallet, then update the order's status in WooCommerce. The plugin documents this in the order screen.

---

**Q: What does "non-custodial" actually mean?**

A: Allscale never holds your money. The customer's payment confirms on-chain directly to the USDT wallet address you specify in your Allscale dashboard. There's no Allscale account balance, no payout cycle, no "pending funds" that haven't arrived yet. It's wallet-to-wallet.

---

**Q: Does this work with the WordPress block-based checkout?**

A: Yes. The plugin is compatible with both the classic WooCommerce checkout and the newer block-based checkout. Customers see the same Allscale payment option either way.

---

**Q: What chains does USDT settle on?**

A: The chain is determined by the customer's wallet at checkout time. The plugin records and links block-explorer details for Ethereum, Polygon, BNB Chain, Base, Optimism, and Arbitrum. Your USDT wallet just needs to be reachable on whichever chain your customer used.

---

**Q: Is the source code open?**

A: Yes — GPLv2 on GitHub. You can audit it, fork it, contribute fixes, or self-host without restriction. There's no proprietary core hidden behind a SaaS endpoint; the whole plugin lives on your server.

---

### 3.7 Final CTA

A wide, prominent CTA section near the end of the page.

**Headline:** *Start accepting crypto on WordPress.*

**Two buttons:**
- *Download from GitHub →* (primary — links to the latest release ZIP)
- *Read the documentation* (secondary — links to the README)

**Trust microcopy under buttons (one line):**
> Free · open source on GitHub · GPLv2 · no vendor lock-in

---

### 3.8 Footer

Designer's call — match the rest of the Allscale site family. The page should sit naturally inside the existing Allscale navigation / footer chrome.

---

## 4. Tone of voice

- **No marketing fluff.** Match the labs.allscale.io ethos. Direct, specific, factual.
- **Numbers > adjectives.** "0.6% per transaction" beats "low fees." "Seconds" beats "fast."
- **Don't name competitors in body copy** except in the Pricing comparison microcopy, where naming Stripe + PayPal is a factual reference, not a swipe.
- **Don't oversell.** No "the future of payments" / "revolutionary" / "game-changing." Let the value props speak.
- **Don't mention v0 / pre-release / beta status.** Treat the plugin as a real, available product. (We do have a feedback channel via the GitHub issue tracker for bug reports — that's enough acknowledgment.)
- **Technical-but-friendly.** Assume the merchant knows what "wallet" and "on-chain" mean but might not know what HPOS or webhooks are.

---

## 5. What's deliberately out of scope

- **Competitor comparison table.** No side-by-side feature matrix vs. Stripe / BitPay / Coinbase Commerce. The pillar copy hints at the contrast; explicit tables read as adversarial.
- **Testimonials / social proof.** We don't have any yet. Leave space for the future ("Trusted by …" / customer logos) but don't fabricate.
- **Localization.** English only for now.
- **Multi-page.** This is one scrolling page. If it needs to split, the designer decides — but the brief assumes single-page.

---

## 6. Existing assets to reference

In the plugin repository (`allscale-io/allscale-wordpress-plugin`):

| Asset | Purpose |
|---|---|
| `docs/screenshots/settings-healthy.png` | Hero candidate + Feature 1 |
| `docs/screenshots/wizard-webhook.png` | Feature 2 |
| `docs/screenshots/order-meta-box.png` | Feature 3 |
| `docs/screenshots/wc-required-notice.png` | Feature 4 |
| `assets/icon.png` | Allscale brand mark (the "All" with green corner brackets) |
| `assets/logo.svg` | Allscale wordmark (horizontal lockup) |
| `assets/chains/*.png` | EVM chain mark images (eth, polygon, base, bnb, arbitrum, optimism) — useful for visual decoration |
| `assets/usdt.png` | USDT badge |

---

## 7. Deliverables expected from the designer

Whatever format works best for the design tool — Figma file, HTML/CSS mockups, or whatever Claude Design typically produces. The implementer (Claude or human) will translate the design into the same tech stack as labs.allscale.io.

If feasible, please specify:
- Desktop + mobile breakpoints
- The persona-switcher interaction (what changes when, in detail)
- The hover / focus states for the two CTAs

---

## 8. Open questions for the designer to surface

If anything in the persona content variations reads as forced or doesn't fit the design, please flag it and propose alternates rather than executing dutifully. Same for any FAQ entry that doesn't fit the layout — the brief is the floor, not the ceiling.
