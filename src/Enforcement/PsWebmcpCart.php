<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Adaptador de {@see WebmcpCartPort} sobre `\Cart` de PrestaShop 8/9.
 *
 * Alcance honesto: el método de envío se escribe con `setDeliveryOption`; la dirección sólo se actualiza
 * si el carrito ya tiene una dirección de entrega de un cliente identificado, que es suya y que ningún
 * pedido ha usado. `buyer.email` NO se escribe nunca (cambiaría la cuenta de una persona); el invitado
 * rellena sus datos al confirmar en el checkout, que es donde `complete_checkout` lo envía.
 */
final class PsWebmcpCart implements WebmcpCartPort
{
    public function __construct(private readonly ?\Cart $cart)
    {
    }

    public function cartId(): int
    {
        return $this->cart !== null ? (int) $this->cart->id : 0;
    }

    public function isEmpty(): bool
    {
        return $this->cart === null || (int) $this->cart->id <= 0 || (int) $this->cart->nbProducts() === 0;
    }

    public function recalculate(): void
    {
        if ($this->cart !== null) {
            $this->cart->getDeliveryOptionList(null, true);
        }
    }

    public function carrierIds(): array
    {
        $ids = [];
        if ($this->cart === null) {
            return $ids;
        }
        foreach ((array) $this->cart->getDeliveryOptionList() as $options) {
            foreach (array_keys((array) $options) as $key) {
                $ids[] = rtrim((string) $key, ',');
            }
        }

        return array_values(array_unique($ids));
    }

    public function hasDeliveryAddress(): bool
    {
        return $this->cart !== null && (int) $this->cart->id_address_delivery > 0;
    }

    public function applyUpdate(array $update): array
    {
        $ignored = [];
        if ($this->cart === null) {
            return $ignored;
        }
        // El email nunca se escribe: crearía o cambiaría una cuenta.
        if (isset($update['email'])) {
            $ignored[] = 'buyer.email';
        }
        if (isset($update['address']) && !$this->updateOwnUnusedAddress($update['address'])) {
            $ignored[] = 'fulfillment.address';
        }
        if (isset($update['method_id']) && (int) $this->cart->id_address_delivery > 0) {
            $this->cart->setDeliveryOption([(int) $this->cart->id_address_delivery => $update['method_id'] . ',']);
            $this->cart->update();
        }

        return $ignored;
    }

    /** @param array<string,string> $in */
    private function updateOwnUnusedAddress(array $in): bool
    {
        $cart = $this->cart;
        if ((int) $cart->id_customer <= 0 || (int) $cart->id_address_delivery <= 0) {
            return false;
        }
        $address = new \Address((int) $cart->id_address_delivery);
        if (!\Validate::isLoadedObject($address) || (int) $address->id_customer !== (int) $cart->id_customer || $address->isUsed()) {
            return false;
        }
        isset($in['address1']) && $address->address1 = $in['address1'];
        isset($in['address2']) && $address->address2 = $in['address2'];
        isset($in['city']) && $address->city = $in['city'];
        isset($in['postal_code']) && $address->postcode = $in['postal_code'];
        isset($in['first_name']) && $address->firstname = $in['first_name'];
        isset($in['last_name']) && $address->lastname = $in['last_name'];
        if (isset($in['country_code']) && ($idCountry = (int) \Country::getByIso($in['country_code'])) > 0) {
            $address->id_country = $idCountry;
            if (isset($in['province_code'])) {
                $address->id_state = (int) \State::getIdByIso($in['province_code'], $idCountry);
            }
        }
        return (bool) $address->update();
    }

    public function describe(): array
    {
        $cart  = $this->cart;
        $items = [];
        foreach ((array) $cart->getProducts() as $p) {
            $items[] = [
                'name'       => (string) ($p['name'] ?? ''),
                'quantity'   => (int) ($p['cart_quantity'] ?? 0),
                'line_total' => (float) ($p['total_wt'] ?? 0),
            ];
        }
        $address  = (int) $cart->id_address_delivery > 0 ? new \Address((int) $cart->id_address_delivery) : null;
        $loaded   = $address !== null && \Validate::isLoadedObject($address);
        $selected = (array) $cart->getDeliveryOption(null, true);
        $method   = $selected !== [] ? rtrim((string) reset($selected), ',') : '';
        $currency = \Currency::getCurrencyInstance((int) $cart->id_currency);
        $customer = \Context::getContext()->customer ?? null;

        return [
            'status'      => 'open',
            'currency'    => (string) ($currency->iso_code ?? ''),
            'items'       => $items,
            'totals'      => [
                'subtotal' => (float) $cart->getOrderTotal(true, \Cart::ONLY_PRODUCTS),
                'shipping' => (float) $cart->getOrderTotal(true, \Cart::ONLY_SHIPPING),
                'total'    => (float) $cart->getOrderTotal(true),
            ],
            'buyer'       => ['email' => $customer !== null && \Validate::isLoadedObject($customer) ? (string) $customer->email : ''],
            'fulfillment' => [
                'address'   => [
                    'address1'     => $loaded ? (string) $address->address1 : '',
                    'city'         => $loaded ? (string) $address->city : '',
                    'postal_code'  => $loaded ? (string) $address->postcode : '',
                    'country_code' => $loaded ? (string) \Country::getIsoById((int) $address->id_country) : '',
                ],
                'method_id' => $method !== '' ? $method : null,
            ],
        ];
    }

    public function checkoutUrl(): string
    {
        return (string) \Context::getContext()->link->getPageLink('order', true);
    }
}
