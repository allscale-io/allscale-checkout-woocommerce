# Allscale Checkout for WordPress — v0.0.x Rebuild Architecture

This document describes the architecture for the v0.0.x pre-release rebuild of the plugin (deliberately labeled v0 — not yet production-stable). It captures (a) what changed in the underlying Allscale Checkout API since the 0.1.x plugin was written, (b) how the rebuild is structured, (c) how each known issue in 0.1.x is resolved, and (d) what is in and out of scope.

For the UI specification that pairs with this architecture, see [`design-brief.md`](./design-brief.md).

---

## Quick orientation

If you're reading this cold (AI agent or new contributor), here's where to start based on what you want to do:

- **Understand the codebase end-to-end** → read §4 (module-by-module design) and §5 (file layout). Each class is single-purpose and described in its own subsection.
- **Trace a real request** — payment intent creation → §4.10 (Gateway), webhook delivery → §4.9 (Webhook_Handler), customer return-URL → §4.10 again. Both write paths converge on §4.7 (Status_Mapper) under §4.8 (Order_Locker).
- **Extend the plugin** — see the README's "Extending the plugin" section for the public filter / action hooks and the canonical order meta keys.
- **Understand a design choice** — §1 (API spec deltas vs 0.1.x), §2 (what stayed the same), §3 (known-issue resolutions), §7 (scope decisions).
- **Adding a new Allscale status, chain, currency, or status-to-WC mapping** — `Status_Codes`, `Admin::chain_name` / `Admin::explorer_url`, `Currency::$map`, `Status_Mapper::apply` respectively.
- **Working on the UI** — see [`design-brief.md`](./design-brief.md) for the visual spec and copy strings; CSS lives in `assets/css/admin.css` scoped under `.allscale-admin` / `.allscale-metabox` / `.allscale-wizard`.

---

## 1. What changed in the Allscale API (vs the 0.1.x plugin's assumptions)

The plugin was originally written against an older spec. The current canonical spec is the [allscale-checkout-skill](https://github.com/allscale-io/allscale-checkout-skill) markdown, cross-verified against `docs.allscale.io/llms-full.txt` (the full single-file documentation dump). The deltas that affect the plugin:

| Topic | What 0.1.x assumed | Current API |
|---|---|---|
| **Sandbox environment** | Two base URLs: sandbox/production toggle in settings | **Sandbox retired.** Single base URL `https://openapi.allscale.io`. Testing happens via "test store" credentials issued from the Allscale dashboard. |
| **`redirect_url`** | Passed inside `extra.return_url` | Top-level field `redirect_url` on the intent body |
| **`stable_coin`** | Not supported | Top-level field for native USDT pricing (alternative to `currency`). Currently only USDT (`1`) is enabled |
| **`user_id`, `user_name`** | Not sent | Top-level fields on the intent body |
| **Status enum** | 6 values: 20/10/-1/-2/-3/-4 | **12 values.** Adds TIMEOUT (-5), CREATED (1), PAYING (2), TEMP_WALLET_RECEIVED (3), PENDING_MANUAL_OPERATION (4), SEND_BACK (5) |
| **Status endpoint shape** | Assumed `{status, tx_hash, amount_cents}` object | `GET /v1/checkout_intents/{id}/status` returns a **bare integer** payload. Full details require `GET /v1/checkout_intents/{id}` |
| **Replay window** | ±5 minutes for both API and webhook | Webhook ±5 minutes (unchanged), API request ±10 minutes default (may be tightened per-store via `replay_window_seconds`) |
| **Error codes** | Plugin parsed HTTP status only | Full enum: 10001, 20001, 20002, 30001, 40001, 50001, 50002, 90000, 99999 — must map to user-facing copy |
| **`/v1/test/ping`** | Not used | Used to validate credentials at save time and via a "Test connection" button |
| **Webhook `webhook_id`** | Not validated | Webhook payload includes a `webhook_id` field that must equal the `X-Webhook-Id` header |
| **Webhook `payment_method_type`** | Not stored | New field on the webhook payload — should be persisted to order meta |
| **`accepted_stable_coins`** | Not supported | Optional intent field (list of stable-coin enums the merchant accepts). the current code sends a single value implicitly; forward-compat only |
| **Response signing** | Not supported | Optional per-store feature. this release does not implement it; the interface is stubbed for future |
| **Minimum payment** | Not enforced client-side | 0.1 USDT minimum — pre-check before creating intent |

**Hidden bug surfaced by this analysis.** The 0.1.x `check_payment_on_return()` calls `GET /v1/checkout_intents/{id}/status` and expects an object with `status`, `tx_hash`, `amount_cents`. The endpoint actually returns a bare integer. The return-URL fallback has therefore never worked correctly in 0.1.x.

---

## 2. What stayed the same

- HMAC-SHA256 request signing algorithm and canonical-string format (METHOD / PATH / QUERY / TIMESTAMP / NONCE / BODY_SHA256, hex body hash, base64 signature).
- Webhook canonical-string format including the literal prefix line `allscale:webhook:v1`.
- Currency enum values for the 9 supported fiats (USD=1, AUD=9, CAD=27, CNY=31, EUR=44, GBP=48, HKD=57, JPY=72, SGD=126).
- HPOS compatibility and block-based checkout compatibility declarations.
- The webhook URL pattern: `https://yoursite.com/wc-api/allscale_checkout`.
- Webhook URLs cannot be registered programmatically — the merchant pastes them into the Allscale dashboard manually. The design accepts this constraint (see [`design-brief.md`](./design-brief.md) §1).

---

## 3. Known issues in 0.1.x and how the rewrite resolves each

Catalogued during the initial 0.1.x review (2026-05-20).

| # | Issue (0.1.x) | Resolution |
|---|---|---|
| 1 | **Webhook handler registered only inside `Allscale_Gateway::__construct()`** — registration depends on the gateway being instantiated, which happens via the `woocommerce_payment_gateways` filter. Order-of-operations fragility. | `Plugin::boot()` registers the webhook handler unconditionally on the `init` hook. The handler reads `api_secret` from options at request time (not constructor), supporting credential rotation. |
| 2 | **`substr($description, 0, 197)` byte-truncates UTF-8** — Chinese / Japanese / emoji product names get sliced mid-character and produce malformed UTF-8 in the API payload. | `mb_strimwidth($description, 0, 200, '…', 'UTF-8')`. |
| 3 | **Race condition between webhook and return-URL fallback** — both call `payment_complete()` and `add_order_note()`. Concurrent arrival causes duplicate notes. | `Order_Locker::with_lock($order_id, callable)` wraps every order state mutation. Uses `wc_get_order_lock` if available (WC 8.6+); transient-based fallback otherwise. The status mapper also checks `$order->get_status()` before transitioning to short-circuit redundant updates. |
| 4 | **Redundant `pending → pending` update** — new orders are already pending when `process_payment` runs; the explicit `update_status('pending', ...)` is a no-op but adds an unneeded order note. | Removed. |
| 5 | **No logging anywhere.** | A single `Logger` wrapper around `wc_get_logger()` with source `'allscale-checkout'` is threaded through the API client (logs request_id, status code, latency), webhook handler (logs validation failures with redacted headers), and status mapper (logs transitions). A "Debug logging" toggle in settings increases verbosity. |
| 6 | **No i18n** — all strings hardcoded English, no `Text Domain` header. | Text domain `allscale-checkout` declared in the plugin header, set with `load_plugin_textdomain` on `plugins_loaded`. All user-facing strings wrapped in `__()` / `esc_html__()` / `_e()`. A `.pot` template is generated under `languages/`. |
| 7 | **Currency limited to 9 fiats; no admin signal when unsupported.** | The skill still defines only 9 supported fiats, so we keep parity. Added: an admin notice (and an inline notice on the settings page) when the store currency is unsupported, and an optional "native USDT pricing" toggle for crypto-first stores using `stable_coin`. |
| 8 | **Block checkout integration is minimal** (renders label + description only). | Carried forward as-is. Out of scope; revisit in a future release. |
| 9 | **README ZIP download link uses GitHub-relative path** (breaks outside github.com). | Replaced with an absolute URL. |
| 10 | **`wc_get_orders` meta lookup** is slow at scale on legacy CPT storage. | HPOS is the documented requirement; lookup remains as-is, documented in code. |

---

## 4. Module-by-module design

### 4.1 Plugin bootstrap (`includes/class-plugin.php`)

A singleton entry point. The main file `allscale-checkout.php` defines the plugin headers and constants, then calls `Allscale\Checkout\Plugin::instance()->boot()`.

`boot()` is the one place that wires the plugin to WordPress:

1. Load text domain on `init` priority 1.
2. Declare HPOS compatibility and `cart_checkout_blocks` compatibility on `before_woocommerce_init`.
3. Check `class_exists('\WC_Payment_Gateway')`. If WC isn't loaded, register the branded "needs WooCommerce" admin notice and bail — the rest of the plugin never wires up.
4. **Lazy-require `class-gateway.php`** now that the parent WC class is confirmed present. (Requiring it eagerly at the top of the main file was a fatal-error trap when the plugin loaded before WC alphabetically.)
5. Instantiate and register `Webhook_Handler` on the `woocommerce_api_allscale_checkout` action **unconditionally** — does not depend on the gateway constructor running. This is the fix for known-issue 1.
6. Register the gateway via the `woocommerce_payment_gateways` filter.
7. Register `Blocks_Integration` lazily on `woocommerce_blocks_loaded` (same parent-class-availability pattern as the gateway).
8. Register `Settings_Validator` (hooks into WC's save-fields filter to ping-validate credentials before persistence).
9. If `is_admin()`, register `Admin` (settings page, AJAX, notices, meta box) and `Setup_Wizard` (first-run wizard + activation redirect).
10. Run `Migrations::maybe_run()` — version-agnostic, keyed on the presence of the legacy `environment` setting.

### 4.2 API client (`includes/class-api-client.php`)

Pure HTTP layer. No WordPress UI assumptions.

**Constructor:** `new Api_Client(string $api_key, string $api_secret, Logger $logger)`.

**Public methods, each returning an `Api_Result` value object:**

| Method | Endpoint | Returns |
|---|---|---|
| `test_ping()` | `GET /v1/test/ping` | Bool-equivalent success/failure for credential validation |
| `create_intent(array $payload)` | `POST /v1/checkout_intents/` | Full intent payload including `checkout_url`, `allscale_checkout_intent_id` |
| `get_intent_details(string $intent_id)` | `GET /v1/checkout_intents/{id}` | Full intent object including `tx_hash`, `amount_cents`, `actual_paid_amount`, etc. — used by the return-URL fallback |

(`GET /v1/checkout_intents/{id}/status` returns a bare integer payload and was the wrapper Allscale's spec recommends for cheap polling, but the return-URL fallback needs full details anyway so we don't expose a separate `get_intent_status` method — add one if a future polling path needs it.)

Internally, `request($method, $path, $body)` delegates to `Signer::sign_request()` for header construction and uses `wp_remote_request()` for transport. On every response, `Logger::debug()` is called with `request_id`, response status, and latency. Errors are logged at `Logger::warning()` or `Logger::error()` depending on category.

### 4.3 Signer (`includes/class-signer.php`)

Pure cryptography. No HTTP, no logging. Easy to unit-test.

**Static methods:**
- `sign_request(string $method, string $path, string $query, string $body, string $secret): array` — returns the four signing headers (`X-Timestamp`, `X-Nonce`, `X-Signature`, plus a sentinel for `X-API-Key` which the caller adds).
- `verify_webhook(string $method, string $path, string $query, string $webhook_id, string $timestamp, string $nonce, string $body, string $signature, string $secret): bool` — timing-safe.

The canonical-string construction is identical to what 0.1.x had; we just extract it from the API client class to make it testable.

### 4.4 API result (`includes/class-api-result.php`)

A small value object so callers never have to dig through an array shape:

```php
final class Api_Result {
    public bool $success;
    public mixed $data;          // payload on success
    public ?int $error_code;     // Allscale code on failure
    public ?string $error_message;
    public ?string $request_id;
    public ?int $http_status;
}
```

### 4.5 Currency (`includes/class-currency.php`)

The ISO ↔ Allscale-enum mapping, plus the `stable_coin` enum. Pure data class with static lookups: `to_enum(string $iso): ?int`, `is_supported(string $iso): bool`, `supported_codes(): array`.

### 4.6 Status codes (`includes/class-status-codes.php`)

The 12 Allscale status integer constants and helpers:

```php
const CONFIRMED                = 20;
const ON_CHAIN                 = 10;
const SEND_BACK                = 5;
const PENDING_MANUAL_OPERATION = 4;
const TEMP_WALLET_RECEIVED     = 3;
const PAYING                   = 2;
const CREATED                  = 1;
const FAILED                   = -1;
const REJECTED                 = -2;
const UNDERPAID                = -3;
const CANCELED                 = -4;
const TIMEOUT                  = -5;

public static function is_terminal(int $status): bool;
public static function is_failure(int $status): bool;
public static function is_success(int $status): bool;
public static function label(int $status): string; // i18n label
```

### 4.7 Status mapper (`includes/class-status-mapper.php`)

The decision table that maps an Allscale status to a WooCommerce order transition. Centralized so the webhook handler and the return-URL fallback share identical behavior.

**Signature:** `Status_Mapper::apply(WC_Order $order, int $allscale_status, array $context): void`.

| Allscale status | WC action |
|---|---|
| `1 CREATED` | Add note, no status change |
| `2 PAYING` | Add note "Customer is on the checkout page", no status change |
| `3 TEMP_WALLET_RECEIVED` | Add note "Deposit wallet assigned", no status change |
| `4 PENDING_MANUAL_OPERATION` | `on-hold` + note "Pending manual review by Allscale" |
| `5 SEND_BACK` | Add note "Refund in progress on Allscale side", no status change |
| `10 ON_CHAIN` | Add note "Transaction detected on-chain, awaiting confirmation" |
| `20 CONFIRMED` | Validate `amount_cents` ≥ expected. If yes → `payment_complete($tx_hash)`. If short → `on-hold` + amount-mismatch note |
| `-1 FAILED` | `failed` |
| `-2 REJECTED` | `failed` + note "Rejected by KYT check" |
| `-3 UNDERPAID` | `on-hold` + note with received amount |
| `-4 CANCELED` | `cancelled` |
| `-5 TIMEOUT` | `cancelled` + note "Payment intent timed out" |

Before every transition, the mapper short-circuits if the order is already in the target state — this is the second half of the race-condition fix from issue 3.

`$context` carries `tx_hash`, `paid_cents`, `payment_method_type`, `chain_id`, `actual_paid_amount`, etc. — all derived from the webhook payload or details endpoint. The mapper persists relevant fields to order meta before transitioning.

### 4.8 Order locker (`includes/class-order-locker.php`)

`Order_Locker::with_lock(int $order_id, callable $fn): mixed` acquires a mutex around order state updates. Implementation:

1. Hash the order resource name into a private `wp_options` key.
2. Acquire it with `INSERT IGNORE`; the unique `option_name` index is the cross-worker compare-and-set.
3. Recover abandoned locks with an owner-checked conditional `UPDATE`, and release with an owner-checked `DELETE` so an expired owner cannot delete its successor's lock.
4. Embed expiry in the owner value and remove expired rows on the next lock-using request, independently of the site's object-cache backend.
5. Wait up to three seconds. If acquisition fails, do not invoke the callback; webhook callers return `503` so Allscale can retry.

The same 120-second order lock guards intent creation, webhook handling, and the return-URL fallback. Combined with durable processed-webhook IDs and the mapper's status short-circuit, it prevents duplicate intents, duplicate notes, and concurrent payment completion.

### 4.9 Webhook handler (`includes/class-webhook-handler.php`)

Registered unconditionally from `Plugin::boot()` on the `init` hook. Listens on `woocommerce_api_allscale_checkout`.

Sequence:
1. Read raw body via `file_get_contents('php://input')`.
2. Read `api_secret` from current options (not from a stored constructor value — supports credential rotation).
3. If secret is empty, return 503 with a logged warning.
4. Read required headers: `X-Webhook-Id`, `X-Webhook-Timestamp`, `X-Webhook-Nonce`, `X-Webhook-Signature`.
5. Validate timestamp window (±5 minutes per spec).
6. Verify signature via `Signer::verify_webhook` before claiming any untrusted identifier.
7. Parse body; verify `payload.webhook_id === X-Webhook-Id` header.
8. Find the order by the current, superseded, or legacy intent-id meta. A missing order returns `503` so a webhook racing intent persistence is retried.
9. Acquire the atomic order lock. Under that lock, reject a durable `_allscale_processed_webhook_id` duplicate and atomically claim the nonce for 10 minutes.
10. `Status_Mapper::apply(...)`, persist the processed webhook ID, then run the post-process action.
11. If processing or lock storage fails, release the nonce claim where possible and return `503`; only completed or proven-duplicate events receive `200`.
12. Update the `allscale_last_webhook_at` option to `time()`.
13. If this is the first webhook ever received (option was previously empty), set `allscale_first_webhook_at` to trigger the celebratory admin notice.
14. Return 200 OK.

Every failure path is logged with the request's `webhook_id` (not the secret).

### 4.10 Gateway (`includes/class-gateway.php`)

`Allscale\Checkout\Gateway` extends `WC_Payment_Gateway`.

Settings fields (matching the design brief):
- `enabled`
- `title`
- `description`
- `api_key`
- `api_secret`
- `use_stable_coin_pricing` (bool, default false)
- `debug_logging` (bool, default false)

`process_payment($order_id)`:
1. Sanity-check the order exists.
2. Compute amount in cents. Validate >= 10 cents (0.1 USDT minimum, in stable-coin-cents terms — see code comment).
3. Build description via `mb_strimwidth(..., 200, '…')` (fixes issue 2).
4. Build `Intent_Request` with top-level `redirect_url = $this->get_return_url($order)` (no more `extra.return_url`).
5. Either send `currency` enum + cents, or `stable_coin: 1` + cents if `use_stable_coin_pricing` is enabled.
6. Send `user_id` and `user_name` from the WC order's billing fields when present.
7. Acquire the atomic order lock before inspecting or creating an intent.
8. If an active intent already exists, verify its amount and reuse its saved/API-provided `checkout_url`. Confirmed intents redirect to the return URL for reconciliation; underpaid/refund states require merchant review.
9. Only terminal no-payment states (failed, rejected, canceled, or timed out) may be superseded. Archive their IDs before storing the replacement.
10. Call `Api_Client::create_intent()` only when no reusable intent exists.
11. On success, persist `_allscale_intent_id`, `_allscale_checkout_url`, and `_allscale_intent_amount_cents`, then redirect. On failure, surface a friendly error.

`handle_thankyou_page($order_id)` (the return-URL fallback):
1. If order already paid, return.
2. Read `_allscale_intent_id` from meta. If missing, return.
3. Call `Api_Client::get_intent_details($intent_id)` (NOT the status endpoint — fixes the hidden 0.1.x bug).
4. `Order_Locker::with_lock(...)` → `Status_Mapper::apply(...)` with the full context.

### 4.11 Settings validator (`includes/class-settings-validator.php`)

Hooked into `woocommerce_settings_api_sanitized_fields_allscale_checkout`. Before any save:

1. If credentials are unchanged from stored values, allow the save (don't burn a `ping` call on every save).
2. If credentials are new or changed, instantiate a temporary `Api_Client` with the proposed values and call `test_ping()`.
3. If ping fails, **reject the save** — the credentials are not written, the old values persist, and an admin notice surfaces with the specific error per the skill's error-code → user-copy table.
4. If ping passes, allow the save and emit a success notice.

This mirrors the skill's Step 4.5 multi-tenant validation pattern, applied to the single-tenant WP admin context.

### 4.12 Admin (`includes/class-admin.php`)

Only loaded if `is_admin()`. Responsibilities:

- The "Test connection" button: registers an authenticated admin-ajax endpoint (`wp_ajax_allscale_test_connection`) that takes the API key/secret from the request, runs `test_ping()`, and returns a JSON response the frontend JS turns into the status pill.
- Admin notices controller (`Admin::render_notices`): renders 4 notice types as floating admin notices:
  1. Settings save result (success/error) — surfaced from `Settings_Validator` via transient.
  2. "First webhook received" — celebratory one-time notice when `OPT_FIRST_WEBHOOK_AT` is first set.
  3. Sandbox-retired migration notice — one-time after upgrading from a 0.1.x install that had `environment=sandbox`.
  4. Credentials-missing notice — shown on Plugins / Dashboard screens when the gateway is enabled but credentials are empty.

  Three other notice types from the design brief live elsewhere because they belong with the surface they describe:
  - **WC required** → `Plugin::render_wc_required_notice` (brand-styled gradient card, fires before any WC-dependent code loads).
  - **Currency unsupported** → inline notice rendered at the top of the settings page in `Admin::render_settings_page`.
  - **Activation / get-started** → handled by the `Setup_Wizard` activation redirect and the welcome banner on the empty settings state, not as a floating notice.
- Enqueues `assets/js/admin.js` and `assets/css/admin.css` on the WC settings → Allscale tab and the order detail screens (CSS-only for orders).
- Renders the order detail meta box (`Allscale Payment`) via `add_meta_boxes`.

### 4.13 Error messages (`includes/class-error-messages.php`)

A small map from Allscale error codes to localized user-friendly strings. Used by the settings validator, the gateway's `process_payment` notice, and the admin notices controller.

### 4.14 Blocks integration (`includes/class-blocks-integration.php`)

Carried forward from 0.1.x essentially unchanged — extends `AbstractPaymentMethodType`, registers a minimal React component that renders the label/description for the block-based checkout. A future release may deepen this; for now we just preserve parity.

### 4.15 Migrations (`includes/class-migrations.php`)

`maybe_run()` checks the stored plugin version against `ALLSCALE_CHECKOUT_VERSION` and runs idempotent migrations:

- **From the legacy 0.1.x community beta**: detected by the presence of the now-removed `environment` setting in stored gateway options (we don't use `version_compare` — that's fragile across re-versioning). If `environment` was `sandbox`, queue the sandbox-retired admin notice (one-time). Strip the `environment` key. The legacy `_allscale_checkout_intent_id` order meta is kept readable via a dual-read fallback in `Webhook_Handler::find_order_by_intent` and `Gateway::handle_thankyou` so in-flight orders aren't broken.

After every run, the stored version option is updated to `ALLSCALE_CHECKOUT_VERSION`. The migration check itself is idempotent (post-first-run, the `environment` signal is gone), so it's safe to invoke on every upgrade.

### 4.16 Setup Wizard (`includes/class-setup-wizard.php`)

A hidden admin page (`admin.php?page=allscale-checkout-setup`) that walks merchants through their first configuration in four steps:

1. **Welcome** — non-custodial trust message, prereq checklist, "Continue".
2. **Credentials** — paste API key + secret, optional Test Connection, "Continue" runs a server-side ping and only advances on success (the same `/v1/test/ping` flow Settings_Validator uses).
3. **Webhook** — display the webhook URL with copy button + explicit 4-step manual instructions for pasting it into the Allscale dashboard.
4. **Done** — celebratory screen with "Place test order" + "Finish & go to settings" CTAs.

The wizard is triggered by an activation hook (`Setup_Wizard::on_activation` sets a 30-second transient; `maybe_redirect_after_activation` reads it on the next admin page load and `wp_safe_redirect`s to step 1). Skipping or completing the wizard sets a persistent option so the redirect never fires again. The wizard URL also stays accessible via a "Run guided setup" link in the welcome banner and a bold "Setup wizard" link on the Plugins page until credentials are configured.

The wizard reuses `Admin`'s `/v1/test/ping` AJAX endpoint and its shared `admin.js` / `admin.css`. Inputs in the wizard form are mirrored into hidden WC-named fields so the shared JS finds them without modification.

---

## 5. File layout

```
allscale-wordpress-plugin/
├── allscale-checkout.php                  # Main plugin file: headers, constants, Plugin::boot()
├── readme.txt                             # WordPress.org standard format (future submission)
├── README.md                              # GitHub-facing docs
├── LICENSE                                # GPLv2
├── uninstall.php                          # Cleanup of options/transients on plugin delete
├── languages/
│   └── allscale-checkout.pot              # i18n template
├── docs/
│   ├── architecture.md                    # This file
│   └── design-brief.md                    # UI specification
├── includes/
│   ├── class-plugin.php
│   ├── class-api-client.php
│   ├── class-api-result.php
│   ├── class-signer.php
│   ├── class-currency.php
│   ├── class-status-codes.php
│   ├── class-status-mapper.php
│   ├── class-order-locker.php
│   ├── class-logger.php
│   ├── class-error-messages.php
│   ├── class-gateway.php
│   ├── class-webhook-handler.php
│   ├── class-settings-validator.php
│   ├── class-admin.php
│   ├── class-blocks-integration.php
│   └── class-migrations.php
├── assets/
│   ├── icon.png                           # Allscale brand mark
│   ├── logo.svg                           # Wordmark with green corner brackets
│   ├── usdt.png                           # USDT badge for checkout
│   ├── chains/                            # Per-chain mark images
│   │   ├── eth.png  polygon.png  base.png
│   │   └── bnb.png  arbitrum.png  optimism.png
│   ├── js/
│   │   ├── admin.js                       # Settings page interactivity
│   │   └── blocks.js                      # Block-based checkout integration
│   └── css/
│       └── admin.css                      # Scoped admin + wizard styles
```

---

## 6. Naming conventions

| Item | Convention |
|---|---|
| **PHP namespace** | `Allscale\Checkout\` (PHP 7.4+ supports namespaces natively; the plugin's `Requires PHP` is 7.4) |
| **Constants** | `ALLSCALE_CHECKOUT_*` (e.g., `ALLSCALE_CHECKOUT_VERSION`, `ALLSCALE_CHECKOUT_PATH`, `ALLSCALE_CHECKOUT_BASE_URL`) |
| **Plugin option key** | `woocommerce_allscale_checkout_settings` (WC convention; preserved from 0.1.x for in-place upgrade) |
| **Order meta keys** | `_allscale_intent_id`, `_allscale_tx_hash`, `_allscale_status`, `_allscale_payment_method_type`, `_allscale_chain_id`, `_allscale_actual_paid_amount`, `_allscale_service_fee_amount`, `_allscale_net_income_amount` |
| **Standalone options** | `allscale_checkout_version`, `allscale_checkout_last_webhook_at`, `allscale_checkout_first_webhook_at`, `allscale_checkout_last_ping_status` |
| **Filters / actions** | `allscale_checkout_*` (e.g., `allscale_checkout_intent_request_payload`, `allscale_checkout_webhook_after_process`) |
| **Text domain** | `allscale-checkout` |
| **REST / webhook slug** | `allscale_checkout` (preserves existing URL `/wc-api/allscale_checkout`) |

---

## 7. Scope

### v0.0.x — in scope

- All deltas in §1 (sandbox removal, redirect_url at top level, expanded status enum, intent details vs status endpoint, error code mapping, 0.1 USDT minimum, webhook_id verification).
- All known issue fixes in §3.
- "Test connection" button + AJAX endpoint.
- Pre-save credential validation via `/v1/test/ping`.
- Order detail meta box (`Allscale Payment`).
- Admin notices system (7 notice types from the design brief).
- Logger wrapper with debug toggle.
- Full i18n.
- 0.1.x → 0.0.x migration with sandbox-retired notice.
- Native USDT pricing as an opt-in setting.
- Webhook health observation: `last_webhook_at` and first-webhook celebration notice.
- README and `readme.txt` rewrite.

### v0.0.x — explicitly out of scope (deferred)

- **Setup wizard** (P1 in the design brief — additive, can land in 1.1).
- **Block-based checkout client-side enhancements** (label/description only currently; deeper React surface is a future improvement).
- **Response signing verification** (rarely enabled; will add interface stub but no implementation).
- **Transaction history / dashboard widgets** — Allscale API does not expose data sources for these.
- **Refund automation** — non-custodial; impossible to support.
- **WP.org submission** — requires legal review and a separate process.
- **PHPUnit test suite** — recommended for 1.1.
- **RTL / dark mode design polish.**

---

## 8. Migration plan (0.1.x → 0.0.x)

For merchants upgrading in place:

1. **Settings option key** is preserved (`woocommerce_allscale_checkout_settings`). No data loss.
2. **Order meta keys** for in-flight orders: the new code reads both `_allscale_checkout_intent_id` (legacy) and `_allscale_intent_id` (new), preferring the new one. After 0.0.1, new orders only write the new key.
3. **`environment` setting**: if previously `sandbox`, queue the migration notice telling the merchant sandbox is retired and to use test-store credentials. The field is no longer rendered in settings; the stored value is silently ignored on upgrade.
4. **Webhook URL** is unchanged. No action required from the merchant.
5. **Credentials**: existing API key and secret remain valid (no rotation forced). The first save after upgrade re-validates them via the new pre-save ping; if they were already valid before, validation passes silently.

---

## 9. Implementation order

Once design is approved:

1. Scaffold directory structure + main file + Plugin bootstrap.
2. Implement leaf modules in parallel (no inter-dependencies): `Signer`, `Currency`, `Status_Codes`, `Logger`, `Error_Messages`, `Api_Result`.
3. Implement `Api_Client` (depends on Signer + Logger + Api_Result).
4. Implement `Order_Locker`.
5. Implement `Status_Mapper` (depends on Status_Codes + Logger).
6. Implement `Webhook_Handler` (depends on Signer + Status_Mapper + Order_Locker + Logger).
7. Implement `Gateway` (depends on Api_Client + Status_Mapper + Currency + Error_Messages).
8. Implement `Settings_Validator` (depends on Api_Client + Error_Messages).
9. Implement `Admin` (depends on Api_Client + the design system from the brief).
10. Implement `Blocks_Integration` (port from 0.1.x).
11. Implement `Migrations`.
12. i18n extraction → `.pot`.
13. Update README and add `readme.txt`.
14. Lint pass: `php -l` on every file, plus a static check that namespaces and class names match filenames.
