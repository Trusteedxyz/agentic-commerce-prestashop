[English](README.md) | **Español** | [Français](README.fr.md) | [Deutsch](README.de.md)

# Trusteed AgenticTools para PrestaShop

Los agentes de IA son un tipo nuevo de comprador online. Con Trusteed, la red que conecta a negocios y agentes, pueden comprar en tu tienda con las condiciones que tú fijes.

- Define tus reglas de negocio: a quién dejas comprar, hasta qué importe, qué categorías no quieres ofrecer a los agentes, límites de precio, niveles de stock que te protejan de agentes fraudulentos, y más.
- Recibe recibos firmados. Cada transacción genera un recibo firmado criptográficamente, en el que cualquier manipulación se puede detectar, y que te sirve como prueba de la compra si hay una disputa. Alineado con eIDAS (UE) y con eSIGN (EE. UU.).
- Consulta lo que hacen los agentes: cuánto gastan, qué productos compran y con qué frecuencia.
- Bloquea a los agentes que parezcan peligrosos o den problemas.
- Acepta compras en divisas digitales mediante el protocolo X402.
- Deja que agentes y comercios comercien directamente entre pares (peer-to-peer).

## Capturas de pantalla

| Inicio | Puntuación de confianza | Merchant Center — Pedidos |
|------|------------|--------------------------|
| ![Inicio](screenshots/01-home-dashboard.png) | ![Puntuación](screenshots/02-trust-score-breakdown.png) | ![Pedidos](screenshots/03-merchant-center-orders.png) |

| Merchant Center — Métodos de pago | Merchant Center — Certificaciones | Mis Ventas |
|----------------------------|-----------------------------------|----------|
| ![Pagos](screenshots/03b-merchant-center-payments.png) | ![Certificaciones](screenshots/04-merchant-center-certifications.png) | ![Mis Ventas](screenshots/05-my-sales-orders.png) |

| Recibos de confianza (Mis Ventas → Ventas IA) | Agentes |
|---------------------------------------|--------|
| ![Recibos](screenshots/06-my-sales-ai-receipts.png) | ![Agentes](screenshots/07-agents.png) |

| Detalle del comprobante — descarga en ZIP |
|--------------------------------------------|
| ![Descarga de comprobante](screenshots/08-my-sales-receipt-download.png) |

Cada transacción de un agente genera un recibo de confianza firmado, un registro en el que cualquier manipulación se puede detectar (alineado con eIDAS y con eSIGN) que aparece en **Mis Ventas → Ventas IA**. Haz clic en una fila para ver el detalle: ID del agente, herramienta invocada, hashes de entrada y salida, JWS. También puedes descargar el recibo como ZIP y guardarlo como respaldo por si hay una disputa.

## Funcionalidades

Trusteed AgenticTools reúne Trust Center, Merchant Center, herramientas agénticas MCP y enforcement de checkout en un único módulo de PrestaShop.

- Trust Center: recibos de confianza firmados, claves de firma, registro de auditoría, desglose de la puntuación de confianza.
- Merchant Center: pedidos, métodos de pago, agentes, reglas de checkout, estado de certificación y de NLWeb.
- 5 herramientas MCP nativas para el add-on PrestaShop MCP Server (marketplace ID 96617): `trusteed_sign_trust_receipt`, `trusteed_verify_agent_signature`, `trusteed_dispatch_payment_acp`, `trusteed_dispatch_payment_ap2`, `trusteed_dispatch_payment_x402`. Los agentes (Claude Desktop y otros) pueden firmar recibos y despachar pagos directamente desde PrestaShop.
- Enforcement de checkout: las reglas del comercio (importe máximo, países bloqueados, horario comercial y más) se aplican en cada checkout, con o sin agente.
- Evaluador offline de respaldo: aplica las mismas reglas universales en local cuando la API remota de reglas no está disponible, en lugar de un permitir/bloquear genérico por defecto.
- Auto-registro self-serve: registro de la tienda en Trusteed con un clic. También puedes pegar las credenciales a mano.
- Valores por defecto fail-closed: el enforcement nunca permite en silencio cuando está mal configurado.

## Compatibilidad

| Componente | Compatible |
|-----------|-----------|
| PrestaShop | 8.0.0 – 9.99.99 |
| PHP | 8.1+ |

## Requisitos

- PrestaShop 8.0.0 o superior
- PHP 8.1 o superior
- Una cuenta de Trusteed ([regístrate gratis en trusteed.xyz](https://trusteed.xyz))

## Instalación

### Subida manual

1. **Descarga el `.zip` instalable** desde el último Release de GitHub:
   [**⬇ trusteed-agentic-commerce-prestashop-2.0.1.zip**](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases/latest/download/trusteed-agentic-commerce-prestashop-2.0.1.zip)
   o consulta todas las versiones en la [página de Releases](https://github.com/Trusteedxyz/agentic-commerce-prestashop/releases).
2. En tu **Back Office** de PrestaShop: **Módulos → Gestor de módulos → Subir un módulo**.
3. Selecciona el `.zip` descargado y haz clic en **Subir este módulo**.
4. Haz clic en **Configurar**.

### Desde el código fuente (compilar el zip tú mismo)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git
cd agentic-commerce-prestashop
bash bin/build-zip.sh   # genera dist/trusteed-agentic-commerce-prestashop-<versión>.zip
```

El módulo incluye un autoloader PSR-4 de respaldo para el namespace `Trusteed\`, así que funciona incluso sin un directorio `vendor/`. El script de build no lo incluye y `composer install` es opcional.

### Vía Composer (opcional, para herramientas de IDE / desarrollo local)

```bash
git clone https://github.com/Trusteedxyz/agentic-commerce-prestashop.git trusteed
cd trusteed
composer install --no-dev --optimize-autoloader
```
Después sube la carpeta `trusteed/` resultante como `.zip` según lo descrito arriba. En producción puedes saltarte este paso; mira la nota del autoloader de respaldo más arriba.

## Configuración

1. Inicia sesión en tu **Back Office** de PrestaShop.
2. Ve a **Módulos → Trusteed AgenticTools → Configurar**.
3. Haz clic en **Auto-registrar esta tienda** (registro en un clic que rellena automáticamente el Merchant ID y el secreto), o pega manualmente tu **Merchant ID** y **S2S secret** desde [app.trusteed.xyz/settings](https://app.trusteed.xyz/settings).
4. Guarda. El módulo comprueba la conectividad y empieza a sincronizar las reglas de enforcement.

### Claves de configuración

| Clave | Por defecto | Propósito |
|-----|---------|-------------|
| `TRUSTEED_API_BASE` | `https://api.trusteed.xyz` | Endpoint del backend de Trusteed |
| `TRUSTEED_CEL_MERCHANT_ID` | _(vacío)_ | Merchant ID emitido por Trusteed |
| `TRUSTEED_EMBED_S2S_SECRET` | _(vacío)_ | Secreto servidor-a-servidor para la API de embed/enforcement |
| `TRUSTEED_BOOTSTRAP_TOKEN` | _(vacío)_ | Token embed-bootstrap heredado (reemplazado por el auto-registro) |

## Páginas de administración

Tras la instalación aparece un menú **Trusteed** en la barra lateral del Back Office de PrestaShop:

| Página | Descripción |
|------|-------------|
| Inicio | Resumen de reputación y ventas recientes |
| ¿Cómo va mi tienda? (Trust Center) | Recibos firmados, claves de firma, registro de auditoría, puntuación de confianza |
| Centro de comercio (Merchant Center) | Pedidos, métodos de pago, agentes, certificaciones, NLWeb |
| Mis ventas | Lista de pedidos y recibos de confianza IA |
| Mis Reglas | Reglas de enforcement de checkout |
| Seguridad | Registro de auditoría y alertas de anomalías |
| Agentes | Identidades de agentes conectados |
| Configuración | Ajustes del módulo y auto-registro |

## Preguntas frecuentes

**¿Qué datos se envían?** Solo lo que requieren las reglas de enforcement y los recibos de confianza: importes de pedido, país e identidad del agente. Ningún dato de tarjeta de pago pasa por Trusteed. Toda la comunicación usa HTTPS.

**¿Qué agentes son compatibles?** Cualquier agente conectado a través del add-on PrestaShop MCP Server (marketplace ID 96617), incluidos Claude Desktop y otros clientes compatibles con MCP.

**¿Ralentiza mi tienda?** No. El enforcement de checkout se ejecuta de forma síncrona solo en la validación del pedido, con un respaldo local offline cuando la API remota no está disponible.

## Historial de cambios

### 2.0.1

- Corrección: bundle del SPA de administración reconstruido (Fase A de evidencia de disputas: la lista real de recibos ahora se monta en Mis Ventas, igual que en Magento y WooCommerce).
- Añadido: script de empaquetado `bin/build-zip.sh`. El `.zip` instalable ahora se publica como asset del Release de GitHub, así que el comercio ya no tiene que compilarlo por su cuenta.

### 2.0.0

**Importante:** este release reemplaza el contenido publicado por error bajo `v1.0.0` en este repositorio. Se había publicado un módulo distinto e independiente («Trusteed Trust Center») en lugar de este módulo de enforcement de checkout y AgenticTools. Este es el primer release correcto.

- Corrección: el enforcement de checkout se saltaba por completo en checkouts orgánicos (sin agente). Reglas del comercio como el importe máximo, países bloqueados y horario comercial nunca se ejecutaban salvo que hubiera un token de agente presente. Estas reglas ahora se aplican en todos los checkouts, con o sin agente.
- Añadido: un evaluador offline de respaldo que aplica las mismas reglas universales localmente cuando la API remota de evaluación de reglas no está disponible.
- Añadido: auto-registro self-serve (registro de la tienda en un clic, además del flujo manual de pegar credenciales que ya existía).
- Rebrand técnico completo de `mcpwebstore`/`Mcpwebstore` a `trusteed`/`Trusteed`: namespace PSR-4, nombre técnico del módulo, constantes de configuración y los nombres de las 5 herramientas MCP que invocan los agentes.

## Soporte

- Email de soporte: support@trusteed.xyz
- Issues en GitHub: [github.com/Trusteedxyz/agentic-commerce-prestashop/issues](https://github.com/Trusteedxyz/agentic-commerce-prestashop/issues)

## Licencia

MIT. Ver [LICENSE](LICENSE) para el texto completo.
