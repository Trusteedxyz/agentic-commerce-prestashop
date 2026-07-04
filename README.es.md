[English](README.md) | **Español** | [Français](README.fr.md) | [Deutsch](README.de.md)

# Trusteed Agentic Commerce for PrestaShop

Permite que los nuevos compradores online, los agentes de IA, realicen compras en tu tienda de forma segura y fiable gracias a Trusteed: la red que fomenta la confianza entre negocios y agentes.

- **Define tus reglas de negocio**: a quién permites comprar, hasta qué importe, qué categorías no quieres ofrecer a los agentes, establece límites de precio, mantén niveles de stock para protegerte frente a posibles agentes fraudulentos, y mucho más.
- **Recibos a prueba de manipulación**: generamos recibos firmados electrónicamente y criptográficamente invulnerables que sirven como prueba de la transacción real en caso de disputa. Compatible con las normativas eIDAS (UE, Reino Unido) y eSIGN (EE. UU.).
- **Analítica de agentes**: consulta estadísticas sobre las compras de los agentes — cuánto gastan, qué productos compran y con qué frecuencia.
- **Bloqueo de agentes**: bloquea agentes potencialmente peligrosos o problemáticos.
- **Monedas digitales**: permite compras en monedas digitales gracias al protocolo X402.
- **Transacciones entre pares**: habilita el comercio directo entre pares (peer-to-peer) entre agentes y comerciantes.

## Capturas de pantalla

| Panel de control | Puntuación de confianza |
|-----------|------------|
| ![Dashboard](screenshots/01-home-dashboard.png) | ![Trust Score](screenshots/02-trust-score-breakdown.png) |

| Pedidos | Pagos |
|--------|---------|
| ![Orders](screenshots/03-merchant-center-orders.png) | ![Payments](screenshots/03b-merchant-center-payments.png) |

| Certificaciones | Mis ventas |
|---------------|---------|
| ![Certifications](screenshots/04-merchant-center-certifications.png) | ![My Sales](screenshots/05-my-sales-orders.png) |

| Recibos de IA | Agentes |
|------------|-------|
| ![AI Receipts](screenshots/06-my-sales-ai-receipts.png) | ![Agents](screenshots/07-agents.png) |

## Características

- Trust Center: recibos, claves de firma, registro de auditoría, puntuación de confianza
- Merchant Center: pedidos, métodos de pago, agentes, configuración de checkout, certificación + NLWeb
- Asistente de incorporación (4 pasos — sin necesidad de página de configuración)
- Acceso al Trust Center restringido a super-administradores (relay de bootstrap fail-closed), con el
  ámbito `all_shops` del super-administrador derivado en el servidor
- Insignia de confianza `displayBackOfficeTop` en cada página del back office

## Requisitos

- PrestaShop 8.0.0 – 9.99.99
- PHP 8.1+
- Una cuenta de Trusteed en app.trusteed.xyz

## Instalación

### Opción 1: PrestaShop Addons (recomendado)

[Próximamente]

### Opción 2: Subida manual (recomendado)

1. **Descarga el `.zip` instalable** desde la última GitHub Release:
   [**⬇ trusteed-agentic-commerce-prestashop-1.0.0.zip**](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases/latest/download/trusteed-agentic-commerce-prestashop-1.0.0.zip)
   — o consulta todas las versiones en la [página de Releases](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases).
2. En tu Back Office de PS: **Módulos → Gestor de módulos → Subir un módulo**.
3. Sube el fichero descargado `trusteed-agentic-commerce-prestashop-1.0.0.zip`.
4. Haz clic en **Configurar** → el asistente de configuración se abrirá automáticamente.

### Opción 3: Docker / desarrollo

```bash
docker compose -f e2e/docker/ps-staging.yml up -d
docker compose -f e2e/docker/ps-staging.yml exec prestashop \
  php bin/console prestashop:module install trusteed
```

## Asistente rápido de configuración

En la primera instalación (o cuando `AGENTICMCP_MERCHANT_ID` no esté definido), el módulo redirige al asistente rápido de configuración:

1. **Bienvenida** — confirma los requisitos previos (cuenta + salida HTTPS)
2. **Conectar** — abre el Portal de Trusteed y navega a "Conectar una tienda → PrestaShop"
3. **Credenciales** — pega tu Merchant ID y tu Bootstrap Secret
4. **Prueba** — haz clic en "Comprobar credenciales" para verificar la conexión

## Multitienda (PrestaShop Multistore)

- Cada tienda tiene un contexto de embed independiente (claim `id_shop` acotado).
- **Solo los super-administradores (`id_profile === _PS_ADMIN_PROFILE_`) pueden abrir el Trust
  Center.** El relay de bootstrap falla en modo cerrado (HTTP 403) para cualquier otro
  perfil, de modo que los usuarios que no son super-administradores nunca obtienen un token de embed — no existe
  una vista reducida del Trust Center para un "empleado normal".
- Para los super-administradores, la lista `all_shops` se deriva en el servidor a partir de la lista
  real de tiendas (`Shop::getCompleteListOfShopsID()`), nunca del contexto del back office controlado
  por quien llama, y la SPA muestra un desplegable selector de tienda.

## Ficheros del módulo

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

## Notas de seguridad

- El bootstrap secret se almacena en la tabla `Configuration` de PS (cifrado a nivel de BD con el cifrado nativo de PS).
- TTL del JWT: 30 segundos. Tokens de acceso: 5 minutos.
- `X-Frame-Options: SAMEORIGIN` en todas las respuestas de administración.
- CSRF validado mediante `Tools::getAdminTokenLite`.
- **El acceso al Trust Center está restringido a super-administradores.** El relay de bootstrap AJAX
  (`ajaxProcessIssueBootstrap`) falla en modo cerrado para cualquier empleado cuyo
  `id_profile` no sea `_PS_ADMIN_PROFILE_` (HTTP 403 `insufficient_capability`).
  El ACL de pestañas de PrestaShop solo garantiza el permiso de *visualización*, que es
  de bajo privilegio; dado que el token emitido lleva una capacidad
  `admin_trusteed` (escritura de confianza a nivel de todo el comercio), la restringimos
  a los super-administradores.
- **Almacenamiento del token — Bearer en el DOM, NO en una cookie httpOnly.** El endpoint del
  relay `POST /v1/embed/ps/issue-token` devuelve un token **opaco** de vida corta (TTL ≤ 900s)
  en el campo JSON `access_token`. El bootstrap externo
  (`views/js/trusteed-init.js`) lo mantiene en memoria de JS y la SPA compartida
  lo persiste en `sessionStorage` limitado a la pestaña; se envía a la API como una
  cabecera de autorización `Bearer` a través del callback `getToken` del cliente API.
  Las cookies httpOnly se descartan deliberadamente porque JS no puede leerlas para
  adjuntar la cabecera Bearer. Por tanto, el token queda expuesto al JS de la página,
  así que su TTL corto + la renovación proactiva (margen de los últimos 60s) son la mitigación
  principal, no el aislamiento por cookie.

### CSP — sin scripts inline

`trust.tpl` **no contiene bloques `<script>` inline**, por lo que funciona bajo una
Content-Security-Policy estricta. Carga dos scripts externos, en este orden:

1. `views/js/admin-spa.js` — la SPA empaquetada (define `window.TrusteedEmbed`).
2. `views/js/trusteed-init.js` — el bootstrap compatible con CSP que obtiene/renueva
   el token opaco y llama a `TrusteedEmbed.mount(...)`.

Los valores inyectados por Smarty (token CSRF, endpoint del token AJAX) se pasan a
`trusteed-init.js` mediante atributos `data-*` en el contenedor `#amcp-root` y
se leen con `element.dataset` — nada se interpola en JS ejecutable.

## Compatibilidad

| PrestaShop | PHP  | Estado                    |
| ---------- | ---- | ------------------------- |
| 8.0 – 8.2  | 8.1+ | ✅ Probado                |
| 9.x        | 8.2+ | ⚠️ Debería funcionar (sin probar) |

## Licencia

MIT © Trusteed
