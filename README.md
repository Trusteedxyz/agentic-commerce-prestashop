# Trusteed AgenticTools — PrestaShop Module

Panel de confianza para PrestaShop que permite a los comerciantes gestionar ventas a agentes de IA (Claude, ChatGPT, etc.), visualizar su puntuación de confianza y emitir comprobantes de venta firmados digitalmente.

- **Compatible:** PrestaShop 8.0.0 – 9.99.99
- **PHP:** 8.1+
- **Autor:** Trusteed
- **Licencia:** MIT

## Capturas de pantalla

### Panel de inicio — Reputación y ventas recientes

![Home dashboard](screenshots/01-home-dashboard.png)

### Desglose de puntuación de confianza

![Trust score breakdown](screenshots/02-trust-score-breakdown.png)

### Merchant Center — Pedidos

![Merchant Center orders](screenshots/03-merchant-center-orders.png)

### Merchant Center — Métodos de pago

![Merchant Center payments](screenshots/03b-merchant-center-payments.png)

### Merchant Center — Certificaciones y NLWeb

![Merchant Center certifications](screenshots/04-merchant-center-certifications.png)

### Mis ventas — Lista de pedidos

![My sales orders](screenshots/05-my-sales-orders.png)

### Mis ventas — Comprobantes de venta IA (Trust Receipts)

![My sales AI receipts](screenshots/06-my-sales-ai-receipts.png)

### Agentes de compra

![Agents](screenshots/07-agents.png)

---

## Instalación

1. Instala el add-on PrestaShop MCP Server (marketplace ID `96617`).
2. Copia este módulo en `modules/trusteed/` y ejecuta `composer install`.
3. Instala desde Back Office → Módulos → "Trusteed AgenticTools".
4. Configura `TRUSTEED_API_BASE` y `TRUSTEED_BOOTSTRAP_TOKEN` desde el dashboard de Trusteed.

## Integración con herramientas agénticas (MCP)

Este módulo expone cinco herramientas MCP canónicas mediante el atributo PHP `#[McpTool]`
(`php-mcp/server`). Cuando el add-on MCP Server `96617` está presente lo descubre
automáticamente — `Trusteed::isMcpCompliant()` devuelve `true` y el add-on
escanea `src/AgenticTools/Tools/` durante el registro de herramientas.

### Herramientas expuestas

Fuente: `src/AgenticTools/Tools/`.

| Herramienta (MCP)                 | Archivo fuente                 | Propósito                                                               |
| --------------------------------- | ------------------------------ | ----------------------------------------------------------------------- |
| `trusteed_sign_trust_receipt`     | `SignTrustReceiptTool.php`     | Emite un Trust Receipt JWS Ed25519 para un pedido PrestaShop.           |
| `trusteed_verify_agent_signature` | `VerifyAgentSignatureTool.php` | Verifica una firma de mensaje HTTP RFC 9421 para una petición agéntica. |
| `trusteed_dispatch_payment_acp`   | `DispatchPaymentAcpTool.php`   | Despacha un checkout ACP (Agent Commerce Protocol) para un carrito.     |
| `trusteed_dispatch_payment_x402`  | `DispatchPaymentX402Tool.php`  | Despacha un pago x402 en stablecoin para un carrito PrestaShop.         |
| `trusteed_dispatch_payment_ap2`   | `DispatchPaymentAp2Tool.php`   | **[EXPERIMENTAL]** Despacha un pago AP2 basado en mandato agéntico.     |

### Requisitos

- PHP 8.1+
- PrestaShop 9.x (también funciona en 8.0+)
- Add-on PrestaShop MCP Server `96617` instalado y habilitado
- `php-mcp/server` disponible (dependencia transitiva del add-on `96617`)

### Cómo funciona

1. El add-on `96617` llama a `Trusteed::isMcpCompliant()` durante el arranque.
2. Si devuelve `true`, escanea el namespace PSR-4 de este módulo buscando clases con el atributo `#[McpTool(name: ..., description: ...)]`.
3. Las cinco herramientas se registran en el MCP Server y quedan disponibles para cualquier cliente agente conectado.

### Verificar la integración

Desde Claude Desktop (o cualquier cliente MCP) conectado al PrestaShop MCP Server, ejecuta `list_tools`. Las cinco herramientas deben aparecer con el prefijo `trusteed_`.

Ejemplo de invocación de agente:

> "Firma un comprobante de venta para el pedido 123."

→ Claude llama a `trusteed_sign_trust_receipt({ orderId: "123" })` y el módulo devuelve `{ receiptId, jws, kid, signedAt }`.

### Degradación elegante

Si el add-on `96617` **no** está instalado, `class_exists('PhpMcp\\Server\\Attributes\\McpTool')` devuelve `false` y las clases de atributos se omiten silenciosamente. El módulo sigue siendo instalable y funcional para flujos no-agénticos — solo la superficie de AgenticTools no estará disponible.

## Claves de configuración

| Clave                      | Por defecto                | Propósito                                   |
| -------------------------- | -------------------------- | ------------------------------------------- |
| `TRUSTEED_API_BASE`        | `https://api.trusteed.xyz` | URL base del backend de Trusteed.           |
| `TRUSTEED_BOOTSTRAP_TOKEN` | _(vacío)_                  | Token embed-bootstrap emitido por Trusteed. |

## Changelog

### 2.0.0

**Corrección importante:** este release reemplaza el contenido publicado previamente bajo `v1.0.0` en este repositorio, que por error correspondía a un módulo distinto (Trust Center standalone). Este release contiene el módulo real y vigente: `Trusteed` (technical name `trusteed`), que consolida Trust Center + Merchant Center + AgenticTools (5 MCP tools) + Checkout Enforcement en un solo módulo PrestaShop, con 9 controladores de administración.

- **Fix**: enforcement de checkout se saltaba por completo en checkouts orgánicos (sin agente) — reglas de merchant (monto máximo, países bloqueados, horario comercial) ahora aplican siempre.
- **Añadido**: evaluador offline de respaldo (`OfflineSafetyValveEvaluator`) para cuando la API remota de reglas no está disponible.
- **Añadido**: auto-registro self-serve (un clic para registrar la tienda en Trusteed y obtener credenciales automáticamente, además del método manual existente).
- **Rebrand técnico completo**: namespace PSR-4, nombre técnico del módulo, constantes de configuración y nombres de las 5 MCP tools pasan de `mcpwebstore`/`Mcpwebstore` a `trusteed`/`Trusteed`.

## Licencia

MIT — ver `composer.json`.
