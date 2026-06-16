# Allscale Checkout for WordPress — UI Design Brief

> A handoff document for a designer (Claude Design or human). Describes every visible surface of the rebuilt plugin, the states each surface can be in, the copy, the responsive behavior, and the WordPress / WooCommerce conventions that must be respected.

---

## 1. Product context (read this first)

**What it is.** A WooCommerce **payment gateway plugin**. Installs into a WordPress site that runs WooCommerce. Lets the merchant accept crypto payments via Allscale — the customer pays USDT (a stablecoin), the merchant receives USDT directly in their own wallet. **Non-custodial**: Allscale never holds the merchant's money.

**Who installs it.** A small-business merchant who already runs a WooCommerce store. Not a developer. Probably has used Stripe / PayPal plugins before. Reads English (we will also produce localized strings later, but the English copy is what the designer should design around).

**Why they install it.** Lower fees than Stripe/PayPal (0.6% per transaction with a $0.10 minimum vs ~3%), instant settlement, no chargebacks, no account freezes. The product's value prop is that the merchant **owns their funds the whole time**.

**Brand vibe.**
- Trustworthy, calm, technical-but-not-intimidating. Closer to **Stripe Dashboard** than to **Crypto Twitter**.
- Crypto-native enough to mention "USDT" and "wallet" without apology, but never assumes the user knows what a hash or a chain ID is.
- Non-custodial / self-custody is the headline trust signal — surface it where it earns trust without spamming.

**The single most important design constraint.** This plugin lives **inside the WordPress admin UI**. It cannot redesign the WP admin chrome. We work within WordPress's existing visual language (the "fresh" color scheme by default, standard form fields, standard buttons, `.notice` banners). Custom design moves should feel like a **first-class native extension of WordPress** — not a different product bolted on.

**Hard implementation limits the designer must know.**
- We **cannot** programmatically register the merchant's webhook URL — they must paste it into the Allscale dashboard themselves. Designs for the webhook block must accept this.
- The Allscale API exposes only 6 endpoints. We **cannot** show transaction lists, merchant info, wallet balances, or anything sourced from Allscale beyond per-order intent details. Don't design dashboards that imply we have data we don't have.
- Sandbox mode no longer exists in the API. Testing is done by creating a **test store** in Allscale dashboard, which issues separate credentials. The plugin doesn't toggle environments.
- Refunds cannot be automated. The merchant refunds manually from their own wallet.

---

## 2. The end-to-end user journey

The journey we're designing for, in order:

1. **Install** — merchant downloads the plugin ZIP from GitHub, uploads via *Plugins → Add New → Upload Plugin*, clicks Activate.
2. **First admin notice** — a one-time dismissible banner appears at the top of the admin: "Allscale Checkout is activated. Set it up →" linking to the gateway settings page.
3. **Settings page (first visit, empty state)** — merchant lands on *WooCommerce → Settings → Payments → Allscale Checkout*. API Key and Secret fields are empty. The page makes it obvious what to fill in first.
4. **Enter credentials** — merchant pastes API Key + Secret from their Allscale dashboard.
5. **Test connection** — they click "Test connection" button. The plugin calls `GET /v1/test/ping`. Result: ✓ success or ✗ specific error.
6. **Save** — clicking Save Changes triggers a second validation `ping`; if it fails, the save is rejected with a specific message.
7. **Copy webhook URL** — the webhook block becomes more prominent now that credentials are valid. Merchant clicks "Copy" and pastes the URL into the webhook field in their Allscale dashboard.
8. **First real customer payment** — customer pays. The plugin receives its first webhook.
9. **Celebratory notice** — a one-time dismissible banner appears: "✓ First webhook received — your store is fully wired up."
10. **Ongoing** — settings page now shows "Webhook status: received N minutes/hours ago ✓".
11. **Order detail page** — every paid order shows an "Allscale Payment" meta box with tx hash, payment method, amounts breakdown.

The design must support every state along this journey, including the **empty state** at step 3 and the **healthy steady state** after step 10. Don't design only the "everything-configured-and-recent-activity" version.

---

## 3. Inventory of surfaces to design

Priority key: **P0** = must design for v1, **P1** = strong-want for v1, **P2** = v1.1 or later.

| # | Surface | Priority | Where it lives |
|---|---|---|---|
| 3.1 | Plugins list row | P0 | WP admin → Plugins (mostly copy + Settings link) |
| 3.2 | Post-activation admin notice | P0 | Top of any admin page, dismissible |
| 3.3 | Gateway settings page (main surface) | **P0** | WC → Settings → Payments → Allscale Checkout |
| 3.4 | Empty state of settings page (first visit) | P0 | Same as 3.3, before credentials |
| 3.5 | Order meta box "Allscale Payment" | P0 | Edit order screen, side column |
| 3.6 | Admin notices (errors / warnings / celebrations) | P0 | Top of admin pages |
| 3.7 | Front-end checkout payment method | P0 | Customer-facing WC checkout |
| 3.8 | Front-end order-received page | P0 | Customer "thank you" page (mostly WC default + 1 status block) |
| 3.9 | Setup wizard (stretch) | P1 | Full-page take-over after activation |
| 3.10 | Block-based checkout payment method | P1 | Customer-facing Gutenberg checkout (functionally same as 3.7) |

P0 surfaces below are described in detail. P1 are sketched at the end.

---

## 4. Surface-by-surface specifications

### 4.1 Plugins list row (P0)

**Purpose.** The standard WP plugin row, with a "Settings" action link.

**Layout.** This is rendered by WordPress core. We control:
- **Plugin name** (header): `Allscale Checkout`
- **Description** (1-2 sentences below name): `Accept crypto payments with 0.6% fees (min $0.10) and instant USDT settlement to your own wallet. Non-custodial — your funds are never held by a third party. Requires a free Allscale account.`
- **Action links** (left side, before Activate/Deactivate): `Settings | Deactivate`
- **Meta line** (right side): `Version 1.0.0 | By Allscale community | View details | Visit plugin site`

**Design ask.** Nothing custom — this is pure WP. Designer should NOT mock this up unless they want to show WP context around it.

---

### 4.2 Post-activation admin notice (P0)

**Purpose.** First-run signal that the plugin exists, where to go next. Dismissible. Shown until the merchant either (a) clicks the CTA, (b) dismisses, or (c) saves valid credentials.

**Layout.**
```
┌────────────────────────────────────────────────────────────────────┐
│ [×]  [icon]  Allscale Checkout activated.                         │
│              Add your API credentials to start accepting payments. │
│              [ Set up Allscale Checkout → ]                       │
└────────────────────────────────────────────────────────────────────┘
```

- Uses WP's `notice notice-info notice-large is-dismissible` styling as the base.
- Left icon: small Allscale logo mark.
- Primary CTA button uses `.button .button-primary` styling.
- Dismiss "×" upper right.

**States.** Just shown / dismissed. Stops appearing once credentials are saved (regardless of dismiss).

**Mobile.** On `<782px`, button stacks below the text block, full-width.

---

### 4.3 Gateway settings page (P0 — the main surface)

This is the **single most important screen in the plugin**. Spend the most design budget here.

**Where it is.** *WooCommerce → Settings → Payments → Allscale Checkout*. The outer page chrome (top nav, secondary nav tabs, "Save changes" button at the bottom) is rendered by WooCommerce — we cannot change it. We render the form content **inside** that chrome.

**WC's structural constraint.** WC payment gateways traditionally render as a single `<table class="form-table">` with rows of `<th>label</th><td>input</td>`. We can break free from this — many modern WC gateways do — but our custom HTML must still feel native. Suggested approach: **render as a series of card-like sections** (using `<div>` blocks styled to match WC's `.postbox` aesthetic), with the form-table inside each section as needed.

**Section layout, top to bottom:**

```
┌── Page chrome rendered by WooCommerce (don't redesign) ─────────────┐
│  WooCommerce ▸ Settings ▸ Payments ▸ Allscale Checkout              │
│                                                                      │
│  ┌─ Section 1: Status & visibility ────────────────────────────┐    │
│  │  [☐] Enable Allscale Checkout                               │    │
│  │  Title shown to customers:    [Pay with Crypto (Allscale) ]│    │
│  │  Description shown:           [Pay securely with...       ]│    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  ┌─ Section 2: API credentials ───────────────────────────────┐    │
│  │  API Key       [st_•••••••••••••••••••••••••••••••]        │    │
│  │  API Secret    [••••••••••••••••••••••••••••••••••]  [Show]│    │
│  │  Don't have credentials yet? Sign up at allscale.io →       │    │
│  │                                                              │    │
│  │  [ Test connection ]    ● Not tested                        │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  ┌─ Section 3: Webhook setup ─────────────────────────────────┐    │
│  │  Webhook URL                                                │    │
│  │  ┌─────────────────────────────────────────────┐ [ Copy ] │    │
│  │  │ https://yoursite.com/wc-api/allscale_checkout│         │    │
│  │  └─────────────────────────────────────────────┘          │    │
│  │  Paste this into your Allscale store's webhook setting.    │    │
│  │                                                              │    │
│  │  Webhook status:  ● Never received yet                      │    │
│  │  (Help link: How to configure webhooks →)                   │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  ┌─ Section 4: Payment configuration ─────────────────────────┐    │
│  │  Pricing currency:  USD ✓ (your store currency)            │    │
│  │  [☐] Use native USDT pricing instead of fiat conversion    │    │
│  │       (Advanced — for stores that want to display USDT     │    │
│  │       amounts directly. Most stores leave this off.)        │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  ┌─ Section 5: Advanced (collapsed by default) ──────────[▾]─┐    │
│  │  [☐] Enable debug logging                                  │    │
│  │       Writes detailed activity to WooCommerce logs at      │    │
│  │       WC ▸ Status ▸ Logs.                                   │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  [ Save changes ]   (rendered by WooCommerce, don't touch)          │
└──────────────────────────────────────────────────────────────────────┘
```

**Section-by-section design notes:**

#### Section 1: Status & visibility
- Standard WC enable checkbox, no special design.
- Title and description are short text inputs.
- No need to mock unless designer wants to show WP form context.

#### Section 2: API credentials
- API Key field: standard text input. When a value is already saved, show the **last 4 characters** prefixed by `st_••••••` rather than the full secret. Reveal-on-click ("Show" link) toggles visibility.
- API Secret field: same masking treatment.
- "Test connection" button: see [Component: Test Connection Button](#component-test-connection-button).
- Below the secret field, in small gray text: a help link to allscale.io for sign-up.

#### Section 3: Webhook setup
- The webhook URL is in a **read-only code-styled block**. Monospaced font, light-gray background, rounded corners. Click anywhere selects all.
- "Copy" button to the right of the URL block. On click → URL is copied to clipboard, button transforms briefly to "✓ Copied" (~1.5s), then back.
- Below: **webhook status pill** — see [Component: Status Pill](#component-status-pill) — with states: `Never received yet`, `Received N minutes ago ✓`, `Received N hours ago ✓`, `Received N days ago ⚠` (if >7 days while orders are flowing), `Last received 2 weeks ago ⚠`.
- Optional: a small "?" tooltip next to "Webhook status" that explains in plain English what a webhook is and why this status matters.

#### Section 4: Payment configuration
- Show the **WooCommerce store currency** with a checkmark if it's supported. If it's not supported by Allscale (e.g., MXN, INR, BRL), show a warning state:
  > ⚠ Your store currency **MXN** is not supported by Allscale. Allscale Checkout supports USD, EUR, GBP, CAD, AUD, JPY, CNY, SGD, HKD. Change your store currency in *WooCommerce → Settings → General* or enable the gateway only for stores using a supported currency.
- The "Use native USDT pricing" toggle is for the rare merchant who wants USDT amounts displayed directly (advanced use). Default off. Help-text in small print.

#### Section 5: Advanced
- Collapsible card, collapsed by default. A chevron in the header rotates on expand/collapse.
- Inside: debug logging toggle.

**States for the whole settings page:**

| State | Trigger | What changes |
|---|---|---|
| **Empty (first visit)** | API key + secret are empty | Section 2 visually emphasized (subtle border highlight). Section 3 webhook URL shown but slightly muted. Sections 4-5 collapsed or de-emphasized. |
| **Credentials filled but not tested** | Values entered, not saved | Test connection pill says "Not tested". |
| **Test passed** | `/v1/test/ping` returned success | Pill: `● Connected ✓` (green). |
| **Test failed** | ping returned 20002, 20001, 30001, timeout, etc. | Pill: `● Test failed — <specific reason>` (red) + inline help text matching the error code |
| **Saved, no webhook yet** | Save succeeded, no webhook received | Section 3 webhook status: `● Never received yet` (gray dot). |
| **Healthy** | Save succeeded + webhook received recently | Section 3 webhook status: `● Received 4 minutes ago ✓` (green). |
| **Webhook stale** | Save succeeded, no webhook in >7 days while gateway enabled | Section 3 webhook status: `● No webhook in 8 days ⚠`. Yellow dot. Help text expands explaining what to check. |

**Mobile (<782px):**
- Section cards stack full-width with vertical spacing.
- Form labels move above inputs (WP default).
- "Test connection" button stacks below the API Secret field; status pill stacks below the button.
- Webhook URL code block remains full-width but the "Copy" button stacks below it on the smallest screens (alternative: keep inline if width permits).

---

### 4.4 Empty state of settings page (P0)

**Purpose.** Make the first-visit experience self-explanatory. A merchant who knows nothing should understand what to do next within 5 seconds.

**Layout.** Same overall structure as 4.3, but with these adjustments:
- A **top callout banner** spanning all sections:
  ```
  ┌────────────────────────────────────────────────────────────┐
  │  Welcome to Allscale Checkout                              │
  │  Three steps to start accepting crypto payments:           │
  │   1. Enter your API credentials  →  see Section 2 below    │
  │   2. Test the connection                                    │
  │   3. Paste your webhook URL into your Allscale dashboard   │
  └────────────────────────────────────────────────────────────┘
  ```
- Numbered step indicators next to each relevant section header.
- Sections beyond credentials (webhook, advanced) are visually de-emphasized (e.g., opacity 70%, smaller header) until credentials are entered.

**Once credentials are saved successfully, this welcome banner permanently disappears.** Don't make it dismissible — just have it gate on the credentials-not-yet-saved state.

---

### 4.5 Order meta box "Allscale Payment" (P0)

**Where it appears.** Inside the WooCommerce edit-order screen, in the right-hand sidebar (the column with "Order actions", "Order notes", etc.). Only appears for orders that used the Allscale payment method.

**Visual style.** Standard `.postbox` — a card with a title bar, collapse toggle, and a body.

**Layout.**
```
┌─ Allscale Payment ─────────────────────[▾]─┐
│                                              │
│  Status        ● Confirmed                  │
│  Paid          5.00 USDT                    │
│  Fee           0.025 USDT                   │
│  Net           4.975 USDT                   │
│                                              │
│  ──────────────────────────────────────     │
│                                              │
│  Tx hash       0xabcd…1234  ⧉ View on chain │
│  Chain         Ethereum (1)                 │
│  Method        Wallet scan                  │
│  Intent ID     65b2…d4e5     ⧉ Copy        │
│                                              │
└──────────────────────────────────────────────┘
```

**Field details:**
- **Status**: status pill (same component as on the settings page) using the Allscale status enum mapped to friendly labels.
- **Paid / Fee / Net**: three lines showing the breakdown from `actual_paid_amount` / `service_fee_amount` / `net_income_amount`. Bold the "Paid" amount.
- **Tx hash**: truncated middle (`0xabcd…1234`), with a small "↗" link icon that opens the appropriate block explorer (Etherscan, Polygonscan, BscScan etc., based on `chain_id`).
- **Chain**: chain name with chain ID in parens.
- **Method**: payment method type human-readable (Wallet Scan / WalletConnect / Allscale Pay).
- **Intent ID**: truncated with a copy button.

**Empty states.**
- If only the intent ID exists but no on-chain data yet (status `CREATED` / `PAYING` / `TEMP_WALLET_RECEIVED`): show the Status pill + Intent ID + a single-line description like *"Awaiting payment from customer."* — hide the chain/tx fields.
- If status is `FAILED` / `REJECTED` / `CANCELED` / `TIMEOUT`: show status + a one-line reason from the order note. Hide chain/tx fields.

**Mobile.** The meta box already collapses to single-column on mobile via WC's responsive styling. No special handling needed.

---

### 4.6 Admin notices (P0)

We will surface several notice types. Designer needs to specify their visual treatment.

| Notice | Trigger | Style | Persistence |
|---|---|---|---|
| **Activated / get started** | Just activated, credentials empty | Info, dismissible | Hides on dismiss OR on first valid save |
| **First webhook received** | First webhook ever | Success, dismissible | One-time |
| **WooCommerce not active** | Gateway loaded without WC | Error, NOT dismissible | While WC inactive |
| **Currency unsupported** | Store currency not in Allscale list, gateway enabled | Warning, dismissible | While currency unsupported |
| **Credentials missing** | Gateway enabled, no key/secret | Warning, dismissible | While missing |
| **Migration from 0.1.x (sandbox)** | Upgrading from old version that had sandbox toggle | Info, dismissible | One-time per migration |
| **Test connection failed on save** | Save attempted but ping failed | Error, dismissible | Until next successful save |

**Visual style.** Use WP's `.notice` color treatments. Each notice has:
- Left edge color stripe (4px wide): info blue / success green / warning yellow / error red.
- 24×24 icon on the left: ℹ︎ / ✓ / ⚠ / ✕ respectively. For the "first webhook" success, use a sparkly variant — this is the one celebratory moment, treat it like it matters.
- One sentence of body copy, plus an optional CTA button on the right.
- "×" dismiss button upper right (only for dismissible).

**Mobile.** On <782px, CTA button stacks below body copy.

---

### 4.7 Front-end checkout payment method (P0)

**Where it appears.** Customer-facing checkout, in the payment method radio-button list.

**Layout (inside the radio item):**
```
( ● ) [icon]  Pay with Crypto (Allscale)
              Pay securely with your crypto wallet. Powered by Allscale.
              [ icons row: USDT logo, Ethereum, Polygon, etc. ]
```

- The merchant-configurable title is the primary line.
- The merchant-configurable description is the secondary line.
- A row of small payment-method icons (USDT logo, supported chains) reinforces trust and tells the customer "this accepts crypto".
- The Allscale logo appears small to the left of the title.

**Selected state.** Standard WC behavior — radio button selected, panel expands if the gateway had `has_fields`. Our gateway has `has_fields = false` so nothing extra opens. The "Place order" button takes them to the hosted Allscale checkout.

**Mobile.** WC handles this. Icon row may wrap.

---

### 4.8 Front-end order-received page (P0)

**Where it appears.** Customer "Thank you" page after they return from Allscale.

**What we add.** A small status block above WC's default order details:
```
┌────────────────────────────────────────────────┐
│  ✓ Payment confirmed                           │
│  We received your payment of 5.00 USDT.       │
│  Tx: 0xabcd…1234 ↗                            │
└────────────────────────────────────────────────┘
```

**States.**
- **Confirmed (status=20)**: green checkmark, message above, tx hash link.
- **Pending (status 1/2/3/10)**: yellow spinner icon, "We're confirming your payment on-chain. This page will update automatically." (auto-refresh via JS every 10s, max 5 minutes.)
- **Failed / underpaid / canceled / timeout** (status -1..-5): red X icon, plain explanation: "Your payment didn't go through. Please contact the store if you've been charged."

**Mobile.** Stacks naturally, full-width.

---

### 4.9 Setup wizard (P1 — stretch goal)

If the designer has bandwidth, this is the highest-leverage stretch goal.

**Concept.** A 4-step wizard that takes over the admin after first activation, replacing the "post-activation notice" path with a guided flow.

**Steps:**
1. **Welcome** — what Allscale is, what you'll need (Allscale account + API key). "Continue" button.
2. **Credentials** — paste API Key + Secret. "Test connection" runs inline. Pass → next.
3. **Webhook** — show the webhook URL, "Copy" button, explicit instructions: "1. Copy the URL above. 2. Open your Allscale dashboard. 3. Paste it into your store's webhook setting. 4. Click 'I've done this'."
4. **Done** — celebratory screen. "Your store is ready to accept crypto payments. Place a test order to verify everything works → [link]". "Finish & go to settings".

**Visual.** Centered, full-width single-column card with a top progress bar (4 steps, current step highlighted). Skippable at any step (link in upper right: "Skip wizard — I'll configure manually"). Cannot be re-opened by default — once dismissed or completed, settings page is canonical home.

**Note.** Even without the wizard, the rest of the design must work. The wizard is **additive**.

---

### 4.10 Block-based checkout payment method (P1)

Functionally identical to 4.7 but rendered via React inside the block checkout. Same visual spec applies. Designer doesn't need to mock this separately unless they want to verify it works inside the block layout.

---

## 5. Atomic components

These appear in multiple surfaces. Specify them once.

### Component: Test connection button

**Visual.** A `.button .button-secondary` style — outlined, not solid.

**States:**
| State | Label | Visual |
|---|---|---|
| Idle | `Test connection` | Default secondary button |
| Loading | `Testing…` | Spinner icon on left, button disabled |
| Success | `Test connection` (label resets) + ✓ pill appears beside it | Default button, green "Connected" pill |
| Failure | `Test connection` (label resets) + ✕ pill appears beside it with specific error text | Default button, red pill |

**Error pill copy** (mapped from skill's error table):
- `20002` → "API secret is incorrect — re-copy it from your Allscale dashboard."
- `20001` / HTTP 401 → "API key isn't recognized."
- `30001` → "Your server's IP isn't on the Allscale allowlist."
- Timeout → "Couldn't reach Allscale — try again in a moment."
- Other → "Test failed. See WooCommerce logs for details."

### Component: Status pill

Reused everywhere we show a state (webhook status, order status, test result).

**Anatomy:** Colored dot (8–10px diameter) + label text. No background fill, no border. Just the dot and the text. Compact.

**Color states:**
- **Green** ● — Healthy / Confirmed / Connected
- **Yellow** ● — Warning / Pending / Stale
- **Red** ● — Failed / Error / Rejected
- **Gray** ● — Neutral / Never received / Not tested

**Examples:**
- `● Connected ✓`
- `● Received 4 minutes ago ✓`
- `● Never received yet`
- `● Test failed — API secret is incorrect`
- `● Confirmed`
- `● Underpaid`

### Component: Code/URL block with copy button

**Visual.** Monospaced font, 13–14px, light-gray (~`#f0f0f1`) rounded background, padding 8–12px. Selectable on click. Copy button to the right (or below on small screens).

**Copy button:** ghost-style icon button with a small clipboard icon and the word "Copy". On click, briefly transforms to "✓ Copied" green for ~1.5 seconds, then reverts.

### Component: Allscale logo / payment method icon

The designer should provide:
- **Square mark** (`icon.png`, 64×64 and 128×128): used in payment method label, plugin row, notices.
- **Horizontal lockup** (logo + wordmark): used in headers / welcome banner.
- Both should work on light backgrounds (admin) and on the merchant's checkout (could be dark or light depending on theme).

### Component: Section card

For the settings page section grouping. Mimic WP's `.postbox`:
- White background, light gray border (1px, ~`#c3c4c7`), no shadow.
- Header bar with title (16px semibold) and optional collapse chevron on the right.
- Body with consistent padding (16–20px).

---

## 6. WordPress / WooCommerce conventions to respect

The designer must work **within** these — not redesign them.

| Convention | Specifics |
|---|---|
| **Color scheme** | Default WP "fresh": primary blue `#2271b1`, hover `#135e96`. We may inherit user-selected admin colors. Don't hardcode against fresh — design tokens. |
| **Button classes** | `.button` (default), `.button-primary` (blue), `.button-secondary` (outline), `.button-large`. Don't invent. |
| **Form fields** | Standard WP input: 1px border `#8c8f94`, white bg, 8px padding, 14px font. Focus state: blue ring. |
| **Notice colors** | Info blue `#72aee6`, success green `#00a32a`, warning yellow `#dba617`, error red `#d63638`. Left stripe 4px. |
| **Icons** | Use **Dashicons** (built-in WP icon font) where possible: `dashicons-yes` (✓), `dashicons-warning` (⚠), `dashicons-clipboard`, `dashicons-external`. Supplement only when Dashicons can't express the concept. |
| **Typography** | System font stack: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif`. Don't introduce custom webfonts. |
| **Spacing** | WP uses ~16px / 20px rhythm. Match it. |
| **Form-table pattern** | When showing labeled inputs in groups, the WP standard is a 2-column table (label left, input right). We deviate (use sections) but inside sections, label/input pairing remains. |
| **Capitalization** | Sentence case for everything ("Save changes", "Test connection") — not title case. |
| **Tone** | Direct, second-person ("Enter your API key"), no marketing fluff inside the admin. |

---

## 7. Mobile responsive requirements

**The WP admin is fully responsive.** Anything we build must continue to work below 782px (the WP admin breakpoint) AND below ~360px (small phones).

**Specific responsive behaviors:**
- All sections stack full-width.
- Form labels move above inputs (WP default behavior).
- The "Copy" button on the webhook URL block: stays inline if there's room, stacks below on very narrow screens.
- The "Test connection" button stacks above its status pill on narrow screens (or the pill wraps to the next line).
- Status pills and color dots remain at the same size — don't shrink them, just wrap text.
- The order meta box stacks single-column (WC handles this).
- Setup wizard: full-screen on mobile, no side margins, but content centered with ~16px gutters.
- Front-end checkout payment row: icons may wrap. Keep tap targets ≥44px tall.

**Test screens:**
- 1280px (desktop)
- 768px (tablet portrait)
- 375px (iPhone SE)
- 360px (small Android)

---

## 8. Visual system requirements

If introducing brand color tokens, suggest the designer propose 2–3 values that:
1. Pair naturally with WP's blue admin palette.
2. Are distinct enough to read as "Allscale" — likely a teal, green, or violet accent.
3. Survive on both light and dark mode backgrounds (WordPress 6.x has limited dark mode but we should be safe).

**Suggested accent direction:** A muted teal or sea-green (`~#0f9b8e` or similar) as an Allscale brand accent, used sparingly — only on:
- Allscale logo mark
- The "First webhook received" celebratory state
- Possibly the section card header bars

Most of the UI should stay in WP's default palette so the plugin feels native.

**Iconography:**
- Allscale brand mark (provided): use as-is.
- Chain icons (Ethereum, Polygon, etc.) on the front-end: source from a maintained icon set, single-color flat treatment.
- Dashicons everywhere else.

**Illustration:**
- One illustration could be useful in the empty state of the settings page or the welcome step of the setup wizard. Optional. If included, should be flat, single-or-two-color, abstract (no humans needed). Theme: a stylized wallet receiving a coin.

---

## 9. Deliverables we need from the designer

Please provide:

**Required (P0 surfaces):**
1. Gateway settings page — desktop AND mobile mockups, in **all key states**:
   - Empty (first visit, credentials not entered)
   - Credentials entered, not yet tested
   - Test passed
   - Test failed (with error pill)
   - Saved + healthy webhook
   - Saved + stale webhook (≥7 days)
   - Currency unsupported warning
2. Order meta box — desktop mockup, in 3 states (pending, confirmed, failed).
3. Admin notices — one mockup showing all 7 notice types stacked, so we can see the visual hierarchy together.
4. Front-end checkout payment method — desktop + mobile.
5. Front-end order-received page status block — confirmed / pending / failed states.
6. All atomic components on a single "component library" page: test-connection button (4 states), status pill (4 colors with example text), code/URL block, section card.
7. Logo / icon set: 64×64 + 128×128 mark, horizontal lockup.

**Stretch (P1 surfaces):**
8. Setup wizard — all 4 steps, desktop + mobile.
9. Optional illustration for empty state.

**Format:**
- Figma file (preferred) OR static PNGs at 1x and 2x.
- Annotate exact hex colors, font sizes, paddings, border radii.
- For interactive states (hover, focus, click), annotate the change.

---

## 10. Out of scope for v1 design

Don't design these. We'll handle them in v1.1 or later:
- A standalone admin dashboard page (top-level menu item) — we deliberately stay inside WC's settings tab to respect WP conventions.
- Transaction history list — Allscale API doesn't expose this data.
- Settlement / wallet balance views — API doesn't expose.
- Refund UI — manual process, non-custodial.
- Multilingual (RTL) layout — i18n strings will exist, but RTL design polish is v2.
- Dark mode — WP admin doesn't have first-class dark mode yet.
- White-label theming — the plugin is branded "Allscale", that's fixed.

---

## 11. Open questions for the designer to answer

1. Should the "first webhook received" notice be sticky (stays for a session) or fully dismissible immediately?
2. Should the webhook URL block include a small QR code option for merchants who want to copy from desktop to mobile? (Probably overkill — but flag if you think it adds value.)
3. On the order meta box, when there are 8+ data points, do we want a "Show technical details" collapse to reduce visual weight? Or keep all visible?
4. For the setup wizard's step 3 (webhook), do we want to add a small illustrated diagram showing "WP plugin → Allscale dashboard" to explain why the merchant has to do this manually? (We can't auto-register the webhook URL — see Section 1's hard constraints.)

---

## Appendix A: Exact copy strings

(Designer can place these verbatim in mockups so we don't iterate twice.)

### Settings page
- Page section headers: **Status & visibility**, **API credentials**, **Webhook setup**, **Payment configuration**, **Advanced**
- Enable checkbox label: `Enable Allscale Checkout`
- Title field label: `Title shown to customers`
- Description field label: `Description shown to customers`
- API Key label: `API key`
- API Secret label: `API secret`
- Help text under API secret: `Don't have credentials yet? Sign up at allscale.io →`
- Test button: `Test connection`
- Webhook URL label: `Webhook URL`
- Webhook URL help text: `Paste this URL into your Allscale store's webhook setting. We can't configure it for you — Allscale requires you to do it from your dashboard.`
- Webhook status label: `Webhook status`
- USDT toggle: `Use native USDT pricing instead of fiat conversion`
- USDT toggle help: `Advanced — for stores that want to display USDT amounts directly to customers. Most stores leave this off.`
- Debug toggle: `Enable debug logging`
- Debug help: `Writes detailed activity to WooCommerce logs at WooCommerce → Status → Logs.`

### Empty state welcome banner
- Title: `Welcome to Allscale Checkout`
- Subtitle: `Three steps to start accepting crypto payments:`
- Step 1: `Enter your API credentials below`
- Step 2: `Test the connection`
- Step 3: `Paste your webhook URL into your Allscale dashboard`

### Notices
- Activated: `Allscale Checkout is activated. Add your API credentials to start accepting payments. → Set up Allscale Checkout`
- First webhook: `Your store just received its first Allscale webhook. Payments will now confirm automatically.`
- WC not active: `Allscale Checkout requires WooCommerce. Please install and activate WooCommerce.`
- Currency unsupported: `Your store currency {CODE} isn't supported by Allscale. Supported currencies: USD, EUR, GBP, CAD, AUD, JPY, CNY, SGD, HKD.`
- Credentials missing: `Allscale Checkout is enabled but missing API credentials. → Add credentials`
- Migration from 0.1.x: `You've upgraded Allscale Checkout. Sandbox mode has been retired — to test without real payments, create a test store in your Allscale dashboard and use its credentials here. → Learn more`
- Save failed: `Could not save your Allscale credentials: {SPECIFIC ERROR}. They have not been stored.`

### Order meta box
- Box title: `Allscale Payment`
- Field labels: `Status`, `Paid`, `Fee`, `Net`, `Tx hash`, `Chain`, `Method`, `Intent ID`
- Status labels: `Confirmed`, `On-chain (awaiting confirmation)`, `Pending`, `Underpaid`, `Failed`, `Rejected`, `Canceled`, `Timed out`, `Manual review`, `Refund in progress`

### Front-end
- Checkout method title (default, editable): `Pay with Crypto (Allscale)`
- Checkout method description (default, editable): `Pay securely with your crypto wallet. Powered by Allscale.`
- Order-received confirmed: `Payment confirmed`
- Order-received pending: `We're confirming your payment on-chain. This page will update automatically.`
- Order-received failed: `Your payment didn't go through. Please contact the store if you've been charged.`

---

*End of brief. Designer: please ask clarifying questions before mocking. Most questions are answered above — if it's not in here, ask.*
