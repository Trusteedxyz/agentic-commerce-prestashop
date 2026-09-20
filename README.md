**English** | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md)

# Trusteed AgenticTools for PrestaShop

AI agents are a new kind of online shopper. With Trusteed, the network that connects businesses and agents, they can buy from your store on your terms.

- Set your business rules: who can buy, up to what amount, which categories you don't offer to agents, price limits, stock levels that protect you from fraudulent agents, and more.
- Get signed receipts. Every transaction produces a cryptographically signed, tamper-evident receipt you can use as proof of the purchase if there's a dispute. Aligned with eIDAS (EU) and eSIGN (USA).
- See what agents do: how much they spend, what they buy and how often.
- Block agents that look dangerous or cause problems.
- Accept purchases in digital currencies through the X402 protocol.
- Let agents and merchants trade directly, peer to peer.
- Check whether agents can actually buy from you today: the agent readiness dashboard shows three independent views (what others say, what you promise against what you do, and what we've observed) and never merges them into one score.

## Screenshots

| Home                                       | Trust Score                                              | Merchant Center: Orders                             |
| ------------------------------------------ | -------------------------------------------------------- | ---------------------------------------------------- |
| ![Home](screenshots/01-home-dashboard.png) | ![Trust Score](screenshots/02-trust-score-breakdown.png) | ![Orders](screenshots/03-merchant-center-orders.png) |

| Merchant Center: Payments                                | Merchant Center: Certifications                                     | My Sales                                        |
| --------------------------------------------------------- | -------------------------------------------------------------------- | ----------------------------------------------- |
| ![Payments](screenshots/03b-merchant-center-payments.png) | ![Certifications](screenshots/04-merchant-center-certifications.png) | ![My Sales](screenshots/05-my-sales-orders.png) |

| Trust Receipts (My Sales → AI Sales)                       | Agents                               |
| ---------------------------------------------------------- | ------------------------------------ |
| ![Trust Receipts](screenshots/06-my-sales-ai-receipts.png) | ![Agents](screenshots/07-agents.png) |

| Trust Receipt detail: download as ZIP                                  | Agent Readiness                                        |
| ----------------------------------------------------------------------- | ------------------------------------------------------ |
| ![Trust Receipt download](screenshots/08-my-sales-receipt-download.png) | ![Agent Readiness](screenshots/09-agent-readiness.png) |

Every agent transaction produces a signed trust receipt, a tamper-evident record (aligned with eIDAS and eSIGN) listed under **My Sales → AI Sales**. Click a row to see the details: agent ID, tool called, input and output hashes, JWS. You can also download the receipt as a ZIP file and keep it as your own evidence of what the agent did. The receipt is signed with an Ed25519 JWS, so any later change to its contents can be detected. It is not a qualified electronic signature or seal: no QTSP-issued certificate or qualified timestamp stands behind it today, so it carries no presumption of legal validity on its own.

## Features

Trusteed AgenticTools brings Trust Center, Merchant Center, MCP agentic tools and checkout enforcement together in a single PrestaShop module.

- Trust Center: signed trust receipts, signing keys, audit log, trust score breakdown.
- Merchant Center: orders, payment methods, agents, checkout rules, certification and NLWeb status.
- 5 native MCP tools for the PrestaShop MCP Server add-on (marketplace ID 96617): `trusteed_sign_trust_receipt`, `trusteed_verify_agent_signature`, `trusteed_dispatch_payment_acp`, `trusteed_dispatch_payment_ap2`, `trusteed_dispatch_payment_x402`. Agents (Claude Desktop and others) can sign receipts and dispatch payments straight from PrestaShop.
- Checkout enforcement: merchant rules (max order amount, blocked countries, business hours and more) apply on every checkout, agent or human.
- Offline safety-valve evaluator: enforces the same universal rules locally when the remote rules API is unreachable, instead of a blanket allow/block fallback.
- Self-serve auto-registration: one-click store registration with Trusteed. You can also paste your credentials manually.
- Configurable outage behavior: when the rules API is unreachable and there is no recent cached snapshot, the module ships in `balanced` mode. It lets the checkout through and logs that decision. Set `TRUSTEED_CEL_FALLBACK_MODE` to `strict` to block it instead.

## Compatibility

| Component  | Declared range                                               | Actually verified against                                                      |
| ---------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| PrestaShop | 8.0.0 – 9.99.99 (`ps_versions_compliancy` in `trusteed.php`) | 8.2.0 (all screenshots in this README, no automated E2E on other versions yet) |
| PHP        | 8.1+                                                         | 8.1, 8.2                                                                       |

The 8.0.0–9.99.99 range is what the module _declares_ to PrestaShop's module manager. It
has not been exercised end-to-end outside 8.2.0. There is no CI running PHPUnit against
multiple PrestaShop versions yet; treat 9.x support as unverified until that exists.

## Requirements

- PrestaShop 8.0.0 or newer
- PHP 8.1 or newer
- A Trusteed account ([sign up free at trusteed.xyz](https://trusteed.xyz))

## Installation

### Manual upload

1. **Download the installable `.zip`** from the latest GitHub Release:
   [**⬇ Download the latest release**](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases/latest)
   or browse all versions at the [Releases page](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases).
2. In your PrestaShop **Back Office**: **Modules → Module Manager → Upload a module**.
3. Select the downloaded `.zip` and click **Upload this module**.
4. Click **Configure**.

### From source (build the zip yourself)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git
cd agentic-commerce-prestashop
bash bin/build-zip.sh   # outputs dist/trusteed-agentic-commerce-prestashop-<version>.zip
```

The module ships a PSR-4 fallback autoloader for the `Trusteed\` namespace, so it runs even without a `vendor/` directory. The build script doesn't include one, and `composer install` is optional.

### Via Composer (optional, for IDE tooling / local dev)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git trusteed
cd trusteed
composer install --no-dev --optimize-autoloader
```

Then upload the resulting `trusteed/` folder as a `.zip` as described above. Production installs can skip this; see the autoloader note above.

## Configuration

1. Log in to your PrestaShop **Back Office**.
2. Go to **Modules → Trusteed AgenticTools → Configure**.
3. Either click **Auto-register this store** (one-click registration that fills the Merchant ID and secret automatically), or paste your **Merchant ID** and **S2S secret** manually from [trusteed.xyz/dashboard/settings](https://trusteed.xyz/dashboard/settings).
4. Save. The values are validated (HTTPS endpoint, 64-hex secret) and stored. Saving does **not** contact Trusteed; only **Auto-register this store** performs a live call.

### Configuration keys

| Key                            | Default                    | Purpose                                                                                                                                                             |
| ------------------------------ | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `TRUSTEED_API_BASE`            | `https://api.trusteed.xyz` | Trusteed backend endpoint                                                                                                                                           |
| `TRUSTEED_CEL_MERCHANT_ID`     | _(empty)_                  | Merchant ID issued by Trusteed                                                                                                                                      |
| `TRUSTEED_EMBED_S2S_SECRET`    | _(empty)_                  | Server-to-server secret for the embed/enforcement API                                                                                                               |
| `TRUSTEED_BOOTSTRAP_TOKEN`     | _(empty)_                  | Legacy embed-bootstrap token (superseded by auto-registration)                                                                                                      |
| `TRUSTEED_CEL_ENABLED`         | `0`                        | Master switch for checkout enforcement. While `0`, no rule is evaluated on any checkout                                                                             |
| `TRUSTEED_CEL_INSTALLATION_ID` | _(empty)_                  | Installation ID for the signed rule snapshot                                                                                                                        |
| `TRUSTEED_CEL_HMAC_SECRET`     | _(empty)_                  | HMAC secret for snapshot and rules-evaluate calls                                                                                                                   |
| `TRUSTEED_CEL_FALLBACK_MODE`   | `balanced`                 | Behaviour when the rules API is unreachable and no cached snapshot exists: `balanced` and `permissive` allow the checkout and log the fail-open, `strict` blocks it |

Enforcement stays fully inert until `TRUSTEED_CEL_ENABLED` is `1` **and** all three of `TRUSTEED_CEL_MERCHANT_ID`, `TRUSTEED_CEL_INSTALLATION_ID` and `TRUSTEED_CEL_HMAC_SECRET` are set. With any of them missing, the module allows every checkout through without evaluating a single rule.

## Admin pages

After installation a **Trusteed** menu appears in the PrestaShop Back Office sidebar:

| Page                   | Description                                            |
| ---------------------- | ------------------------------------------------------ |
| Home                   | Reputation and recent sales overview                   |
| How is my store doing? | Signed receipts, signing keys, audit log, trust score  |
| Merchant Center        | Orders, payment methods, agents, certifications, NLWeb |
| My sales               | Order list and AI trust receipts                       |
| My Rules               | Checkout enforcement rules                             |
| Security               | Audit log and anomaly alerts                           |
| Agents                 | Connected agent identities                             |
| Agent Readiness        | Whether agents can actually buy in this store          |
| Settings               | Module settings and auto-registration                  |

## FAQ

**What data is sent?** Only what enforcement rules and trust receipts require: order totals, country and agent identity. No payment card data ever passes through Trusteed. All communication uses HTTPS.

**Which agents are supported?** Any agent connected through the PrestaShop MCP Server add-on (marketplace ID 96617), including Claude Desktop and other MCP-compatible clients.

**Does it slow down my store?** No. Checkout enforcement runs synchronously only at order validation, with a local offline fallback when the remote API is unreachable.

## The agent readiness dashboard

**Can agents find me?** is a page in your admin panel. It answers one
question: when an AI shopping agent visits your store, does it find what you
think it finds?

The page never shows a single score. It has three columns and never averages
them, because they answer different questions and can disagree with each other:

| Column                                   | What it is                                                                                                                                                                       |
| ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **What a third party says**              | The verdict of an external scanner, quoted verbatim. We never convert it to a scale of ours, because rescaling someone else's grade would mean grading our own exam             |
| **Does what you say match what you do?** | 16 checks that compare what your store _advertises_ with what it _actually answers_. No external scanner can do this part, because it needs your credentials                    |
| **What we have seen**                    | Real agent traffic in the selected window: which agents arrived, which tools they used, how far they got and where they failed                                                  |

If a check could not run, the page marks it **not checked** and gives the reason. It
is never dropped and never counted as a pass. "We could not look" and "we looked
and it was fine" are different answers, and the page tells you which one you are
reading.

### What each check looks at

| Check | What it detects                                                      |
| ----- | -------------------------------------------------------------------- |
| C1    | You advertise tools your store does not serve                        |
| C2    | You advertise a checkout protocol whose endpoint does not answer     |
| C3    | The catalogue price is not the price charged                         |
| C4    | Things are advertised as available when they are not                 |
| C5    | Your return policy says different things depending on where you look |
| C6    | You advertise as available something that is switched off            |
| C7    | Rules switched on that cannot act for lack of data                   |
| C8    | Your rules observe but do not block                                  |
| C9    | The identification method you advertise does not work                |
| C10   | An agent can buy any amount without your confirmation                |
| C11   | The point of sale is using expired rules                             |
| C12   | Operations with no signed receipt                                    |
| C13   | Advertised addresses that do not work                                |
| C14   | Agents are seeing stale data from your store                         |
| C15   | Identity credentials about to expire                                 |
| C16   | The delivery time you promise is not the one you meet                |

Some checks need more than your settings, and the page says so instead of
leaving a gap:

- Needs your store connected (C3, C4, C5, C14). These compare against your real
  catalogue, and without credentials there is nothing to compare with.
- Needs delivered orders (C16). It compares what you promise with what you
  actually met, and that takes history.
- Nothing to compare this time. C12, for example, has nothing to check until an
  agent completes a purchase. That is not a failing grade.

The checks run once a day, and the page shows each result with its date, so
yesterday's verdict looks like yesterday's. Showing a cached "all good" as if it
were current is the kind of self-deception this page exists to catch.

## Changelog

### 2.3.1: Security fix

- Fixed: a request with a malformed agent-token signature could make the module skip every checkout rule instead of rejecting the request. Reported responsibly by Salúa Es-sair. See [GHSA-2j2x-5q52-g48m](https://github.com/Trusteedxyz/agentic-commerce-prestashop/security/advisories/GHSA-2j2x-5q52-g48m).
- Fixed: the same failure could be triggered by any human checkout, not only agents: a wrong PrestaShop API call meant no merchant rule ever ran on a normal storefront order.
- New: clicking "Upgrade" now actually refreshes the module's core override on disk. Previously only a fresh install did.

### 2.3.0

- New: the top-bar badge now has three states. It used to appear only **after** the module was configured, so installing it and not finishing produced no signal at all. It now warns when setup is unfinished, and warns separately when the store is registered but checkout enforcement is not active.
- Fixed: auto-registration claimed "credentials configured automatically" after configuring two of the five keys the module uses. It now names what it configured, and warns that enforcement needs two more values auto-registration cannot issue.
- New: if your store URL was already registered under a different install key, the module can prove it controls the domain and recover the store. That case previously had no way out: it asked for a key you never had.

### 2.2.4

- New: a check that could not run now says why in one of four groups (nothing to do, needs configuration, waiting for data, or one of our own checks failed) instead of one flat list of unexplained grays.
- New: the panel now shows which of our servers answered your request, a short opaque label. Useful when comparing what you see here with what support sees; it never reveals a hostname or service name.

### 2.2.3

- New: Settings now lets you choose which tools your store serves to agents. If you never saved a list, the panel tells you that what you serve is the basic set the platform ships with, not a choice of yours.
- New: a button to re-run the readiness check without waiting for the daily sweep, and the panel remembers what changed since the previous run.
- Changed: our own outages no longer count as your store's mismatches. The panel keeps them separate, because there is nothing you can do about them.

### 2.2.2

- Fixed: the agent readiness page shipped without its stylesheet, so the panel rendered unstyled.
- Fixed: the panel could show its shell in one language and the diagnosis in another. The resolved language now travels with the texts instead of being detected twice.
- New: every finding carries a link to where it is fixed, and the merchant's own claims (the delivery promise and the rest) appear with the backing each one has.
- Changed: a store with no run yet reads as "checking" instead of "checked once a day": opening the panel already triggers the first run in the background.

### 2.2.1

- Fixed: the Agent Readiness page rendered Home instead. `resolveSection()` validates against an allowlist that `agent-readiness` had never been added to, so it fell back silently.
- Fixed: two admin notices (missing configuration, missing assets) were hardcoded in Spanish and shown to every merchant regardless of their admin language. One of them told the merchant to run a build command from a monorepo, which no merchant can do. Both now go through the module translator.

### 2.2.0

- New: agent readiness dashboard. _Can agents find me?_ now ships in the admin panel. It compares what your store advertises with what it actually answers, in 16 checks, and shows all sixteen, not only the ones that fail. A check that could not run says why (store not connected, no delivered orders yet, nothing to compare this time) instead of leaving a gap that reads like a fault. See "The agent readiness dashboard" above.
- Fixed: the diagnosis was written in Spanish inside the API and shown verbatim, so a merchant with the panel in English read English headings above Spanish findings. The checks now emit language-neutral codes and the text is composed when served, in the language you are using.
- Fixed: check C1 ("you advertise tools your store does not serve") counted the full public catalogue as served when no tool list was configured, reporting 46 of 48 answering when the server actually serves 12. It failed in the flattering direction, which is the one this panel exists to catch.
- Fixed: check C6 ("you advertise as available something that is switched off") reported a capability as off whenever its flag was unset, even for flags that are on by default. It was a false alarm on every store.

### 2.1.1

- Fixed: the admin panel bundle (`views/js/admin-spa.js`) shipped unminified: 869 KB / 25,064 lines instead of the 490 KB / 41 lines the documented build command (`pnpm run build:ps`) actually produces. Provenance could not be verified. Rebuilt from source.
- Fixed: the R047 (ask the buyer to confirm above a threshold) rule had no form field in the admin panel; its parameters existed in the schema but could only be set via the API.

### 2.1.0

- Security fix: the agent token verifier treated `exp`, `iat` and `nonce` as optional. Every protection below them (expiry, the 330s lifetime cap, anti-replay) hung off an `isset`, so a token that simply omitted the claim skipped the check: without `exp` it was valid forever, and without `nonce` nothing was deduplicated. All three are now mandatory (`nonce` 16–64 chars), matching the canonical token schema.
- Security fix: an `iat` in the future is now rejected. Combined with the 330s lifetime cap it gave a sliding window: an `iat` an hour ahead bought an hour of wall-clock validity even though `exp - iat` stayed within the cap.
- Fix: rule R036 (max line-item value) read its cap from a parameter named `maxCents`, copied from R035. The canonical name is `maxCentsPerLine`, and it is the only one the merchant panel's strict schema accepts, so the rule could never fire.
- Removed: the offline evaluator's R007 branch. It blocked on `trustScore < 0.3` under a comment that said "high-risk country check", so it did neither what the comment claimed nor what the rule's canonical name means. R007's real signal is cross-merchant abuse state that lives in the backend database, which the offline path cannot reach. Returning ALLOW here is not fail-open over an available signal, because the signal does not exist in this context. The authoritative R007 verdict comes from the server. If you wanted the trust threshold, the rule is R006; if you wanted the country, R014/R019.
- Added: the module now reports which cart signals this installation can project (`POST /api/v1/enforcement/capabilities`, HMAC-signed, sent once per module version from an already-registered back-office hook). Without it, a rule whose signal never arrives returns `NO_SIGNAL` on every checkout: it passes silently, and the merchant sees a rule in ENFORCE that blocks nothing.

### 2.0.1

- Fix: admin SPA bundle rebuilt (dispute-evidence Phase A: the real receipt list is now mounted under My Sales, matching Magento and WooCommerce).
- Added: `bin/build-zip.sh` packaging script. The installable `.zip` is now published as a GitHub Release asset, so merchants no longer have to build it themselves.

### 2.0.0

**Important:** this release replaces content published in error under `v1.0.0` in this repository. A different, standalone module ("Trusteed Trust Center") was shipped instead of this checkout-enforcement and AgenticTools module. This is the first correct release.

- Fix: checkout enforcement was skipped entirely for organic (non-agent) checkouts. Merchant rules such as maximum order amount, blocked countries, and business-hours restrictions never ran unless an agent token was present. These rules now apply to every checkout regardless of agent presence.
- Added: an offline safety-valve evaluator that enforces the same universal merchant rules locally when the remote rules-evaluation API is unreachable.
- Added: self-serve auto-registration (one-click store registration, in addition to the existing manual credential-paste flow).
- Full technical rebrand from `mcpwebstore`/`Mcpwebstore` to `trusteed`/`Trusteed`: PSR-4 namespace, module technical name, config constants, and the 5 MCP tool names agents call.

## Support

- Support email: support@trusteed.xyz
- GitHub issues: [github.com/Trusteedxyz/agentic-commerce-prestashop/issues](https://github.com/Trusteedxyz/agentic-commerce-prestashop/issues)

## License

MIT. See [LICENSE](LICENSE) for full text.
