[English](README.md) | [Español](README.es.md) | [Français](README.fr.md) | **Deutsch**

# Trusteed Agentic Commerce for PrestaShop

Ermöglichen Sie es neuen Online-Käufern, den KI-Agenten, in Ihrem Shop sicher und zuverlässig einzukaufen – dank Trusteed: dem Netzwerk, das Vertrauen zwischen Unternehmen und Agenten schafft.

- **Legen Sie Ihre Geschäftsregeln fest**: wem Sie den Kauf erlauben, bis zu welchem Betrag, welche Kategorien Sie Agenten nicht anbieten möchten, legen Sie Preisgrenzen fest, halten Sie Lagerbestände aufrecht, um sich vor potenziell betrügerischen Agenten zu schützen, und vieles mehr.
- **Manipulationssichere Belege**: Wir erstellen elektronisch signierte und kryptografisch manipulationssichere Belege, die im Streitfall als Nachweis der tatsächlichen Transaktion dienen. Kompatibel mit eIDAS (EU, UK) und eSIGN (USA).
- **Agenten-Analysen**: Sehen Sie Statistiken zu Agentenkäufen ein — wie viel sie ausgeben, welche Produkte sie kaufen und wie oft.
- **Agenten sperren**: Sperren Sie potenziell gefährliche oder problematische Agenten.
- **Digitale Währungen**: ermöglicht Käufe in digitalen Währungen dank des X402-Protokolls.
- **Peer-to-Peer-Transaktionen**: ermöglicht direkten Peer-to-Peer-Handel zwischen Agenten und Händlern.

## Screenshots

| Dashboard | Trust Score |
|-----------|------------|
| ![Dashboard](screenshots/01-home-dashboard.png) | ![Trust Score](screenshots/02-trust-score-breakdown.png) |

| Bestellungen | Zahlungen |
|--------|---------|
| ![Orders](screenshots/03-merchant-center-orders.png) | ![Payments](screenshots/03b-merchant-center-payments.png) |

| Zertifizierungen | Meine Verkäufe |
|---------------|---------|
| ![Certifications](screenshots/04-merchant-center-certifications.png) | ![My Sales](screenshots/05-my-sales-orders.png) |

| KI-Belege | Agenten |
|------------|-------|
| ![AI Receipts](screenshots/06-my-sales-ai-receipts.png) | ![Agents](screenshots/07-agents.png) |

## Funktionen

- Trust Center: Belege, Signaturschlüssel, Audit-Log, Trust Score
- Merchant Center: Bestellungen, Zahlungsmethoden, Agenten, Checkout-Konfiguration, Zertifizierung + NLWeb
- Onboarding-Assistent (4 Schritte — keine Konfigurationsseite nötig)
- Trust-Center-Zugriff nur für Super-Administratoren (fail-closed Bootstrap-Relay), wobei der
  `all_shops`-Scope des Super-Administrators serverseitig ermittelt wird
- `displayBackOfficeTop`-Vertrauensbadge auf jeder Backoffice-Seite

## Voraussetzungen

- PrestaShop 8.0.0 – 9.99.99
- PHP 8.1+
- Ein Trusteed-Konto unter app.trusteed.xyz

## Installation

### Option 1: PrestaShop Addons (empfohlen)

[Demnächst verfügbar]

### Option 2: Manueller Upload (empfohlen)

1. **Laden Sie die installierbare `.zip`-Datei** von der neuesten GitHub-Release herunter:
   [**⬇ trusteed-agentic-commerce-prestashop-1.0.0.zip**](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases/latest/download/trusteed-agentic-commerce-prestashop-1.0.0.zip)
   — oder durchsuchen Sie alle Versionen auf der [Releases-Seite](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases).
2. In Ihrem PS-Backoffice: **Module → Modul-Manager → Modul hochladen**.
3. Laden Sie die heruntergeladene Datei `trusteed-agentic-commerce-prestashop-1.0.0.zip` hoch.
4. Klicken Sie auf **Konfigurieren** → der Einrichtungsassistent öffnet sich automatisch.

### Option 3: Docker / Entwicklung

```bash
docker compose -f e2e/docker/ps-staging.yml up -d
docker compose -f e2e/docker/ps-staging.yml exec prestashop \
  php bin/console prestashop:module install trusteed
```

## Schnellstart-Assistent

Bei der Erstinstallation (oder wenn `AGENTICMCP_MERCHANT_ID` nicht gesetzt ist) leitet das Modul zum Schnellstart-Assistenten weiter:

1. **Willkommen** — Voraussetzungen bestätigen (Konto + ausgehendes HTTPS)
2. **Verbinden** — das Trusteed-Portal öffnen und zu „Store verbinden → PrestaShop" navigieren
3. **Zugangsdaten** — Merchant-ID und Bootstrap-Secret einfügen
4. **Test** — auf „Zugangsdaten prüfen" klicken, um die Verbindung zu verifizieren

## Multi-Shop (PrestaShop Multistore)

- Jeder Shop verfügt über einen eigenen Embed-Kontext (auf `id_shop` begrenzter Claim).
- **Nur Super-Administratoren (`id_profile === _PS_ADMIN_PROFILE_`) können das Trust
  Center öffnen.** Das Bootstrap-Relay schlägt für jedes andere Profil fail-closed (HTTP 403)
  fehl, sodass Nicht-Super-Administratoren überhaupt kein Embed-Token erhalten — es gibt keine
  eingeschränkte „normale Mitarbeiter"-Ansicht des Trust Centers.
- Für Super-Administratoren wird die `all_shops`-Liste serverseitig aus der tatsächlichen
  Shop-Liste (`Shop::getCompleteListOfShopsID()`) abgeleitet, nie aus einem vom Aufrufer
  kontrollierten Backoffice-Kontext, und die SPA zeigt ein Dropdown zur Shop-Auswahl an.

## Modul-Dateien

```
trusteed/
├── trusteed.php                  — Module class `Trusteed` (install/uninstall/hooks)
├── classes/
│   ├── TokenBroker.php           — HS256 JWT signer + curl exchange
│   └── AutoRegister.php          — Best-effort store auto-registration against the Trusteed backend
├── controllers/admin/
│   ├── AdminAgenticTrustController.php  — Trust Center embed page + AJAX bootstrap
│   └── AdminAgenticWizardController.php — Setup wizard
├── views/
│   ├── templates/admin/
│   │   ├── trust.tpl             — SPA host page
│   │   └── wizard.tpl            — 4-step setup wizard
│   └── js/
│       ├── admin-spa.js          — Bundled SPA (post-build copy from WP plugin)
│       └── trusteed-init.js      — CSP-safe external bootstrap (token + mount)
├── tests/unit/
│   ├── InstallTest.php
│   └── TokenBrokerTest.php
└── translations/                 — XLIFF locale files (en-US, es-ES, fr-FR, de-DE)
```

## Sicherheitshinweise

- Das Bootstrap-Secret wird in der PS-Tabelle `Configuration` gespeichert (auf DB-Ebene mit der nativen PS-Verschlüsselung verschlüsselt).
- JWT-TTL: 30 Sekunden. Access-Token: 5 Minuten.
- `X-Frame-Options: SAMEORIGIN` bei allen Admin-Antworten.
- CSRF-Validierung über `Tools::getAdminTokenLite`.
- **Der Zugriff auf das Trust Center ist ausschließlich Super-Administratoren vorbehalten.** Das AJAX-Bootstrap-Relay
  (`ajaxProcessIssueBootstrap`) schlägt für jeden Mitarbeiter, dessen
  `id_profile` nicht `_PS_ADMIN_PROFILE_` ist, fail-closed fehl (HTTP 403 `insufficient_capability`).
  Die PrestaShop-Tab-ACL garantiert nur eine *Anzeige*-Berechtigung, die
  niedrigprivilegiert ist; da das ausgestellte Token eine
  `admin_trusteed`-Berechtigung (händlerweite Trust-Schreibrechte) trägt, beschränken wir sie
  auf Super-Administratoren.
- **Token-Speicherung — Bearer im DOM, KEIN httpOnly-Cookie.** Der Relay-Endpunkt
  `POST /v1/embed/ps/issue-token` liefert ein kurzlebiges (TTL ≤ 900s)
  **opakes** Token im JSON-Feld `access_token` zurück. Das externe Bootstrap-Skript
  (`views/js/trusteed-init.js`) hält es im JS-Speicher, und die gemeinsame SPA
  persistiert es in tab-gebundenem `sessionStorage`; es wird als
  `Bearer`-Authorization-Header über den `getToken`-Callback des API-Clients an die API gesendet.
  httpOnly-Cookies werden bewusst NICHT verwendet, da JS sie nicht lesen kann, um
  den Bearer-Header anzuhängen. Das Token ist daher dem JS der Seite ausgesetzt,
  weshalb seine kurze TTL + proaktive Erneuerung (Sicherheitsmarge der letzten 60s) die primäre
  Schutzmaßnahme sind, nicht die Cookie-Isolation.

### CSP — keine Inline-Skripte

`trust.tpl` enthält **keine Inline-`<script>`-Blöcke** und funktioniert daher unter einer
strikten Content-Security-Policy. Es lädt der Reihe nach zwei externe Skripte:

1. `views/js/admin-spa.js` — die gebündelte SPA (definiert `window.TrusteedEmbed`).
2. `views/js/trusteed-init.js` — das CSP-sichere Bootstrap-Skript, das das opake Token
   abruft/erneuert und `TrusteedEmbed.mount(...)` aufruft.

Von Smarty injizierte Werte (CSRF-Token, AJAX-Token-Endpunkt) werden
`trusteed-init.js` über `data-*`-Attribute am `#amcp-root`-Container übergeben und
mit `element.dataset` gelesen — nichts wird in ausführbares JS interpoliert.

## Kompatibilität

| PrestaShop | PHP  | Status                    |
| ---------- | ---- | ------------------------- |
| 8.0 – 8.2  | 8.1+ | ✅ Getestet               |
| 9.x        | 8.2+ | ⚠️ Sollte funktionieren (ungetestet) |

## Lizenz

MIT © Trusteed
