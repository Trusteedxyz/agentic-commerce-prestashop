[English](README.md) | [Español](README.es.md) | [Français](README.fr.md) | **Deutsch**

# Trusteed AgenticTools für PrestaShop

KI-Agenten sind eine neue Art von Online-Käufern. Mit Trusteed, dem Netzwerk, das Unternehmen und Agenten verbindet, können sie zu Ihren Bedingungen in Ihrem Shop einkaufen.

- Legen Sie Ihre Geschäftsregeln fest: wer kaufen darf, bis zu welchem Betrag, welche Kategorien Sie Agenten nicht anbieten, Preisgrenzen, Lagerbestände, die Sie vor betrügerischen Agenten schützen, und mehr.
- Erhalten Sie signierte Belege. Jede Transaktion erzeugt einen kryptografisch signierten Beleg, an dem sich jede Manipulation erkennen lässt und den Sie im Streitfall als Nachweis des Kaufs verwenden können. An eIDAS (EU) und eSIGN (USA) ausgerichtet.
- Sehen Sie, was Agenten tun: wie viel sie ausgeben, was sie kaufen und wie oft.
- Sperren Sie Agenten, die gefährlich wirken oder Probleme verursachen.
- Nehmen Sie Käufe in digitalen Währungen über das X402-Protokoll an.
- Lassen Sie Agenten und Händler direkt miteinander handeln, Peer-to-Peer.

## Screenshots

| Start | Trust Score | Merchant Center — Bestellungen |
|------|------------|--------------------------|
| ![Start](screenshots/01-home-dashboard.png) | ![Trust Score](screenshots/02-trust-score-breakdown.png) | ![Bestellungen](screenshots/03-merchant-center-orders.png) |

| Merchant Center — Zahlungsmethoden | Merchant Center — Zertifizierungen | Meine Verkäufe |
|----------------------------|-----------------------------------|----------|
| ![Zahlungen](screenshots/03b-merchant-center-payments.png) | ![Zertifizierungen](screenshots/04-merchant-center-certifications.png) | ![Meine Verkäufe](screenshots/05-my-sales-orders.png) |

| Trust Receipts (Meine Verkäufe → KI-Verkäufe) | Agenten |
|---------------------------------------|--------|
| ![Belege](screenshots/06-my-sales-ai-receipts.png) | ![Agenten](screenshots/07-agents.png) |

| Beleg-Detail — Download als ZIP |
|-----------------------------------|
| ![Beleg-Download](screenshots/08-my-sales-receipt-download.png) |

Jede Agententransaktion erzeugt einen signierten Vertrauensbeleg (Trust Receipt), einen Datensatz, an dem sich jede Manipulation erkennen lässt (an eIDAS und eSIGN ausgerichtet), der unter **Meine Verkäufe → KI-Verkäufe** aufgeführt wird. Mit einem Klick auf eine Zeile sehen Sie die Details: Agenten-ID, aufgerufenes Tool, Input- und Output-Hashes, JWS. Den Beleg können Sie außerdem als ZIP-Datei herunterladen und für den Streitfall aufbewahren.

## Funktionen

Trusteed AgenticTools vereint Trust Center, Merchant Center, agentische MCP-Tools und Checkout-Durchsetzung in einem einzigen PrestaShop-Modul.

- Trust Center: signierte Trust Receipts, Signaturschlüssel, Audit-Log, Aufschlüsselung des Trust Scores.
- Merchant Center: Bestellungen, Zahlungsmethoden, Agenten, Checkout-Regeln, Zertifizierungs- und NLWeb-Status.
- 5 native MCP-Tools für das PrestaShop-MCP-Server-Add-on (Marketplace-ID 96617): `trusteed_sign_trust_receipt`, `trusteed_verify_agent_signature`, `trusteed_dispatch_payment_acp`, `trusteed_dispatch_payment_ap2`, `trusteed_dispatch_payment_x402`. Agenten (Claude Desktop und andere) können Belege signieren und Zahlungen direkt aus PrestaShop auslösen.
- Checkout-Durchsetzung: Händlerregeln (Höchstbetrag, gesperrte Länder, Geschäftszeiten und mehr) gelten bei jedem Checkout, ob mit Agent oder von einem Menschen.
- Offline-Sicherheitsventil-Evaluator: setzt dieselben universellen Regeln lokal durch, wenn die entfernte Regel-API nicht erreichbar ist, statt pauschal zu erlauben oder zu blockieren.
- Selbstbedienungs-Auto-Registrierung: Registrierung des Shops bei Trusteed mit einem Klick. Sie können Ihre Zugangsdaten auch manuell einfügen.
- Fail-Closed-Standardeinstellungen: Die Durchsetzung erlaubt bei Fehlkonfiguration nie stillschweigend.

## Kompatibilität

| Komponente | Unterstützt |
|-----------|-----------|
| PrestaShop | 8.0.0 – 9.99.99 |
| PHP | 8.1+ |

## Voraussetzungen

- PrestaShop 8.0.0 oder neuer
- PHP 8.1 oder neuer
- Ein Trusteed-Konto ([kostenlos registrieren auf trusteed.xyz](https://trusteed.xyz))

## Installation

### Manueller Upload

1. **Laden Sie die installierbare `.zip`** aus dem neuesten GitHub-Release herunter:
   [**⬇ trusteed-agentic-commerce-prestashop-2.0.1.zip**](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases/latest/download/trusteed-agentic-commerce-prestashop-2.0.1.zip)
   oder durchsuchen Sie alle Versionen auf der [Releases-Seite](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases).
2. In Ihrem PrestaShop-**Back Office**: **Module → Modul-Manager → Modul hochladen**.
3. Wählen Sie die heruntergeladene `.zip` aus und klicken Sie auf **Dieses Modul hochladen**.
4. Klicken Sie auf **Konfigurieren**.

### Aus dem Quellcode (die Zip selbst bauen)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git
cd agentic-commerce-prestashop
bash bin/build-zip.sh   # erzeugt dist/trusteed-agentic-commerce-prestashop-<version>.zip
```

Das Modul enthält einen PSR-4-Fallback-Autoloader für den Namespace `Trusteed\` und läuft deshalb auch ohne `vendor/`-Verzeichnis. Das Build-Skript packt keins ein, und `composer install` ist optional.

### Über Composer (optional, für IDE-Tooling / lokale Entwicklung)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git trusteed
cd trusteed
composer install --no-dev --optimize-autoloader
```
Laden Sie anschließend den entstandenen Ordner `trusteed/` wie oben beschrieben als `.zip` hoch. Für Produktivinstallationen können Sie das überspringen, siehe den Hinweis zum Fallback-Autoloader oben.

## Konfiguration

1. Melden Sie sich in Ihrem PrestaShop-**Back Office** an.
2. Gehen Sie zu **Module → Trusteed AgenticTools → Konfigurieren**.
3. Klicken Sie entweder auf **Diesen Shop automatisch registrieren** (Registrierung mit einem Klick, die Merchant ID und Secret automatisch einträgt), oder fügen Sie Ihre **Merchant ID** und Ihr **S2S-Secret** manuell von [app.trusteed.xyz/settings](https://app.trusteed.xyz/settings) ein.
4. Speichern Sie. Das Modul testet die Verbindung und beginnt, die Durchsetzungsregeln zu synchronisieren.

### Konfigurationsschlüssel

| Schlüssel | Standard | Zweck |
|-----|---------|-------------|
| `TRUSTEED_API_BASE` | `https://api.trusteed.xyz` | Endpunkt des Trusteed-Backends |
| `TRUSTEED_CEL_MERCHANT_ID` | _(leer)_ | Von Trusteed ausgestellte Merchant ID |
| `TRUSTEED_EMBED_S2S_SECRET` | _(leer)_ | Server-zu-Server-Secret für die Embed-/Enforcement-API |
| `TRUSTEED_BOOTSTRAP_TOKEN` | _(leer)_ | Veraltetes Embed-Bootstrap-Token (durch Auto-Registrierung ersetzt) |

## Admin-Seiten

Nach der Installation erscheint in der Seitenleiste des PrestaShop-Back-Office ein **Trusteed**-Menü:

| Seite | Beschreibung |
|------|-------------|
| Start | Übersicht über Reputation und aktuelle Verkäufe |
| Wie läuft mein Shop? (Trust Center) | Signierte Belege, Signaturschlüssel, Audit-Log, Trust Score |
| Merchant Center | Bestellungen, Zahlungsmethoden, Agenten, Zertifizierungen, NLWeb |
| Meine Verkäufe | Bestellliste und KI-Trust-Receipts |
| Meine Regeln | Regeln zur Checkout-Durchsetzung |
| Sicherheit | Audit-Log und Anomalie-Warnungen |
| Agenten | Verbundene Agenten-Identitäten |
| Einstellungen | Moduleinstellungen und Auto-Registrierung |

## FAQ

**Welche Daten werden übermittelt?** Nur das, was Durchsetzungsregeln und Trust Receipts brauchen: Bestellsummen, Land und Agenten-Identität. Kartenzahlungsdaten laufen nie über Trusteed. Die gesamte Kommunikation läuft über HTTPS.

**Welche Agenten werden unterstützt?** Jeder Agent, der über das PrestaShop-MCP-Server-Add-on (Marketplace-ID 96617) verbunden ist, darunter Claude Desktop und andere MCP-kompatible Clients.

**Verlangsamt es meinen Shop?** Nein. Die Checkout-Durchsetzung läuft nur bei der Bestellvalidierung synchron, mit einem lokalen Offline-Fallback, wenn die entfernte API nicht erreichbar ist.

## Änderungsprotokoll

### 2.0.1

- Fix: Admin-SPA-Bundle neu gebaut (Streitfall-Nachweis Phase A: Die echte Belegliste ist jetzt unter Meine Verkäufe eingebunden, wie bei Magento und WooCommerce).
- Neu: Paketier-Skript `bin/build-zip.sh`. Die installierbare `.zip` wird jetzt als GitHub-Release-Asset veröffentlicht, sodass Händler sie nicht mehr selbst bauen müssen.

### 2.0.0

**Wichtig:** Dieses Release ersetzt Inhalt, der in diesem Repository fälschlich unter `v1.0.0` veröffentlicht wurde. Statt dieses Moduls für Checkout-Durchsetzung und AgenticTools wurde ein anderes, eigenständiges Modul („Trusteed Trust Center“) ausgeliefert. Dies ist das erste korrekte Release.

- Fix: Die Checkout-Durchsetzung wurde bei organischen Checkouts (ohne Agent) komplett übersprungen. Händlerregeln wie Höchstbetrag, gesperrte Länder und Geschäftszeiten liefen nur, wenn ein Agenten-Token vorhanden war. Diese Regeln gelten jetzt bei jedem Checkout, unabhängig davon, ob ein Agent beteiligt ist.
- Neu: ein Offline-Sicherheitsventil-Evaluator, der dieselben universellen Händlerregeln lokal durchsetzt, wenn die entfernte API zur Regelauswertung nicht erreichbar ist.
- Neu: Selbstbedienungs-Auto-Registrierung (Registrierung des Shops mit einem Klick, zusätzlich zum bestehenden manuellen Einfügen der Zugangsdaten).
- Vollständiges technisches Rebranding von `mcpwebstore`/`Mcpwebstore` zu `trusteed`/`Trusteed`: PSR-4-Namespace, technischer Modulname, Konfigurationskonstanten und die Namen der 5 MCP-Tools, die Agenten aufrufen.

## Support

- Support-E-Mail: support@trusteed.xyz
- GitHub Issues: [github.com/Trusteedxyz/agentic-commerce-prestashop/issues](https://github.com/Trusteedxyz/agentic-commerce-prestashop/issues)

## Lizenz

MIT. Den vollständigen Text finden Sie in [LICENSE](LICENSE).
