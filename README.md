**English** | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md)

# Trusteed AgenticTools for PrestaShop

AI agents are a new kind of online shopper. With Trusteed, the network that connects businesses and agents, they can buy from your store on your terms.

- Set your business rules: who can buy, up to what amount, which categories you don't offer to agents, price limits, stock levels that protect you from fraudulent agents, and more.
- Get signed receipts. Every transaction produces a cryptographically signed, tamper-evident receipt you can use as proof of the purchase if there's a dispute. Aligned with eIDAS (EU) and eSIGN (USA).
- See what agents do: how much they spend, what they buy and how often.
- Block agents that look dangerous or cause problems.
- Accept purchases in digital currencies through the X402 protocol.
- Let agents and merchants trade directly, peer to peer.

## Screenshots

| Home | Trust Score | Merchant Center — Orders |
|------|------------|--------------------------|
| ![Home](screenshots/01-home-dashboard.png) | ![Trust Score](screenshots/02-trust-score-breakdown.png) | ![Orders](screenshots/03-merchant-center-orders.png) |

| Merchant Center — Payments | Merchant Center — Certifications | My Sales |
|----------------------------|-----------------------------------|----------|
| ![Payments](screenshots/03b-merchant-center-payments.png) | ![Certifications](screenshots/04-merchant-center-certifications.png) | ![My Sales](screenshots/05-my-sales-orders.png) |

| Trust Receipts (My Sales → AI Sales) | Agents |
|---------------------------------------|--------|
| ![Trust Receipts](screenshots/06-my-sales-ai-receipts.png) | ![Agents](screenshots/07-agents.png) |

| Trust Receipt detail — download as ZIP |
|-----------------------------------------|
| ![Trust Receipt download](screenshots/08-my-sales-receipt-download.png) |

Every agent transaction produces a signed trust receipt, a tamper-evident record (aligned with eIDAS and eSIGN) listed under **My Sales → AI Sales**. Click a row to see the details: agent ID, tool called, input and output hashes, JWS. You can also download the receipt as a ZIP file and keep it as backup in case of a dispute.

## Features

Trusteed AgenticTools brings Trust Center, Merchant Center, MCP agentic tools and checkout enforcement together in a single PrestaShop module.

- Trust Center: signed trust receipts, signing keys, audit log, trust score breakdown.
- Merchant Center: orders, payment methods, agents, checkout rules, certification and NLWeb status.
- 5 native MCP tools for the PrestaShop MCP Server add-on (marketplace ID 96617): `trusteed_sign_trust_receipt`, `trusteed_verify_agent_signature`, `trusteed_dispatch_payment_acp`, `trusteed_dispatch_payment_ap2`, `trusteed_dispatch_payment_x402`. Agents (Claude Desktop and others) can sign receipts and dispatch payments straight from PrestaShop.
- Checkout enforcement: merchant rules (max order amount, blocked countries, business hours and more) apply on every checkout, agent or human.
- Offline safety-valve evaluator: enforces the same universal rules locally when the remote rules API is unreachable, instead of a blanket allow/block fallback.
- Self-serve auto-registration: one-click store registration with Trusteed. You can also paste your credentials manually.
- Fail-closed defaults: enforcement never silently allows when it is misconfigured.

## Compatibility

| Component | Supported |
|-----------|-----------|
| PrestaShop | 8.0.0 – 9.99.99 |
| PHP | 8.1+ |

## Requirements

- PrestaShop 8.0.0 or newer
- PHP 8.1 or newer
- A Trusteed account ([sign up free at trusteed.xyz](https://trusteed.xyz))

## Installation

### Manual upload

1. **Download the installable `.zip`** from the latest GitHub Release:
   [**⬇ trusteed-agentic-commerce-prestashop-2.0.1.zip**](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases/latest/download/trusteed-agentic-commerce-prestashop-2.0.1.zip)
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
3. Either click **Auto-register this store** (one-click registration that fills the Merchant ID and secret automatically), or paste your **Merchant ID** and **S2S secret** manually from [app.trusteed.xyz/settings](https://app.trusteed.xyz/settings).
4. Save. The module tests connectivity and starts syncing enforcement rules.

### Configuration keys

| Key | Default | Purpose |
|-----|---------|---------|
| `TRUSTEED_API_BASE` | `https://api.trusteed.xyz` | Trusteed backend endpoint |
| `TRUSTEED_CEL_MERCHANT_ID` | _(empty)_ | Merchant ID issued by Trusteed |
| `TRUSTEED_EMBED_S2S_SECRET` | _(empty)_ | Server-to-server secret for the embed/enforcement API |
| `TRUSTEED_BOOTSTRAP_TOKEN` | _(empty)_ | Legacy embed-bootstrap token (superseded by auto-registration) |

## Admin pages

After installation a **Trusteed** menu appears in the PrestaShop Back Office sidebar:

| Page | Description |
|------|-------------|
| Home | Reputation and recent sales overview |
| How is my store doing? (Trust Center) | Signed receipts, signing keys, audit log, trust score |
| Merchant Center | Orders, payment methods, agents, certifications, NLWeb |
| My sales | Order list and AI trust receipts |
| My Rules | Checkout enforcement rules |
| Security | Audit log and anomaly alerts |
| Agents | Connected agent identities |
| Settings | Module settings and auto-registration |

## FAQ

**What data is sent?** Only what enforcement rules and trust receipts require: order totals, country and agent identity. No payment card data ever passes through Trusteed. All communication uses HTTPS.

**Which agents are supported?** Any agent connected through the PrestaShop MCP Server add-on (marketplace ID 96617), including Claude Desktop and other MCP-compatible clients.

**Does it slow down my store?** No. Checkout enforcement runs synchronously only at order validation, with a local offline fallback when the remote API is unreachable.

## Changelog

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
