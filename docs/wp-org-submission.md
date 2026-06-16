# WordPress.org Plugin Directory — Submission Checklist

A working document for when we decide to submit the plugin to [WordPress.org's plugin directory](https://wordpress.org/plugins/). Not the submission itself — just everything we need ready first, what to expect during review, and what to do after approval.

---

## Status

**Submitted and approved.** v1.0.0 passed WordPress.org Plugin Check, the hosting request was approved, and the plugin is published at https://wordpress.org/plugins/allscale-checkout. Code is uploaded via SVN (see §4). The open decisions in §1 below were all resolved; they're kept as a record of how each was settled.

---

## 1. Decisions made before submitting (resolved)

Each of these was settled before submission. Recorded here for posterity.

### 1.1 Are we ready for "stable" framing? — Resolved: Path B

We took **Path B**: swept the "v0 / not yet stable / don't run on production" framing out of all merchant-facing copy (README.md, readme.txt, architecture intro) and bumped the version to **1.0.0** before submitting. The plugin cleared Plugin Check on the first pass.

For the record, the two paths considered were:

- **Path A** — leave the v0 framing, submit anyway. Likely outcome: rejected with feedback to remove "beta" / "not ready" language, then resubmit clean.
- **Path B** (chosen) — remove the pre-release framing first, bump to a non-v0 version, then submit. More work up front, more likely to clear first time.

### 1.2 Plugin Name — Resolved: `Allscale Checkout`

Shipped as **`Allscale Checkout`** (dropped the "for WordPress" suffix). Shorter, no "WordPress" in the name, and the directory page itself is on wordpress.org so the platform is obvious.

**Plugin slug** is `allscale-checkout` — reserved at submission and permanent.

### 1.3 Trademark / authorship — Resolved: `allscaleio` account

Submitted from the **`allscaleio`** WordPress.org account (owned by Allscale), not a personal account, so trademark ownership is clear.

### 1.4 Tested up to — Resolved

`readme.txt` now declares `Tested up to: 7.0` (and `WC tested up to: 10.8`), verified before submission.

---

## 2. Pre-submission checklist

Everything in here was completed before the submission form went in. All items below were resolved for the v1.0.0 submission, which passed Plugin Check.

| Item | Status (v1.0.0) | Notes |
|---|---|---|
| `readme.txt` has all WP.org-required sections | ✅ Done | Contributors, Tags, Requires/Tested, Stable tag, License, short description, Description, External services, Privacy, Screenshots, FAQ, Changelog, Upgrade Notice. |
| Plugin header has Plugin Name, URI, Description, Version, Author, License, Text Domain, Requires at least, Requires PHP, WC requires at least | ✅ Done | All present in `allscale-checkout.php`. (The `Domain Path` header was removed — WordPress.org auto-loads translations, so it's unused.) |
| GPLv2 LICENSE file in plugin root | ✅ Done | `LICENSE` in repo root. |
| `uninstall.php` cleans up plugin-owned options | ✅ Done | Removes all `allscale_checkout_*` options + the WC settings option + relevant transients. |
| No "calls home" / no telemetry | ✅ Done | We only ever contact `openapi.allscale.io`, which is documented in the External services readme.txt section. |
| All external service usage disclosed in readme.txt | ✅ Done | |
| Privacy implications disclosed in readme.txt | ✅ Done | |
| No loading of external JS / CSS at runtime | ✅ Done | We load only local assets (`assets/css/admin.css`, `assets/js/admin.js`, `assets/js/blocks.js`, `assets/js/thankyou.js`, `assets/js/wizard.js`). No CDN scripts, no inline scripts. |
| No obfuscated or minified third-party code without source | ✅ Done | Plain readable PHP / JS / CSS throughout. |
| Compatible with the latest WP release | ✅ Done | `Tested up to: 7.0`; `WC tested up to: 10.8`. |
| `.wordpress-org/` icons + banner + screenshots | ✅ Done | Icons, banner pair, and 4 screenshots present; uploaded to SVN `assets/`. |
| Plugin works without any database content beyond fresh activation | ✅ Done | Migrations module is idempotent + tolerant of empty state. |
| Plugin passes WordPress.org Plugin Check | ✅ Done | Cleared on submission (inline scripts moved to enqueued files, output escaped via `wp_kses`, inputs sanitized). |

---

## 3. Submission process

### 3.1 Create the WordPress.org account

Submitted under the `allscaleio` account. Use a long-lived email tied to Allscale infra so future reset / notifications go somewhere durable. Two-factor recommended.

### 3.2 Build the submission ZIP

The ZIP we upload must contain the plugin code at the **top level** (no nested wrapper folder). The current release-build script produces an `allscale-checkout/` wrapper folder — that's the WP plugin directory layout. WP.org's submission form takes that exact format.

Build with the release script:

```bash
cd /home/claude/temp && rm -rf release-build && mkdir release-build && cd release-build
cp -r /path/to/repo allscale-checkout
rm -rf allscale-checkout/.git allscale-checkout/.wordpress-org allscale-checkout/docs allscale-checkout/.gitignore
zip -rq allscale-checkout.zip allscale-checkout/
```

Confirm:

- ZIP is under 10 MB (WP.org limit; we're at ~125 KB).
- `readme.txt` at the top of `allscale-checkout/`.
- `allscale-checkout.php` at the top of `allscale-checkout/` with a valid Plugin Name header.
- No `.git/`, `docs/`, `.wordpress-org/`, or `.distignore`. (These are for the source repo, not for the merchant install.)

### 3.3 Submit at https://wordpress.org/plugins/developers/add/

Fill out:

- **Plugin Name** — the value chosen in §1.2.
- **Plugin Description** — short, ≤150 chars. Reuse the short description line at the top of readme.txt.
- **Upload Plugin** — the ZIP from §3.2.

Submit. WP.org's automated checker runs immediately (license, slug, basic sanity). If it passes, the submission queues for human review.

### 3.4 Wait for review

Recent average is **2 weeks**, sometimes faster, sometimes 4+ weeks. Plan accordingly — don't promise a launch date pinned to WP.org approval.

The reviewer either:

- **Approves** — sends an email with SVN credentials and the plugin's directory URL (see §4).
- **Requests changes** — sends a list of fixes. Reply to the email with the new ZIP. Iterates until approved or rejected.
- **Rejects** — usually for trademark / naming / GPL compatibility / spam reasons. We have time to fix and re-submit.

Common reasons we might get feedback:

- **"Remove 'WordPress' from the plugin name"** — switch to `Allscale Checkout` and resubmit.
- **"Document the external API usage"** — already done in the readme.txt External services section; point them at it.
- **"Plugin reads as pre-release"** — see §1.1; remove v0/beta language and resubmit.
- **"Cannot verify Allscale trademark ownership"** — see §1.3; submit from a trademark-holder account or send authorization.

---

## 4. After approval: the SVN workflow

This is the part that surprises most people. WordPress.org uses **Subversion** (not git) for plugin distribution. After approval, we get an SVN repo at `https://plugins.svn.wordpress.org/allscale-checkout/` with this layout:

```
plugins.svn.wordpress.org/allscale-checkout/
├── trunk/         ← current development version (the code merchants install)
├── tags/
│   ├── 1.0.0/    ← each released version, committed once and not touched again
│   └── ...
└── assets/        ← banner / icon / screenshots (NOT shipped to merchants)
    ├── icon-128x128.png
    ├── icon-256x256.png
    ├── banner-772x250.png
    ├── banner-1544x500.png
    ├── screenshot-1.png
    └── ...
```

### 4.1 Manual SVN release workflow

```bash
# One-time setup
svn co https://plugins.svn.wordpress.org/allscale-checkout/ svn-allscale
cd svn-allscale

# Copy our plugin code → trunk/
rsync -av --delete \
  --exclude='.git/' \
  --exclude='docs/' \
  --exclude='.wordpress-org/' \
  --exclude='.gitignore' \
  /path/to/git/repo/ \
  trunk/

# Copy the WP.org-only assets → assets/
rsync -av /path/to/git/repo/.wordpress-org/ assets/

# Tag this release
svn cp trunk/ tags/1.0.0/

# Commit. WP.org will email a deploy notification.
svn ci -m "Release 1.0.0" --username allscaleio
```

### 4.2 Automated SVN release via GitHub Action

The friendlier path: use [`10up/action-wordpress-plugin-deploy`](https://github.com/10up/action-wordpress-plugin-deploy). Add a workflow at `.github/workflows/wporg-deploy.yml`:

```yaml
name: Deploy to WordPress.org
on:
  release:
    types: [published]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: 10up/action-wordpress-plugin-deploy@stable
        env:
          SVN_USERNAME: ${{ secrets.WP_ORG_USERNAME }}
          SVN_PASSWORD: ${{ secrets.WP_ORG_PASSWORD }}
          SLUG: allscale-checkout
          ASSETS_DIR: .wordpress-org
```

With this, every time we publish a GitHub Release, the action mirrors the repo (minus `.git/`, `.wordpress-org/`, `docs/`) to SVN `trunk/`, copies `.wordpress-org/*` to SVN `assets/`, and tags `tags/<version>/`. No manual SVN required.

Set up after first approval — needs the WP.org username + password as GitHub secrets.

---

## 5. After approval: ongoing obligations

- **Security disclosures.** WP.org's security team monitors all plugins. If they find a vulnerability, they email a report and expect a fix within ~30 days. If unfixed, the plugin is removed from the directory until patched.
- **`Tested up to` header.** Bump this whenever a new WP version ships and we've verified compatibility. WP shows a "may not be compatible with your version" warning if our `Tested up to` is more than two WP versions behind.
- **`Stable tag` in readme.txt.** Always points at the latest released version's tag in SVN. Bump this for every release.
- **Support forum.** Each plugin has a forum at `wordpress.org/support/plugin/allscale-checkout/`. Reasonable etiquette: respond within a few days. Long-quiet support forums get the plugin flagged as "abandoned."
- **Reviews.** Stars + comments live at `wordpress.org/plugins/allscale-checkout/reviews/`. Read, don't argue with negative reviews publicly — reply once with help, move private.

---

## 6. Out of scope for this submission

- **Allscale dashboard integration UI** — we already require the merchant to paste credentials manually. No OAuth handshake here.
- **WP.org plugin reviews / testimonials** — those come from merchants, not us.
- **Localization beyond .pot template** — we ship the text-domain hook; community translators populate via translate.wordpress.org after approval.
- **Premium / paid tier** — WordPress.org allows freemium plugins but they have constraints (no upgrade nags inside the plugin admin, no premium-only features in the free version unless documented). Out of scope unless we want to add paid tiers later.

---

## 7. Open questions to revisit

- Do we want to wire up `10up/action-wordpress-plugin-deploy` now (so the moment we get approval, the next git tag auto-publishes) or wait until approval and set it up reactively?
- What email address gets WP.org's review correspondence?
- Banner design — do we want Claude Design to produce a real banner pair before submission, or accept the placeholder for v1?
