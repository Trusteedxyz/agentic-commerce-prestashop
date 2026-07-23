[English](README.md) | [Español](README.es.md) | [Français](README.fr.md) | **Deutsch**

# Trusteed AgenticTools für PrestaShop

Ermöglichen Sie neuen Online-Käufern, den KI-Agenten, sichere und zuverlässige Einkäufe in Ihrem Shop dank Trusteed: dem Netzwerk, das Vertrauen zwischen Unternehmen und Agenten schafft.

- **Legen Sie Ihre Geschäftsregeln fest**: wem Sie den Kauf erlauben, bis zu welchem Betrag, welche Kategorien Sie Agenten nicht anbieten möchten, Preisgrenzen, Bestandsniveaus zum Schutz vor potenziell betrügerischen Agenten, und mehr.
- **Manipulationssichere Belege**: Wir erstellen elektronisch signierte und kryptografisch manipulationssichere Belege, die als Nachweis der tatsächlichen Transaktion im Streitfall dienen. Kompatibel mit eIDAS (EU, UK) und eSIGN (USA).
- **Agenten-Analytics**: Sehen Sie Statistiken zu Agentenkäufen — wie viel sie ausgeben, welche Produkte sie kaufen und wie oft.
- **Agenten-Blockierung**: Blockieren Sie potenziell gefährliche oder problematische Agenten.
- **Digitale Währungen**: Ermöglicht Käufe in digitalen Währungen dank des X402-Protokolls.
- **Peer-to-Peer-Transaktionen**: Ermöglicht direkten Peer-to-Peer-Handel zwischen Agenten und Händlern.

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

Jede Agenten-Transaktion erzeugt einen kryptografisch signierten **Trust Receipt** — einen manipulationssicheren Nachweis (eIDAS-/eSIGN-kompatibel), der unter **Meine Verkäufe → KI-Verkäufe** aufgeführt wird. Klicke auf eine Zeile, um alle Details zu sehen (Agenten-ID, aufgerufenes Tool, Input-/Output-Hashes, JWS) und den Beleg als ZIP herunterzuladen, um ihn im Streitfall als Nachweis vorzulegen.

## Funktionen

Trusteed AgenticTools vereint Trust Center, Merchant Center, agentische MCP-Tools und Checkout-Enforcement in einem einzigen PrestaShop-Modul.

- **Trust Center** — signierte Trust Receipts, Signaturschlüssel, Audit-Log, Trust-Score-Aufschlüsselung
- **Merchant Center** — Bestellungen, Zahlungsmethoden, Agenten, Checkout-Regeln, Zertifizierungs- & NLWeb-Status
- **5 native MCP-Tools** für das PrestaShop-MCP-Server-Add-on (Marketplace-ID 96617): `trusteed_sign_trust_receipt`, `trusteed_verify_agent_signature`, `trusteed_dispatch_payment_acp`, `trusteed_dispatch_payment_ap2`, `trusteed_dispatch_payment_x402` — Agenten (Claude Desktop usw.) können Belege signieren und Zahlungen direkt aus PrestaShop heraus auslösen
- **Checkout-Enforcement** — Händlerregeln (maximaler Bestellbetrag, gesperrte Länder, Geschäftszeiten und mehr) gelten bei jedem Checkout, ob mit oder ohne Agent
- **Offline-Sicherheitsventil-Evaluator** — wendet dieselben universellen Regeln lokal an, wenn die Remote-Regel-API nicht erreichbar ist, statt eines pauschalen Erlauben/Blockieren
- **Selbstbedienungs-Auto-Registrierung** — Ein-Klick-Registrierung des Shops; Zugangsdaten können auch manuell eingefügt werden
- **Fail-closed als Standard** — Enforcement erlaubt bei Fehlkonfiguration niemals stillschweigend

## Kompatibilität

| Komponente | Unterstützt |
|-----------|-----------|
| PrestaShop | 8.0.0 – 9.99.99 |
| PHP | 8.1+ |

## Voraussetzungen

- PrestaShop 8.0.0 oder neuer
- PHP 8.1 oder neuer
- Ein Trusteed-Konto — [kostenlos registrieren auf trusteed.xyz](https://trusteed.xyz)

## Installation

### Manueller Upload

1. Laden Sie den Quellcode von der aktuellen [Releases-Seite](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases) herunter (oder führen Sie `git clone` für dieses Repository aus).
2. Benennen Sie den extrahierten obersten Ordner in `trusteed` um (PrestaShop verlangt, dass der Ordnername mit dem technischen Namen des Moduls übereinstimmt) und komprimieren Sie ihn erneut als `.zip`.
3. In Ihrem PrestaShop-**Back Office**: **Module → Modul-Manager → Modul hochladen**.
4. Wählen Sie die soeben erstellte `trusteed.zip` aus und klicken Sie auf **Dieses Modul hochladen**.
5. Klicken Sie auf **Konfigurieren**.

### Über Composer (für die Entwicklung)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git trusteed
cd trusteed
composer install --no-dev --optimize-autoloader
```
Laden Sie anschließend den resultierenden Ordner `trusteed/` wie oben beschrieben als `.zip` hoch. Das Modul enthält außerdem einen PSR-4-Fallback-Autoloader und funktioniert daher auch ohne `vendor/`-Verzeichnis (composer install ist optional, nicht erforderlich).

## Konfiguration

1. Melden Sie sich in Ihrem PrestaShop-**Back Office** an.
2. Gehen Sie zu **Module → Trusteed AgenticTools → Konfigurieren**.
3. Klicken Sie entweder auf **Diesen Shop automatisch registrieren** (Ein-Klick-Registrierung, die Merchant ID und Secret automatisch ausfüllt), oder fügen Sie Ihre **Merchant ID** und Ihr **S2S-Secret** manuell von [app.trusteed.xyz/settings](https://app.trusteed.xyz/settings) ein.
4. Speichern — das Modul testet die Konnektivität und beginnt mit der Synchronisierung der Enforcement-Regeln.

### Konfigurationsschlüssel

| Schlüssel | Standard | Zweck |
|-----|---------|-------------|
| `TRUSTEED_API_BASE` | `https://api.trusteed.xyz` | Endpunkt des Trusteed-Backends |
| `TRUSTEED_CEL_MERCHANT_ID` | _(leer)_ | Von Trusteed ausgestellte Merchant ID |
| `TRUSTEED_EMBED_S2S_SECRET` | _(leer)_ | Server-zu-Server-Secret für die Embed-/Enforcement-API |
| `TRUSTEED_BOOTSTRAP_TOKEN` | _(leer)_ | Veraltetes Embed-Bootstrap-Token (durch Auto-Registrierung ersetzt) |

## Admin-Seiten

Nach der Installation erscheint ein **Trusteed**-Menü in der Seitenleiste des PrestaShop-Back-Office:

| Seite | Beschreibung |
|------|-------------|
| Start | Übersicht über Reputation und aktuelle Verkäufe |
| Trust Center | Signierte Belege, Signaturschlüssel, Audit-Log, Trust Score |
| Merchant Center | Bestellungen, Zahlungsmethoden, Agenten, Zertifizierungen, NLWeb |
| Meine Verkäufe | Bestellliste und KI-Trust-Receipts |
| Regeln | Checkout-Enforcement-Regeln |
| Agenten | Verbundene Agenten-Identitäten |
| Sicherheit | Audit-Log und Anomalie-Warnungen |
| Config | Moduleinstellungen und Auto-Registrierung |

## FAQ

**Welche Daten werden gesendet?** Nur das, was Enforcement-Regeln und Trust Receipts erfordern (Bestellsummen, Land, Agenten-Identität). Keine Kartenzahlungsdaten laufen jemals über Trusteed. Die gesamte Kommunikation erfolgt über HTTPS.

**Welche Agenten werden unterstützt?** Jeder über das PrestaShop-MCP-Server-Add-on (Marketplace-ID 96617) verbundene Agent, einschließlich Claude Desktop und anderer MCP-kompatibler Clients.

**Verlangsamt es meinen Shop?** Nein. Checkout-Enforcement läuft synchron nur bei der Bestellvalidierung, mit einem lokalen Offline-Fallback, wenn die Remote-API nicht erreichbar ist.

## Änderungsprotokoll

### 2.0.0

**Wichtig:** Dieses Release ersetzt den fälschlicherweise unter `v1.0.0` in diesem Repository veröffentlichten Inhalt — es war ein anderes, eigenständiges Modul ("Trusteed Trust Center") anstelle dieses Checkout-Enforcement- + AgenticTools-Moduls veröffentlicht worden. Dies ist das erste korrekte Release.

- **Fix** — Checkout-Enforcement wurde bei organischen (agentenlosen) Checkouts vollständig übersprungen: Händlerregeln wie maximaler Bestellbetrag, gesperrte Länder und Geschäftszeiten griffen nur, wenn ein Agenten-Token vorhanden war. Diese Regeln gelten jetzt bei jedem Checkout, unabhängig von der Anwesenheit eines Agenten.
- **Hinzugefügt** — ein Offline-Sicherheitsventil-Evaluator, der dieselben universellen Händlerregeln lokal anwendet, wenn die Remote-Regel-API nicht erreichbar ist.
- **Hinzugefügt** — Selbstbedienungs-Auto-Registrierung (Ein-Klick-Registrierung des Shops, zusätzlich zum bestehenden manuellen Ablauf zum Einfügen der Zugangsdaten).
- Vollständiges technisches Rebranding von `mcpwebstore`/`Mcpwebstore` zu `trusteed`/`Trusteed`: PSR-4-Namespace, technischer Modulname, Konfigurationskonstanten und die Namen der 5 MCP-Tools, die Agenten aufrufen.

## Support

- Support-E-Mail: support@trusteed.xyz
- GitHub Issues: [github.com/Trusteedxyz/agentic-commerce-prestashop/issues](https://github.com/Trusteedxyz/agentic-commerce-prestashop/issues)

## Lizenz

MIT. Vollständigen Text siehe [LICENSE](LICENSE).
