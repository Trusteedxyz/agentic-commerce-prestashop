<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * `agent_reason` aditivo de POST /api/v1/rules/evaluate en un BLOCK: motivo
 * accionable para quien compra. Es entrada no fiable (viene de la red), así que
 * se sanea antes de que llegue al texto de la excepción.
 */
final class AgentReason
{
    private const MAX_LEN = 400;
    private const RULE_CODE = '/^R\d{3}(\.[a-z0-9-]+)?$/';

    /**
     * @param mixed $raw
     * @return array{reason_category:string,reason:string,next_action:string,rule_code:string}|null
     *         null si no hay un `reason` utilizable.
     */
    public static function sanitize($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $limitExceeded = self::limitExceeded($raw['limit_exceeded'] ?? null);
        $reason = self::text($raw['reason'] ?? null);
        // Con limit_exceeded valido el texto a mostrar es su message (lleva cifras); el reason se ignora.
        if ($limitExceeded !== null) {
            $reason = $limitExceeded['message'];
        } elseif ($reason === '' || preg_match('/[\p{Nd}<>]|&#?[A-Za-z0-9]+;/u', $reason) === 1) {
            return null;
        }
        $code = is_string($raw['rule_code'] ?? null) ? $raw['rule_code'] : '';

        // Claves opcionales: cada una se valida sola y, si no es valida, se omite sin tocar el resto.
        $optional = array_filter([
            'human_checkout'  => self::humanCheckout($raw['human_checkout'] ?? null),
            'blocked_item'    => self::blockedItem($raw['blocked_item'] ?? null),
            'published_limit' => self::publishedLimit($raw['published_limit'] ?? null),
            'limit_exceeded'  => $limitExceeded,
        ], static fn ($v) => $v !== null);

        return [
            'reason_category' => self::text($raw['reason_category'] ?? null),
            'reason'          => $reason,
            'next_action'     => self::text($raw['next_action'] ?? null),
            // `$` de PCRE casa antes de un "\n" final: se compara también la longitud.
            'rule_code'       => strlen($code) <= 64 && preg_match(self::RULE_CODE, $code) === 1 && strpos($code, "\n") === false ? $code : '',
        ] + $optional;
    }

    /**
     * Mensaje de la excepción: marcador estable + frase de categoría (+ regla si es publicable)
     * + elemento bloqueado, límite (en la moneda de la tienda) y salida de checkout humano.
     */
    public static function message(string $marker, ?array $agentReason, string $fallback, string $currency = ''): string
    {
        if ($agentReason === null) {
            return $fallback;
        }
        $text = $agentReason['reason'];
        if ($agentReason['rule_code'] !== '') {
            $text .= ' (rule ' . $agentReason['rule_code'] . ')';
        }

        foreach (self::extras($agentReason, $currency) as $extra) {
            $text .= ' ' . $extra;
        }

        return $marker . ' — ' . $text . ' requires_escalation: true';
    }

    /** @return list<string> */
    private static function extras(array $ar, string $currency): array
    {
        $out = [];
        if (isset($ar['blocked_item'])) {
            $out[] = '[blocked: ' . $ar['blocked_item']['value'] . ']';
        }
        // limit_exceeded ya va en el reason (su message): no se repite el limite publicado.
        $limit = isset($ar['limit_exceeded']) ? null : ($ar['published_limit'] ?? null);
        if ($limit !== null && $limit['kind'] === 'max_cart_value') {
            $out[] = '[limit: max ' . number_format($limit['amount_cents'] / 100, 2, '.', '')
                . ($currency !== '' ? ' ' . $currency : '') . ']';
        } elseif ($limit !== null) {
            $out[] = '[limit: máx. ' . $limit['units'] . ' unidades por línea]';
        }
        $hc = $ar['human_checkout'] ?? null;
        if ($hc !== null) {
            $out[] = isset($hc['url']) && $hc['available'] ? '[human checkout: ' . $hc['url'] . ']' : $hc['message'];
        }

        return $out;
    }

    /** @param mixed $v @return array{available:bool,message:string,url?:string}|null */
    private static function humanCheckout($v): ?array
    {
        if (!is_array($v) || !is_bool($v['available'] ?? null)) {
            return null;
        }
        $message = self::safeText($v['message'] ?? null);
        if ($message === '') {
            return null;
        }
        $out = ['available' => $v['available']];
        if (array_key_exists('url', $v)) {
            $url = $v['url'];
            // Una url presente pero invalida invalida la clave entera (no se degrada en silencio).
            if (!is_string($url) || strlen($url) > 2048 || preg_match('~^https?://[^\s<>"\'\\\\\x00-\x1f\x7f]+\z~i', $url) !== 1) {
                return null;
            }
            $out['url'] = $url;
        }

        return $out + ['message' => $message];
    }

    /** @param mixed $v @return array{kind:string,value:string}|null */
    private static function blockedItem($v): ?array
    {
        if (!is_array($v) || !in_array($v['kind'] ?? null, ['category', 'sku', 'digital_good_type'], true)) {
            return null;
        }
        $value = $v['value'] ?? null;
        // \z y no $: PCRE deja pasar un "\n" final con $.
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_.:\/ -]{1,64}\z/', $value) !== 1) {
            return null;
        }

        return ['kind' => $v['kind'], 'value' => $value];
    }

    /** @param mixed $v @return array<string,mixed>|null */
    private static function publishedLimit($v): ?array
    {
        if (!is_array($v) || !is_string($v['source'] ?? null) || self::safeText($v['source']) === '') {
            return null;
        }
        $kind = $v['kind'] ?? null;
        if ($kind === 'max_cart_value'
            && is_int($v['amount_cents'] ?? null) && $v['amount_cents'] >= 0
            && ($v['unit'] ?? null) === 'minor_units_of_store_currency') {
            return ['kind' => $kind, 'amount_cents' => $v['amount_cents'], 'unit' => $v['unit'], 'source' => $v['source']];
        }
        if ($kind === 'max_units_per_line' && is_int($v['units'] ?? null) && $v['units'] >= 0) {
            return ['kind' => $kind, 'units' => $v['units'], 'source' => $v['source']];
        }

        return null;
    }

    /** @param mixed $v @return array<string,mixed>|null */
    private static function limitExceeded($v): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        $rule = $v['rule'] ?? null;
        $code = $v['rule_code'] ?? null;
        $name = is_string($v['rule_name'] ?? null) ? self::safeText($v['rule_name']) : '';
        $message = is_string($v['message'] ?? null) ? self::safeText($v['message']) : '';
        $unit = $v['unit'] ?? null;
        if (!is_string($rule) || preg_match('/^R\d{3}\z/', $rule) !== 1
            || !is_string($code) || preg_match('/^R\d{3}\.[a-z0-9-]{1,60}\z/', $code) !== 1
            || $name === '' || mb_strlen($name, 'UTF-8') > 80
            || !in_array($v['kind'] ?? null, ['max_cart_value', 'max_line_value', 'max_items_per_order', 'max_units_per_line'], true)
            || !is_int($v['limit'] ?? null) || $v['limit'] < 0
            || !is_int($v['observed'] ?? null) || $v['observed'] < 0
            || !in_array($unit, ['minor_units_of_store_currency', 'units'], true)
            // Sin truncar: un message de mas de 400 invalida la clave (text() lo cortaria).
            || $message === '' || mb_strlen((string) $v['message'], 'UTF-8') > self::MAX_LEN
            || $message !== $v['message']) {
            return null;
        }

        return ['rule' => $rule, 'rule_code' => $code, 'rule_name' => $name, 'kind' => $v['kind'],
            'limit' => $v['limit'], 'observed' => $v['observed'], 'unit' => $unit, 'message' => $message];
    }

    /** Texto libre de las claves nuevas: sin el filtro de digitos, pero sin '<', '>' ni entidades. */
    private static function safeText($v): string
    {
        $t = self::text($v);

        return preg_match('/[<>]|&#?[A-Za-z0-9]+;/', $t) === 1 ? '' : $t;
    }

    /** @param mixed $v */
    private static function text($v): string
    {
        if (!is_string($v)) {
            return '';
        }
        // Control, formato (bidi, ancho cero) y separadores de linea: se descarta el texto entero,
        // no se limpia. UTF-8 invalido => preg devuelve null => vacio.
        if (preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $v) !== 0) {
            return '';
        }

        return trim(mb_substr($v, 0, self::MAX_LEN, 'UTF-8'));
    }
}
