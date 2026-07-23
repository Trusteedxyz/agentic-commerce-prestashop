**English** | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md)

# Trusteed AgenticTools for PrestaShop

Enable new online shoppers, AI agents, to make purchases in your store securely and reliably thanks to Trusteed: the network that fosters trust between businesses and agents.

- **Set your business rules**: who you allow to buy, up to what amount, which categories you don't want to offer to agents, set price limits, maintain stock levels to protect yourself against potential fraudulent agents, and more.
- **Tamper-proof receipts**: we generate electronically signed and cryptographically tamper-proof receipts that serve as proof of the actual transaction in case of any dispute. Compatible with eIDAS (EU, UK) and eSIGN (USA) regulations.
- **Agent analytics**: view statistics on agent purchases — how much they spend, what products they buy, and how often.
- **Agent blocking**: block potentially dangerous or problematic agents.
- **Digital currencies**: enables purchases in digital currencies thanks to the X402 protocol.
- **Peer-to-peer transactions**: enables direct peer-to-peer commerce between agents and merchants.

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

Every agent transaction produces a cryptographically signed **trust receipt** — a tamper-proof record (compatible with eIDAS / eSIGN) listed under **My Sales → AI Sales**. Click any row to see the full detail (agent ID, tool called, input/output hashes, JWS) and download the receipt as a ZIP file to hand over as backup in case of a dispute.

## Features

Trusteed AgenticTools consolidates Trust Center, Merchant Center, MCP agentic tools, and checkout enforcement into a single PrestaShop module.

- **Trust Center** — signed trust receipts, signing keys, audit log, trust score breakdown
- **Merchant Center** — orders, payment methods, agents, checkout rules, certification & NLWeb status
- **5 native MCP tools** for the PrestaShop MCP Server add-on (marketplace ID 96617): `trusteed_sign_trust_receipt`, `trusteed_verify_agent_signature`, `trusteed_dispatch_payment_acp`, `trusteed_dispatch_payment_ap2`, `trusteed_dispatch_payment_x402` — agents (Claude Desktop, etc.) can sign receipts and dispatch payments directly from PrestaShop
- **Checkout enforcement** — merchant rules (max order amount, blocked countries, business hours, and more) apply on every checkout, agent or human
- **Offline safety-valve evaluator** — enforces the same universal rules locally when the remote rules API is unreachable, instead of a blanket allow/block fallback
- **Self-serve auto-registration** — one-click store registration with Trusteed; credentials can also be pasted in manually
- **Fail-closed defaults** — enforcement never silently allows when misconfigured

## Compatibility

| Component | Supported |
|-----------|-----------|
| PrestaShop | 8.0.0 – 9.99.99 |
| PHP | 8.1+ |

## Requirements

- PrestaShop 8.0.0 or newer
- PHP 8.1 or newer
- A Trusteed account — [sign up free at trusteed.xyz](https://trusteed.xyz)

## Installation

### Manual upload

1. Download the source from the latest [Releases page](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases) (or `git clone` this repository).
2. Rename the extracted top-level folder to `trusteed` (PrestaShop requires the folder name to match the module's technical name) and compress it back into a `.zip`.
3. In your PrestaShop **Back Office**: **Modules → Module Manager → Upload a module**.
4. Select the `trusteed.zip` you just created and click **Upload this module**.
5. Click **Configure**.

### Via Composer (for development)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git trusteed
cd trusteed
composer install --no-dev --optimize-autoloader
```
Then upload the resulting `trusteed/` folder as a `.zip` as described above. The module also ships a PSR-4 fallback autoloader, so it will still run even without a `vendor/` directory (composer install is optional, not required).

## Configuration

1. Log in to your PrestaShop **Back Office**.
2. Go to **Modules → Trusteed AgenticTools → Configure**.
3. Either click **Auto-register this store** (one-click registration that fills the Merchant ID and secret automatically), or paste your **Merchant ID** and **S2S secret** manually from [app.trusteed.xyz/settings](https://app.trusteed.xyz/settings).
4. Save — the module tests connectivity and starts syncing enforcement rules.

### Configuration keys

| Key | Default | Purpose |
|-----|---------|---------|
| `TRUSTEED_API_BASE` | `https://api.trusteed.xyz` | Trusteed backend endpoint |
| `TRUSTEED_CEL_MERCHANT_ID` | _(empty)_ | Merchant ID issued by Trusteed |
| `TRUSTEED_EMBED_S2S_SECRET` | _(empty)_ | Server-to-server secret for the embed/enforcement API |
| `TRUSTEED_BOOTSTRAP_TOKEN` | _(empty)_ | Legacy embed-bootstrap token (superseded by auto-registration) |

## Admin Pages

After installation a **Trusteed** menu appears in the PrestaShop Back Office sidebar:

| Page | Description |
|------|-------------|
| Home | Reputation and recent sales overview |
| Trust Center | Signed receipts, signing keys, audit log, trust score |
| Merchant Center | Orders, payment methods, agents, certifications, NLWeb |
| My Sales | Order list and AI trust receipts |
| Rules | Checkout enforcement rules |
| Agents | Connected agent identities |
| Security | Audit log and anomaly alerts |
| Config | Module settings and auto-registration |

## FAQ

**What data is sent?** Only what enforcement rules and trust receipts require (order totals, country, agent identity). No payment card data ever passes through Trusteed. All communication uses HTTPS.

**Which agents are supported?** Any agent connected through the PrestaShop MCP Server add-on (marketplace ID 96617), including Claude Desktop and other MCP-compatible clients.

**Does it slow down my store?** No. Checkout enforcement runs synchronously only at order validation, with a local offline fallback when the remote API is unreachable.

## Changelog

### 2.0.0

**Important:** this release replaces content published in error under `v1.0.0` in this repository — a different, standalone module ("Trusteed Trust Center") was shipped instead of this checkout-enforcement + AgenticTools module. This is the first correct release.

- **Fix** — checkout enforcement was skipped entirely for organic (non-agent) checkouts: merchant rules such as maximum order amount, blocked countries, and business-hours restrictions never ran unless an agent token was present. These rules now apply to every checkout regardless of agent presence.
- **Added** — an offline safety-valve evaluator that enforces the same universal merchant rules locally when the remote rules-evaluation API is unreachable.
- **Added** — self-serve auto-registration (one-click store registration, in addition to the existing manual credential-paste flow).
- Full technical rebrand from `mcpwebstore`/`Mcpwebstore` to `trusteed`/`Trusteed`: PSR-4 namespace, module technical name, config constants, and the 5 MCP tool names agents call.

## Support

- Support email: support@trusteed.xyz
- GitHub issues: [github.com/Trusteedxyz/agentic-commerce-prestashop/issues](https://github.com/Trusteedxyz/agentic-commerce-prestashop/issues)

## License

MIT. See [LICENSE](LICENSE) for full text.
