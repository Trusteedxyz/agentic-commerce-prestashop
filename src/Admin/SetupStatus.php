<?php

declare(strict_types=1);

namespace Trusteed\Admin;

/**
 * En qué punto del alta está realmente esta tienda — auditoría de onboarding
 * 2026-09-09, huecos A4 y A2.
 *
 * ## Los dos fallos que cierra
 *
 * **A4 — el módulo no avisaba de que faltaba configurarlo.**
 * `hookDisplayBackOfficeTop` arrancaba con `if ($merchantId === '') return '';`,
 * así que el único indicador visual del módulo aparecía *después* de
 * configurarlo. Justo al revés de lo que hace falta: quien instala y no termina
 * no recibe ninguna señal, y como nada le contradice, da por hecho que acabó.
 *
 * **A2 — el enforcement queda apagado en silencio.**
 * `handleAutoRegister()` escribe `TRUSTEED_CEL_MERCHANT_ID` y
 * `TRUSTEED_EMBED_S2S_SECRET`, y anuncia «Credentials configured
 * automatically». Pero `TRUSTEED_CEL_INSTALLATION_ID` y
 * `TRUSTEED_CEL_HMAC_SECRET` **no tienen escritor automático en todo el
 * módulo** (sólo el formulario manual de `Enforcement\AdminSettings`), y sin las
 * dos no se evalúa ni una regla en ningún checkout.
 *
 * ## Por qué es una clase y no tres `if` dentro del hook
 *
 * El hook pinta HTML y lee `Configuration`. Una decisión de tres estados
 * enterrada ahí no se puede probar sin levantar medio PrestaShop, que es
 * exactamente por lo que el fallo A4 sobrevivió: nadie podía escribir el test
 * que dijera «sin configurar también se pinta algo». Esto es lógica pura sobre
 * tres cadenas.
 *
 * Deliberadamente NO lee `Configuration` por su cuenta: quien la construye le
 * pasa los tres valores. Así el mismo objeto sirve al hook, a la página de
 * configuración y a los tests sin stubs globales.
 */
final class SetupStatus
{
    /** Ni siquiera hay tienda registrada: falta el paso principal. */
    public const STATE_NOT_CONFIGURED = 'not_configured';

    /** Tienda registrada, pero el enforcement no puede evaluar nada. */
    public const STATE_ENFORCEMENT_INCOMPLETE = 'enforcement_incomplete';

    /** Registrada y con enforcement capaz de firmar sus llamadas. */
    public const STATE_READY = 'ready';

    private string $merchantId;
    private string $installationId;
    private string $hmacSecret;

    public function __construct(string $merchantId, string $installationId, string $hmacSecret)
    {
        $this->merchantId = trim($merchantId);
        $this->installationId = trim($installationId);
        $this->hmacSecret = trim($hmacSecret);
    }

    public function state(): string
    {
        if ($this->merchantId === '') {
            return self::STATE_NOT_CONFIGURED;
        }

        // Las dos, no una: un identificador sin secreto supera el
        // corto-circuito del evaluador y luego no puede firmar, así que
        // `/v1/rules/evaluate` rechaza la llamada y se degrada a dejar pasar
        // por la otra puerta. Desde fuera es indistinguible de no tener nada.
        if ($this->installationId === '' || $this->hmacSecret === '') {
            return self::STATE_ENFORCEMENT_INCOMPLETE;
        }

        return self::STATE_READY;
    }

    /** ¿Hay algo que el comerciante deba hacer? */
    public function needsAttention(): bool
    {
        return $this->state() !== self::STATE_READY;
    }

    /**
     * ¿Se está evaluando alguna regla de checkout ahora mismo?
     *
     * Nombrado por lo que el comerciante quiere saber, no por la config: la
     * pregunta que importa es «¿estoy protegido?», y la respuesta honesta con
     * cualquier credencial ausente es que no.
     */
    public function enforcementActive(): bool
    {
        return $this->state() === self::STATE_READY;
    }

    /**
     * Color del badge. Uno por estado — si «falta configurar» y «listo» se
     * pintaran igual, el indicador volvería a no decir nada, que es el fallo A4
     * con otra forma.
     */
    public function badgeColor(): string
    {
        switch ($this->state()) {
            case self::STATE_NOT_CONFIGURED:
                return '#b23c17'; // rojo tierra: falta el paso principal
            case self::STATE_ENFORCEMENT_INCOMPLETE:
                return '#946200'; // ámbar: funciona, pero no protege
            default:
                return '#1a7f4f'; // verde: el de siempre
        }
    }

    /** Clave de traducción del texto corto del badge. Una por estado. */
    public function labelKey(): string
    {
        switch ($this->state()) {
            case self::STATE_NOT_CONFIGURED:
                return 'Trusteed: finish setup';
            case self::STATE_ENFORCEMENT_INCOMPLETE:
                return 'Trusteed: checkout not protected';
            default:
                return 'Trusteed';
        }
    }

    /**
     * Explicación larga, para el `title` del enlace. El badge tiene sitio para
     * cuatro palabras; el motivo va aquí.
     */
    public function tooltipKey(): string
    {
        switch ($this->state()) {
            case self::STATE_NOT_CONFIGURED:
                return 'Your store is not registered with Trusteed yet. Agents cannot find it. Click to finish setup.';
            case self::STATE_ENFORCEMENT_INCOMPLETE:
                return 'Your store is registered, but checkout enforcement is not active: every checkout is allowed through without evaluating a single rule. Ask Trusteed for your Installation ID and HMAC secret.';
            default:
                return 'Your store is registered and checkout enforcement is active.';
        }
    }
}
