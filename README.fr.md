[English](README.md) | [Español](README.es.md) | **Français** | [Deutsch](README.de.md)

# Trusteed Agentic Commerce for PrestaShop

Permettez aux nouveaux acheteurs en ligne, les agents IA, d'effectuer des achats dans votre boutique de manière sûre et fiable grâce à Trusteed : le réseau qui instaure la confiance entre les entreprises et les agents.

- **Définissez vos règles métier** : qui vous autorisez à acheter, jusqu'à quel montant, quelles catégories vous ne souhaitez pas proposer aux agents, fixez des limites de prix, maintenez des niveaux de stock pour vous protéger contre d'éventuels agents frauduleux, et bien plus encore.
- **Reçus infalsifiables** : nous générons des reçus signés électroniquement et rendus infalsifiables par cryptographie, qui servent de preuve de la transaction réelle en cas de litige. Compatible avec les réglementations eIDAS (UE, Royaume-Uni) et eSIGN (États-Unis).
- **Analyse des agents** : consultez des statistiques sur les achats des agents — combien ils dépensent, quels produits ils achètent, et à quelle fréquence.
- **Blocage des agents** : bloquez les agents potentiellement dangereux ou problématiques.
- **Monnaies numériques** : permet les achats en monnaies numériques grâce au protocole X402.
- **Transactions de pair à pair** : permet le commerce direct de pair à pair entre agents et marchands.

## Captures d'écran

| Tableau de bord | Score de confiance |
|-----------|------------|
| ![Dashboard](screenshots/01-home-dashboard.png) | ![Trust Score](screenshots/02-trust-score-breakdown.png) |

| Commandes | Paiements |
|--------|---------|
| ![Orders](screenshots/03-merchant-center-orders.png) | ![Payments](screenshots/03b-merchant-center-payments.png) |

| Certifications | Mes ventes |
|---------------|---------|
| ![Certifications](screenshots/04-merchant-center-certifications.png) | ![My Sales](screenshots/05-my-sales-orders.png) |

| Reçus IA | Agents |
|------------|-------|
| ![AI Receipts](screenshots/06-my-sales-ai-receipts.png) | ![Agents](screenshots/07-agents.png) |

## Fonctionnalités

- Trust Center : reçus, clés de signature, journal d'audit, score de confiance
- Merchant Center : commandes, moyens de paiement, agents, configuration du checkout, certification + NLWeb
- Assistant d'onboarding (4 étapes — aucune page de configuration nécessaire)
- Accès au Trust Center réservé aux super-administrateurs (relais de bootstrap fail-closed), avec le
  périmètre `all_shops` du super-administrateur dérivé côté serveur
- Badge de confiance `displayBackOfficeTop` sur chaque page du back-office

## Prérequis

- PrestaShop 8.0.0 – 9.99.99
- PHP 8.1+
- Un compte Trusteed sur app.trusteed.xyz

## Installation

### Option 1 : PrestaShop Addons (recommandé)

[Bientôt disponible]

### Option 2 : Téléversement manuel (recommandé)

1. **Téléchargez le `.zip` installable** depuis la dernière GitHub Release :
   [**⬇ trusteed-agentic-commerce-prestashop-1.0.0.zip**](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases/latest/download/trusteed-agentic-commerce-prestashop-1.0.0.zip)
   — ou parcourez toutes les versions sur la [page des Releases](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases).
2. Dans votre Back Office PS : **Modules → Gestionnaire de modules → Téléverser un module**.
3. Téléversez le fichier téléchargé `trusteed-agentic-commerce-prestashop-1.0.0.zip`.
4. Cliquez sur **Configurer** → l'assistant de configuration s'ouvre automatiquement.

### Option 3 : Docker / développement

```bash
docker compose -f e2e/docker/ps-staging.yml up -d
docker compose -f e2e/docker/ps-staging.yml exec prestashop \
  php bin/console prestashop:module install trusteed
```

## Assistant de configuration rapide

Lors de la première installation (ou lorsque `AGENTICMCP_MERCHANT_ID` n'est pas défini), le module redirige vers l'assistant de configuration rapide :

1. **Bienvenue** — confirmez les prérequis (compte + sortie HTTPS)
2. **Connexion** — ouvrez le Portail Trusteed, puis allez dans "Connecter une boutique → PrestaShop"
3. **Identifiants** — collez votre Merchant ID et votre Bootstrap Secret
4. **Test** — cliquez sur "Vérifier les identifiants" pour valider la connexion

## Multiboutique (PrestaShop Multistore)

- Chaque boutique dispose d'un contexte d'embed distinct (claim `id_shop` limité).
- **Seuls les super-administrateurs (`id_profile === _PS_ADMIN_PROFILE_`) peuvent ouvrir le Trust
  Center.** Le relais de bootstrap échoue en mode fermé (HTTP 403) pour tout autre
  profil : les utilisateurs qui ne sont pas super-administrateurs n'obtiennent donc jamais de jeton d'embed —
  il n'existe pas de vue restreinte du Trust Center pour un "employé standard".
- Pour les super-administrateurs, la liste `all_shops` est dérivée côté serveur à partir de la
  liste réelle des boutiques (`Shop::getCompleteListOfShopsID()`), jamais du contexte back-office
  contrôlé par l'appelant, et la SPA affiche un sélecteur de boutique déroulant.

## Fichiers du module

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

## Notes de sécurité

- Le bootstrap secret est stocké dans la table `Configuration` de PS (chiffré au niveau BDD avec le chiffrement natif de PS).
- TTL du JWT : 30 secondes. Jetons d'accès : 5 minutes.
- `X-Frame-Options: SAMEORIGIN` sur toutes les réponses d'administration.
- CSRF validé via `Tools::getAdminTokenLite`.
- **L'accès au Trust Center est réservé aux super-administrateurs.** Le relais de bootstrap AJAX
  (`ajaxProcessIssueBootstrap`) échoue en mode fermé pour tout employé dont le
  `id_profile` n'est pas `_PS_ADMIN_PROFILE_` (HTTP 403 `insufficient_capability`).
  L'ACL des onglets PrestaShop ne garantit qu'une permission de *consultation*, ce qui est
  peu privilégié ; comme le jeton émis porte une capacité
  `admin_trusteed` (écriture de confiance à l'échelle du marchand), nous la restreignons
  aux super-administrateurs.
- **Stockage du jeton — Bearer dans le DOM, PAS un cookie httpOnly.** Le point de terminaison
  du relais `POST /v1/embed/ps/issue-token` renvoie un jeton **opaque** de courte durée (TTL ≤ 900s)
  dans le champ JSON `access_token`. Le bootstrap externe
  (`views/js/trusteed-init.js`) le conserve en mémoire JS, et la SPA partagée
  le persiste dans un `sessionStorage` limité à l'onglet ; il est envoyé à l'API sous forme
  d'en-tête d'autorisation `Bearer` via le callback `getToken` du client API.
  Les cookies httpOnly ne sont volontairement pas utilisés car le JS ne peut pas les lire pour
  attacher l'en-tête Bearer. Le jeton est donc exposé au JS de la page,
  c'est pourquoi son TTL court + le rafraîchissement proactif (marge des 60 dernières secondes) constituent la
  mitigation principale, et non l'isolation par cookie.

### CSP — aucun script inline

`trust.tpl` ne contient **aucun bloc `<script>` inline**, il fonctionne donc sous une
Content-Security-Policy stricte. Il charge deux scripts externes, dans l'ordre :

1. `views/js/admin-spa.js` — la SPA empaquetée (définit `window.TrusteedEmbed`).
2. `views/js/trusteed-init.js` — le bootstrap compatible CSP qui récupère/rafraîchit
   le jeton opaque et appelle `TrusteedEmbed.mount(...)`.

Les valeurs injectées par Smarty (jeton CSRF, endpoint du jeton AJAX) sont transmises à
`trusteed-init.js` via des attributs `data-*` sur le conteneur `#amcp-root` et
lues avec `element.dataset` — rien n'est interpolé dans du JS exécutable.

## Compatibilité

| PrestaShop | PHP  | Statut                    |
| ---------- | ---- | ------------------------- |
| 8.0 – 8.2  | 8.1+ | ✅ Testé                  |
| 9.x        | 8.2+ | ⚠️ Devrait fonctionner (non testé) |

## Licence

MIT © Trusteed
