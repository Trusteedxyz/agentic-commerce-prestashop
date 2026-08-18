[English](README.md) | [Español](README.es.md) | **Français** | [Deutsch](README.de.md)

# Trusteed AgenticTools pour PrestaShop

Permettez aux nouveaux acheteurs en ligne, les agents IA, d'effectuer des achats dans votre boutique de manière sûre et fiable grâce à Trusteed : le réseau qui instaure la confiance entre les entreprises et les agents.

- **Définissez vos règles commerciales** : qui vous autorisez à acheter, jusqu'à quel montant, quelles catégories vous ne souhaitez pas proposer aux agents, fixez des limites de prix, maintenez des niveaux de stock pour vous protéger d'agents potentiellement frauduleux, et plus encore.
- **Reçus infalsifiables** : nous générons des reçus signés électroniquement et infalsifiables cryptographiquement qui servent de preuve de la transaction réelle en cas de litige. Compatible avec les réglementations eIDAS (UE, Royaume-Uni) et eSIGN (États-Unis).
- **Analyse des agents** : consultez les statistiques des achats des agents — combien ils dépensent, quels produits ils achètent et à quelle fréquence.
- **Blocage d'agents** : bloquez les agents potentiellement dangereux ou problématiques.
- **Monnaies numériques** : permet les achats en monnaies numériques grâce au protocole X402.
- **Transactions pair-à-pair** : permet le commerce direct pair-à-pair entre agents et commerçants.

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

Chaque transaction d'un agent génère un **reçu de confiance** — un enregistrement signé par un JWS Ed25519, de sorte que toute modification ultérieure de son contenu reste détectable — répertorié sous **Mes Ventes → Ventes IA**. Cliquez sur une ligne pour voir le détail complet (ID de l'agent, outil appelé, hachages d'entrée/sortie, JWS) et télécharger le reçu au format ZIP afin de le conserver comme votre propre preuve de ce que l'agent a fait. Les reçus reposent sur les mêmes formats de signature que ceux sur lesquels s'appuient eIDAS et eSIGN, mais ce ne sont **pas** des signatures ni des cachets électroniques qualifiés : aucun certificat délivré par un QTSP ni horodatage qualifié ne se trouve derrière eux aujourd'hui, ils ne bénéficient donc à eux seuls d'aucune présomption de validité juridique.

## Fonctionnalités

Trusteed AgenticTools regroupe Trust Center, Merchant Center, les outils agentiques MCP et l'application des règles de commande (enforcement) au sein d'un seul module PrestaShop.

- **Trust Center** — reçus de confiance signés, clés de signature, journal d'audit, détail du score de confiance
- **Merchant Center** — commandes, moyens de paiement, agents, règles de commande, statut de certification & NLWeb
- **5 outils MCP natifs** pour l'extension PrestaShop MCP Server (marketplace ID 96617) : `trusteed_sign_trust_receipt`, `trusteed_verify_agent_signature`, `trusteed_dispatch_payment_acp`, `trusteed_dispatch_payment_ap2`, `trusteed_dispatch_payment_x402` — les agents (Claude Desktop, etc.) peuvent signer des reçus et déclencher des paiements directement depuis PrestaShop
- **Application des règles au checkout** — les règles du commerçant (montant maximal, pays bloqués, horaires d'ouverture, et plus) s'appliquent à chaque commande, agent ou humain
- **Évaluateur de secours hors ligne** — applique les mêmes règles universelles localement lorsque l'API distante est injoignable, au lieu d'un simple repli autoriser/bloquer
- **Auto-enregistrement en libre-service** — enregistrement de la boutique en un clic ; les identifiants peuvent aussi être saisis manuellement
- **Comportement configurable en cas de panne** — lorsque l'API de règles est injoignable et qu'aucun instantané local récent n'existe, le module est livré en mode `balanced` : le checkout passe et cette autorisation par défaut est journalisée. Réglez `TRUSTEED_CEL_FALLBACK_MODE` sur `strict` pour le bloquer à la place.

## Compatibilité

| Composant | Plage déclarée | Réellement vérifié sur |
|-----------|------------------|---------------------------|
| PrestaShop | 8.0.0 – 9.99.99 (`ps_versions_compliancy` dans `trusteed.php`) | 8.2.0 (toutes les captures d'écran de ce README ; pas encore de tests E2E automatisés sur d'autres versions) |
| PHP | 8.1+ | 8.1, 8.2 |

La plage 8.0.0–9.99.99 est ce que le module *déclare* au gestionnaire de modules de
PrestaShop — elle n'a pas été testée de bout en bout au-delà de 8.2.0. Il n'existe pas
encore de CI exécutant PHPUnit sur plusieurs versions de PrestaShop ; considérez le support
de la 9.x comme non vérifié jusqu'à ce que ce soit le cas.

## Prérequis

- PrestaShop 8.0.0 ou supérieur
- PHP 8.1 ou supérieur
- Un compte Trusteed — [inscrivez-vous gratuitement sur trusteed.xyz](https://trusteed.xyz)

## Installation

### Téléversement manuel

1. **Téléchargez le `.zip` installable** depuis la dernière Release GitHub :
   [**⬇ Télécharger la dernière version**](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases/latest)
   — ou parcourez toutes les versions sur la [page des Releases](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases).
2. Dans votre **Back Office** PrestaShop : **Modules → Gestionnaire de modules → Téléverser un module**.
3. Sélectionnez le `.zip` téléchargé et cliquez sur **Téléverser ce module**.
4. Cliquez sur **Configurer**.

### Depuis les sources (compiler le zip vous-même)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git
cd agentic-commerce-prestashop
bash bin/build-zip.sh   # génère dist/trusteed-agentic-commerce-prestashop-<version>.zip
```

Le module intègre un autoloader PSR-4 de secours pour l'espace de noms `Trusteed\`, il fonctionne donc correctement même sans dossier `vendor/` (le script de build n'en inclut pas — `composer install` est facultatif, pas obligatoire).

### Via Composer (facultatif, pour l'outillage IDE / le développement local)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git trusteed
cd trusteed
composer install --no-dev --optimize-autoloader
```
Téléversez ensuite le dossier `trusteed/` obtenu sous forme de `.zip` comme décrit ci-dessus. Non requis pour la production — voir la note sur l'autoloader de secours ci-dessus.

## Configuration

1. Connectez-vous à votre **Back Office** PrestaShop.
2. Allez dans **Modules → Trusteed AgenticTools → Configurer**.
3. Cliquez sur **Auto-enregistrer cette boutique** (enregistrement en un clic qui remplit automatiquement le Merchant ID et le secret), ou saisissez manuellement votre **Merchant ID** et votre **secret S2S** depuis [trusteed.xyz/dashboard/settings](https://trusteed.xyz/dashboard/settings).
4. Enregistrez — les valeurs sont validées (point de terminaison HTTPS, secret de 64 caractères hexadécimaux) puis stockées. Enregistrer ne contacte **pas** Trusteed ; seul **Auto-enregistrer cette boutique** effectue un appel réel.

### Clés de configuration

| Clé | Par défaut | Rôle |
|-----|---------|-------------|
| `TRUSTEED_API_BASE` | `https://api.trusteed.xyz` | Point de terminaison du backend Trusteed |
| `TRUSTEED_CEL_MERCHANT_ID` | _(vide)_ | Merchant ID délivré par Trusteed |
| `TRUSTEED_EMBED_S2S_SECRET` | _(vide)_ | Secret serveur-à-serveur pour l'API embed/enforcement |
| `TRUSTEED_BOOTSTRAP_TOKEN` | _(vide)_ | Jeton embed-bootstrap hérité (remplacé par l'auto-enregistrement) |
| `TRUSTEED_CEL_ENABLED` | `0` | Interrupteur général de l'application des règles au checkout. Tant qu'il vaut `0`, aucune règle n'est évaluée sur aucun checkout |
| `TRUSTEED_CEL_INSTALLATION_ID` | _(vide)_ | ID d'installation pour l'instantané signé des règles |
| `TRUSTEED_CEL_HMAC_SECRET` | _(vide)_ | Secret HMAC pour les appels d'instantané et d'évaluation des règles |
| `TRUSTEED_CEL_FALLBACK_MODE` | `balanced` | Comportement lorsque l'API de règles est injoignable et qu'aucun instantané local n'existe : `balanced` et `permissive` laissent passer le checkout et journalisent l'autorisation par défaut, `strict` le bloque |

L'application des règles reste totalement inerte jusqu'à ce que `TRUSTEED_CEL_ENABLED` vaille `1` **et** que les trois clés `TRUSTEED_CEL_MERCHANT_ID`, `TRUSTEED_CEL_INSTALLATION_ID` et `TRUSTEED_CEL_HMAC_SECRET` soient renseignées — s'il en manque une, le module laisse passer tous les checkouts sans évaluer une seule règle.

## Pages d'administration

Après l'installation, un menu **Trusteed** apparaît dans la barre latérale du Back Office PrestaShop. Seul l'anglais dispose de libellés traduits : dans toutes les autres langues, y compris le français, la barre latérale affiche les libellés espagnols reproduits ci-dessous.

| Page | Description |
|------|-------------|
| Inicio | Aperçu de la réputation et des ventes récentes |
| ¿Cómo va mi tienda? | Reçus signés, clés de signature, journal d'audit, score de confiance |
| Centro de comercio | Commandes, moyens de paiement, agents, certifications, NLWeb |
| Mis ventas | Liste des commandes et reçus de confiance IA |
| Mis Reglas | Règles d'application au checkout |
| Seguridad | Journal d'audit et alertes d'anomalies |
| Agentes | Identités des agents connectés |
| Configuración | Paramètres du module et auto-enregistrement |

## FAQ

**Quelles données sont envoyées ?** Uniquement ce que nécessitent les règles d'application et les reçus de confiance (montants des commandes, pays, identité de l'agent). Aucune donnée de carte bancaire ne transite jamais par Trusteed. Toutes les communications utilisent HTTPS.

**Quels agents sont pris en charge ?** Tout agent connecté via l'extension PrestaShop MCP Server (marketplace ID 96617), y compris Claude Desktop et d'autres clients compatibles MCP.

**Cela ralentit-il ma boutique ?** Non. L'application des règles au checkout s'exécute de manière synchrone uniquement lors de la validation de la commande, avec un repli local hors ligne lorsque l'API distante est injoignable.

## Historique des versions

### 2.1.1

- **Corrigé** — le bundle du panneau d'administration (`views/js/admin-spa.js`) était distribué non minifié : 869 Ko / 25 064 lignes au lieu des 490 Ko / 41 lignes que produit réellement la commande de build documentée (`pnpm run build:ps`). Sa provenance ne pouvait pas être vérifiée. Reconstruit depuis la source.
- **Corrigé** — la règle R047 (demander confirmation à l'acheteur à partir d'un montant) n'avait pas de champ de formulaire dans le panneau d'administration ; ses paramètres existaient dans le schéma mais ne pouvaient être définis que via l'API.

### 2.1.0

- **Correctif de sécurité** — le vérificateur de jetons d'agent traitait `exp`, `iat` et `nonce` comme facultatifs. Toutes les protections qui en dépendent — expiration, plafond de durée de vie de 330s, anti-rejeu — reposaient sur un `isset`, si bien qu'un jeton omettant simplement le claim échappait au contrôle : sans `exp` il était valable indéfiniment, et sans `nonce` rien n'était dédupliqué. Les trois sont désormais obligatoires (`nonce` de 16 à 64 caractères), conformément au schéma canonique du jeton.
- **Correctif de sécurité** — un `iat` dans le futur est désormais rejeté. Combiné au plafond de 330s, il donnait une fenêtre glissante : un `iat` avancé d'une heure achetait une heure de validité en temps réel alors même que `exp - iat` restait dans le plafond.
- **Correctif** — la règle R036 (valeur maximale par ligne) lisait son plafond dans un paramètre nommé `maxCents`, copié de R035. Le nom canonique est `maxCentsPerLine`, seul accepté par le schéma strict du panneau marchand : la règle ne pouvait jamais se déclencher.
- **Supprimé** — la branche R007 de l'évaluateur hors ligne. Elle bloquait sur `trustScore < 0.3` sous un commentaire annonçant un « contrôle de pays à risque » : elle ne faisait donc ni ce que disait le commentaire, ni ce que signifie le nom canonique de la règle. Le vrai signal de R007 est l'état d'abus inter-marchands, qui vit dans la base de données du backend et reste hors de portée du chemin hors ligne — renvoyer ALLOW ici n'est pas un fail-open sur un signal disponible, c'est un signal qui n'existe pas dans ce contexte. Le verdict faisant autorité pour R007 vient du serveur. Si vous vouliez le seuil de confiance, la règle est R006 ; si vous vouliez le pays, R014/R019.
- **Nouveauté** — le module déclare désormais quels signaux de panier cette installation sait projeter (`POST /api/v1/enforcement/capabilities`, signé en HMAC, envoyé une fois par version du module depuis un hook back-office déjà enregistré). Sans cela, une règle dont le signal n'arrive jamais renvoie `NO_SIGNAL` à chaque paiement : elle passe en silence, et le marchand voit une règle en ENFORCE qui ne bloque rien.

### 2.0.1

- **Correctif** — bundle SPA d'administration reconstruit (preuve de litige Phase A : la vraie liste de reçus est désormais montée dans Mes Ventes, comme sur Magento/WooCommerce).
- **Ajout** — script d'empaquetage `bin/build-zip.sh` ; le `.zip` installable est désormais publié en tant qu'asset de Release GitHub, au lieu d'exiger que le marchand le compile lui-même.

### 2.0.0

**Important :** cette version remplace le contenu publié par erreur sous `v1.0.0` dans ce dépôt — un module distinct et autonome ("Trusteed Trust Center") avait été publié à la place de ce module d'application des règles de commande + AgenticTools. Il s'agit de la première version correcte.

- **Correction** — l'application des règles au checkout était entièrement ignorée pour les commandes organiques (sans agent) : les règles du commerçant telles que le montant maximal, les pays bloqués et les horaires d'ouverture ne s'exécutaient jamais en l'absence d'un jeton d'agent. Ces règles s'appliquent désormais à chaque commande, agent ou humain.
- **Ajout** — un évaluateur de secours hors ligne qui applique localement les mêmes règles universelles lorsque l'API distante d'évaluation des règles est injoignable.
- **Ajout** — auto-enregistrement en libre-service (enregistrement de la boutique en un clic, en plus du flux manuel existant de saisie des identifiants).
- Rebranding technique complet de `mcpwebstore`/`Mcpwebstore` vers `trusteed`/`Trusteed` : namespace PSR-4, nom technique du module, constantes de configuration et noms des 5 outils MCP appelés par les agents.

## Support

- Email de support : support@trusteed.xyz
- Issues GitHub : [github.com/Trusteedxyz/agentic-commerce-prestashop/issues](https://github.com/Trusteedxyz/agentic-commerce-prestashop/issues)

## Licence

MIT. Voir [LICENSE](LICENSE) pour le texte complet.
