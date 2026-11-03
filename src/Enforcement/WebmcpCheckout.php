<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Endpoint WebMCP del checkout: tools `get_checkout`, `update_checkout`, `complete_checkout`.
 * Port de `Trusteed_Webmcp_Checkout` (WooCommerce) y de `Controller\Webmcp\Checkout` (Magento): misma conducta.
 *
 * - CSRF: el token estático de PrestaShop (`Tools::getToken(false)`; para invitados NO está ligado a la sesión)
 *   viaja en la cabecera propia `X-Trusteed-Token` y se compara con `hash_equals`; nunca del cuerpo ni de la query.
 *   La barrera real es esa cabecera personalizada (un formulario cruzado no la puede poner) más `Origin`. Si hay
 *   `Origin`, su esquema+host+puerto debe ser el de la tienda (ausente se admite; `null` se rechaza).
 * - La marca de agente (servidor, {@see \Trusteed\Storage\CartAgentStorage}) se pone SOLO tras pasar todas las
 *   comprobaciones y antes de despachar; ninguna entrada del cliente la puede quitar.
 * - `complete_checkout` jamás crea pedido: devuelve la URL del checkout para que lo confirme la persona.
 * - Ajuste apagado: 404 sin tocar nada.
 *
 * Lógica pura sobre un puerto de carrito: el front controller sólo extrae petición y entorno.
 */
final class WebmcpCheckout
{
    public const CONFIG         = 'TRUSTEED_WEBMCP_CHECKOUT_TOOLS';
    public const TOKEN_HEADER   = 'X-Trusteed-Token';
    public const MAX_BODY_BYTES = 16384;
    public const TOOLS          = ['get_checkout', 'update_checkout', 'complete_checkout'];

    private const ADDRESS_FIELDS = ['address1', 'address2', 'city', 'postal_code', 'country_code', 'province_code', 'first_name', 'last_name'];

    /**
     * @param \Closure(int):bool $mark      Marca el carrito como agéntico (canal webmcp); false = no quedó marcado.
     * @param \Closure():void    $signature Web Bot Auth: sólo anota, nunca bloquea a una persona.
     */
    public function __construct(
        private readonly WebmcpCartPort $cart,
        private readonly \Closure $mark,
        private readonly \Closure $signature,
    ) {
    }

    /** Exactamente `on` lo activa; ausente, vacío o cualquier otra cosa = apagado. */
    public static function isOn(?string $raw): bool
    {
        return $raw === 'on';
    }

    /**
     * @return array{0:int, 1:array<string,mixed>} código HTTP y cuerpo JSON.
     */
    public function handle(
        bool $enabled,
        string $method,
        ?string $sentToken,
        string $expectedToken,
        ?string $origin,
        string $shopOrigin,
        string $rawBody
    ): array {
        if (!$enabled) {
            return [404, ['error' => 'not_found']];
        }
        if (strtoupper($method) !== 'POST') {
            return [405, ['error' => 'method_not_allowed']];
        }
        if ($sentToken === null || $sentToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $sentToken)) {
            return [403, ['error' => 'forbidden']];
        }
        if ($origin !== null && $origin !== '') {
            $mine = self::originOf($shopOrigin);
            $got  = self::originOf($origin);
            if ($got === null || $got !== $mine) {
                return [403, ['error' => 'forbidden']];
            }
        }

        // Web Bot Auth en cada llamada autorizada; un fallo nunca rompe la petición.
        try {
            ($this->signature)();
        } catch (\Throwable $e) {
            // observe: solo anota
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return [413, ['error' => 'payload_too_large']];
        }
        $body = json_decode($rawBody, true);
        $tool = is_array($body) && isset($body['tool']) && is_string($body['tool']) ? $body['tool'] : '';
        if (!in_array($tool, self::TOOLS, true)) {
            return [400, ['error' => 'unknown_tool']];
        }
        $args     = isset($body['arguments']) && is_array($body['arguments']) ? $body['arguments'] : [];
        $checkout = isset($args['checkout']) && is_array($args['checkout']) ? $args['checkout'] : [];

        if ($this->cart->isEmpty()) {
            return [200, ['status' => 'empty']];
        }

        // Una petición nueva no trae tarifas ni totales: recalcular antes de validar y de describir.
        $this->cart->recalculate();

        $update = $tool === 'update_checkout' ? self::whitelist($checkout) : [];
        if (isset($update['method_id'])) {
            if (!$this->cart->hasDeliveryAddress()) {
                return [400, ['error' => 'address_required']];
            }
            if (!in_array($update['method_id'], $this->cart->carrierIds(), true)) {
                return [400, ['error' => 'invalid_shipping_method']];
            }
        }

        // Marcar ANTES de despachar; solo añade restricciones. Sin marca no se despacha: un carrito
        // operado por un agente nunca debe quedar como el de una persona.
        if (!($this->mark)($this->cart->cartId())) {
            return [500, ['error' => 'mark_failed']];
        }

        if ($tool === 'complete_checkout') {
            return [200, ['status' => 'requires_buyer_confirmation', 'checkout_url' => $this->cart->checkoutUrl()]];
        }
        if ($tool === 'update_checkout') {
            $ignored = $this->cart->applyUpdate($update);
            $this->cart->recalculate();
            $ignored = array_values(array_unique(array_merge($ignored, self::droppedFields($checkout, $update))));
            $out     = $this->cart->describe();

            return [200, $ignored === [] ? $out : $out + ['ignored' => $ignored]];
        }

        return [200, $this->cart->describe()];
    }

    /**
     * Solo `buyer.email` válido, los ocho campos de dirección y `fulfillment.method_id`; el resto se descarta.
     *
     * @param array<string,mixed> $checkout
     * @return array{email?:string, address?:array<string,string>, method_id?:string}
     */
    private static function whitelist(array $checkout): array
    {
        $out   = [];
        $email = $checkout['buyer']['email'] ?? null;
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $out['email'] = $email;
        }
        $fulfillment = isset($checkout['fulfillment']) && is_array($checkout['fulfillment']) ? $checkout['fulfillment'] : [];
        $in          = isset($fulfillment['address']) && is_array($fulfillment['address']) ? $fulfillment['address'] : [];
        $address     = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            if (isset($in[$field]) && is_string($in[$field])) {
                $address[$field] = trim(strip_tags($in[$field]));
            }
        }
        if ($address !== []) {
            $out['address'] = $address;
        }
        $methodId = $fulfillment['method_id'] ?? null;
        if (is_string($methodId) && $methodId !== '') {
            $out['method_id'] = $methodId;
        } elseif (array_key_exists('method_id', $fulfillment) && $methodId !== null && $methodId !== '') {
            $out['method_id'] = '__invalid__'; // no es cadena: nunca coincide con un transportista
        }

        return $out;
    }

    /**
     * Campos que el agente pidió y la lista blanca descartó (email inválido, dirección sin campos válidos).
     *
     * @param array<string,mixed> $checkout
     * @param array<string,mixed> $update
     * @return list<string>
     */
    private static function droppedFields(array $checkout, array $update): array
    {
        $out = [];
        if (isset($checkout['buyer']['email']) && !isset($update['email'])) {
            $out[] = 'buyer.email';
        }
        if (!empty($checkout['fulfillment']['address']) && !isset($update['address'])) {
            $out[] = 'fulfillment.address';
        }

        return $out;
    }

    /** `scheme://host:port` en minúsculas con el puerto por defecto explícito; null si no es una URL. */
    private static function originOf(string $url): ?string
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);

        return $scheme . '://' . strtolower($p['host']) . ':' . ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * HTML para `displayHeader`: la configuración (JSON escapado, apto para un <script>) y el script de las tools.
     * Vacío si el ajuste está apagado o no es la página de checkout (`order`).
     */
    public static function headerHtml(bool $on, string $phpSelf, string $endpoint, string $token, string $scriptUrl): string
    {
        if (!$on || $phpSelf !== 'order') {
            return '';
        }
        $json = json_encode([
            'endpoint'    => $endpoint,
            'nonce'       => $token,
            'nonceHeader' => self::TOKEN_HEADER,
            'platform'    => 'prestashop',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return '<script>window.__trusteedCheckoutTools=' . $json . ';</script>'
            . '<script src="' . htmlspecialchars($scriptUrl, ENT_QUOTES) . '" defer></script>';
    }
}
