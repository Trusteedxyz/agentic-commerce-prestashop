[English](README.md) | [Español](README.es.md) | **Français** | [Deutsch](README.de.md)

# Trusteed AgenticTools pour PrestaShop

Les agents IA sont un nouveau type d'acheteur en ligne. Avec Trusteed, le réseau qui met en relation les entreprises et les agents, ils peuvent acheter dans votre boutique selon vos conditions.

- Définissez vos règles métier : qui peut acheter, jusqu'à quel montant, quelles catégories vous ne proposez pas aux agents, des limites de prix, des niveaux de stock qui vous protègent des agents frauduleux, et plus encore.
- Recevez des reçus signés. Chaque transaction produit un reçu signé cryptographiquement, dont toute altération est détectable, que vous pouvez utiliser comme preuve de l'achat en cas de litige. Aligné sur eIDAS (UE) et sur eSIGN (États-Unis).
- Voyez ce que font les agents : combien ils dépensent, ce qu'ils achètent et à quelle fréquence.
- Bloquez les agents qui semblent dangereux ou qui posent problème.
- Acceptez des achats en monnaies numériques grâce au protocole X402.
- Laissez agents et marchands échanger directement, de pair à pair.

## Captures d'écran

| Accueil | Score de confiance | Merchant Center — Commandes |
|------|------------|--------------------------|
| ![Accueil](screenshots/01-home-dashboard.png) | ![Score](screenshots/02-trust-score-breakdown.png) | ![Commandes](screenshots/03-merchant-center-orders.png) |

| Merchant Center — Moyens de paiement | Merchant Center — Certifications | Mes Ventes |
|----------------------------|-----------------------------------|----------|
| ![Paiements](screenshots/03b-merchant-center-payments.png) | ![Certifications](screenshots/04-merchant-center-certifications.png) | ![Mes Ventes](screenshots/05-my-sales-orders.png) |

| Reçus de confiance (Mes Ventes → Ventes IA) | Agents |
|---------------------------------------|--------|
| ![Reçus](screenshots/06-my-sales-ai-receipts.png) | ![Agents](screenshots/07-agents.png) |

| Détail du reçu — téléchargement en ZIP |
|------------------------------------------|
| ![Téléchargement du reçu](screenshots/08-my-sales-receipt-download.png) |

Chaque transaction d'un agent génère un reçu de confiance signé, un enregistrement dont toute altération est détectable (aligné sur eIDAS et eSIGN) répertorié sous **Mes Ventes → Ventes IA**. Cliquez sur une ligne pour voir le détail : ID de l'agent, outil appelé, hachages d'entrée et de sortie, JWS. Vous pouvez aussi télécharger le reçu au format ZIP et le conserver en cas de litige.

## Fonctionnalités

Trusteed AgenticTools réunit Trust Center, Merchant Center, les outils agentiques MCP et l'application des règles au checkout dans un seul module PrestaShop.

- Trust Center : reçus de confiance signés, clés de signature, journal d'audit, détail du score de confiance.
- Merchant Center : commandes, moyens de paiement, agents, règles de checkout, statut de certification et NLWeb.
- 5 outils MCP natifs pour l'extension PrestaShop MCP Server (marketplace ID 96617) : `trusteed_sign_trust_receipt`, `trusteed_verify_agent_signature`, `trusteed_dispatch_payment_acp`, `trusteed_dispatch_payment_ap2`, `trusteed_dispatch_payment_x402`. Les agents (Claude Desktop et d'autres) peuvent signer des reçus et déclencher des paiements directement depuis PrestaShop.
- Application des règles au checkout : les règles du marchand (montant maximal, pays bloqués, horaires d'ouverture et plus) s'appliquent à chaque checkout, qu'il vienne d'un agent ou d'un humain.
- Évaluateur de soupape de sécurité hors ligne : applique localement les mêmes règles universelles lorsque l'API distante des règles est inaccessible, au lieu d'un repli général qui autorise ou bloque tout.
- Auto-enregistrement en libre-service : enregistrement de la boutique auprès de Trusteed en un clic. Vous pouvez aussi saisir vos identifiants manuellement.
- Comportements par défaut fail-closed : l'application des règles n'autorise jamais en silence en cas de mauvaise configuration.

## Compatibilité

| Composant | Compatible |
|-----------|-----------|
| PrestaShop | 8.0.0 – 9.99.99 |
| PHP | 8.1+ |

## Prérequis

- PrestaShop 8.0.0 ou supérieur
- PHP 8.1 ou supérieur
- Un compte Trusteed ([inscrivez-vous gratuitement sur trusteed.xyz](https://trusteed.xyz))

## Installation

### Téléversement manuel

1. **Téléchargez le `.zip` installable** depuis la dernière Release GitHub :
   [**⬇ trusteed-agentic-commerce-prestashop-2.0.1.zip**](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases/latest/download/trusteed-agentic-commerce-prestashop-2.0.1.zip)
   ou parcourez toutes les versions sur la [page des Releases](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases).
2. Dans votre **Back Office** PrestaShop : **Modules → Gestionnaire de modules → Téléverser un module**.
3. Sélectionnez le `.zip` téléchargé et cliquez sur **Téléverser ce module**.
4. Cliquez sur **Configurer**.

### Depuis les sources (compiler le zip vous-même)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git
cd agentic-commerce-prestashop
bash bin/build-zip.sh   # génère dist/trusteed-agentic-commerce-prestashop-<version>.zip
```

Le module intègre un autoloader PSR-4 de secours pour l'espace de noms `Trusteed\`, il fonctionne donc même sans dossier `vendor/`. Le script de build n'en inclut pas, et `composer install` est facultatif.

### Via Composer (facultatif, pour l'outillage IDE / le développement local)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git trusteed
cd trusteed
composer install --no-dev --optimize-autoloader
```
Téléversez ensuite le dossier `trusteed/` obtenu sous forme de `.zip`, comme décrit plus haut. Vous pouvez l'ignorer pour une installation de production, voir la note sur l'autoloader de secours ci-dessus.

## Configuration

1. Connectez-vous à votre **Back Office** PrestaShop.
2. Allez dans **Modules → Trusteed AgenticTools → Configurer**.
3. Cliquez sur **Auto-enregistrer cette boutique** (enregistrement en un clic qui remplit automatiquement le Merchant ID et le secret), ou saisissez manuellement votre **Merchant ID** et votre **secret S2S** depuis [app.trusteed.xyz/settings](https://app.trusteed.xyz/settings).
4. Enregistrez. Le module teste la connexion et commence à synchroniser les règles d'application.

### Clés de configuration

| Clé | Par défaut | Rôle |
|-----|---------|-------------|
| `TRUSTEED_API_BASE` | `https://api.trusteed.xyz` | Point de terminaison du backend Trusteed |
| `TRUSTEED_CEL_MERCHANT_ID` | _(vide)_ | Merchant ID délivré par Trusteed |
| `TRUSTEED_EMBED_S2S_SECRET` | _(vide)_ | Secret serveur-à-serveur pour l'API embed/enforcement |
| `TRUSTEED_BOOTSTRAP_TOKEN` | _(vide)_ | Jeton embed-bootstrap hérité (remplacé par l'auto-enregistrement) |

## Pages d'administration

Après l'installation, un menu **Trusteed** apparaît dans la barre latérale du Back Office PrestaShop :

| Page | Description |
|------|-------------|
| Accueil | Aperçu de la réputation et des ventes récentes |
| Comment va ma boutique ? (Trust Center) | Reçus signés, clés de signature, journal d'audit, score de confiance |
| Merchant Center | Commandes, moyens de paiement, agents, certifications, NLWeb |
| Mes ventes | Liste des commandes et reçus de confiance IA |
| Mes Règles | Règles d'application au checkout |
| Sécurité | Journal d'audit et alertes d'anomalies |
| Agents | Identités des agents connectés |
| Paramètres | Paramètres du module et auto-enregistrement |

## FAQ

**Quelles données sont envoyées ?** Uniquement ce dont ont besoin les règles d'application et les reçus de confiance : montants des commandes, pays et identité de l'agent. Aucune donnée de carte bancaire ne transite jamais par Trusteed. Toutes les communications passent par HTTPS.

**Quels agents sont pris en charge ?** Tout agent connecté via l'extension PrestaShop MCP Server (marketplace ID 96617), y compris Claude Desktop et d'autres clients compatibles MCP.

**Cela ralentit-il ma boutique ?** Non. L'application des règles au checkout ne s'exécute de façon synchrone qu'à la validation de la commande, avec un repli local hors ligne lorsque l'API distante est inaccessible.

## Historique des versions

### 2.0.1

- Correctif : bundle SPA d'administration reconstruit (preuve de litige Phase A : la vraie liste de reçus est désormais montée dans Mes Ventes, comme sur Magento et WooCommerce).
- Ajout : script d'empaquetage `bin/build-zip.sh`. Le `.zip` installable est désormais publié comme asset de Release GitHub, si bien que les marchands n'ont plus à le compiler eux-mêmes.

### 2.0.0

**Important :** cette version remplace un contenu publié par erreur sous `v1.0.0` dans ce dépôt. Un autre module, autonome (« Trusteed Trust Center »), avait été livré à la place de ce module d'application des règles au checkout et d'AgenticTools. C'est la première version correcte.

- Correctif : l'application des règles au checkout était entièrement ignorée pour les checkouts organiques (sans agent). Les règles du marchand, comme le montant maximal, les pays bloqués et les horaires d'ouverture, ne s'exécutaient que si un jeton d'agent était présent. Ces règles s'appliquent désormais à chaque checkout, qu'un agent soit présent ou non.
- Ajout : un évaluateur de soupape de sécurité hors ligne qui applique localement les mêmes règles universelles du marchand lorsque l'API distante d'évaluation des règles est inaccessible.
- Ajout : auto-enregistrement en libre-service (enregistrement de la boutique en un clic, en plus de la saisie manuelle des identifiants déjà disponible).
- Rebranding technique complet de `mcpwebstore`/`Mcpwebstore` vers `trusteed`/`Trusteed` : espace de noms PSR-4, nom technique du module, constantes de configuration et noms des 5 outils MCP que les agents appellent.

## Support

- E-mail support : support@trusteed.xyz
- Issues GitHub : [github.com/Trusteedxyz/agentic-commerce-prestashop/issues](https://github.com/Trusteedxyz/agentic-commerce-prestashop/issues)

## Licence

MIT. Voir [LICENSE](LICENSE) pour le texte complet.
