<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/** Lo que {@see WebmcpCheckout} necesita del carrito; el adaptador real es {@see PsWebmcpCart}. */
interface WebmcpCartPort
{
    public function cartId(): int;

    public function isEmpty(): bool;

    /** Recalcula transportistas y totales (una petición nueva no los trae). */
    public function recalculate(): void;

    /** @return list<string> Identificadores de método de envío disponibles para este carrito. */
    public function carrierIds(): array;

    /** El carrito ya tiene dirección de entrega (sin ella no hay transportistas que elegir). */
    public function hasDeliveryAddress(): bool;

    /**
     * Escribe lo ya validado y filtrado por la lista blanca.
     *
     * @param array{email?:string, address?:array<string,string>, method_id?:string} $update
     * @return list<string> Campos pedidos que NO se aplicaron (`buyer.email`, `fulfillment.address`).
     */
    public function applyUpdate(array $update): array;

    /** @return array<string,mixed> */
    public function describe(): array;

    public function checkoutUrl(): string;
}
