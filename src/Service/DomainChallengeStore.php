<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * El token que se publica en `.well-known/amcp-verify.txt` — auditoría de
 * onboarding 2026-09-09, hueco A3.
 *
 * ## Para qué
 *
 * `/v1/embed/ps/auto-register` respondía `409 STORE_ALREADY_REGISTERED` sin
 * salida a quien no tuviera la `install_key`: como el alta nueva no prueba
 * propiedad del dominio, la primera petición que llegue con una URL se queda la
 * tienda, y el dueño real recibía para siempre «provide the install key» — una
 * clave que nunca tuvo.
 *
 * Ahora el backend emite un reto y el módulo lo publica en la raíz del dominio.
 * Quien controla el dominio puede hacerlo; quien sólo conoce la URL, no. Es el
 * mismo mecanismo que ya usa el reconecte de WooCommerce, no uno nuevo.
 *
 * ## Por qué es una clase y no dos `Configuration::get` en el controlador
 *
 * Un front controller de PrestaShop no se puede ejercitar en un test sin
 * levantar medio framework. Lo que hay que fijar —qué se sirve, con qué forma
 * exacta y hasta cuándo— es lógica pura sobre dos valores, y así tiene tests.
 * No lee `Configuration` por su cuenta: quien la construye le pasa los dos.
 */
final class DomainChallengeStore
{
    /** Clave de `Configuration` donde vive el token en curso. */
    public const CONFIG_TOKEN = 'TRUSTEED_DOMAIN_CHALLENGE_TOKEN';

    /** Clave de `Configuration` con el instante (epoch) en que se recibió. */
    public const CONFIG_ISSUED_AT = 'TRUSTEED_DOMAIN_CHALLENGE_AT';

    /**
     * Ventana de publicación, en segundos.
     *
     * El reto del backend caduca a los 10 minutos. Aquí se usan 12 para que el
     * margen juegue a favor del comerciante: si nuestra ventana fuera la más
     * corta, el canje fallaría por culpa nuestra y el mensaje de error hablaría
     * del token, no del reloj. Pasado ese plazo se deja de publicar, porque un
     * secreto expuesto sin utilidad es sólo superficie.
     */
    private const WINDOW_SECONDS = 12 * 60;

    /** Forma exacta que emite el backend: 32 bytes en hexadecimal minúscula. */
    private const TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/';

    private string $token;
    private int $issuedAt;

    public function __construct(string $token, int $issuedAt)
    {
        $this->token = trim($token);
        $this->issuedAt = $issuedAt;
    }

    /** ¿Hay un reto vigente y bien formado que publicar? */
    public function isServable(): bool
    {
        if (preg_match(self::TOKEN_PATTERN, $this->token) !== 1) {
            return false;
        }

        $age = time() - $this->issuedAt;

        // Negativo ⇒ marca de tiempo en el futuro: un reloj adelantado o una
        // escritura manipulada no deben conceder una ventana mayor que la real.
        if ($age < 0) {
            return false;
        }

        return $age <= self::WINDOW_SECONDS;
    }

    /**
     * Cuerpo exacto del fichero. El verificador compara el contenido COMPLETO
     * en tiempo constante tras un `trim`, así que aquí no puede colarse ni un
     * salto de línea de más ni una etiqueta HTML: sería un fallo de
     * verificación cuya causa el comerciante no podría adivinar.
     */
    public function body(): string
    {
        return $this->isServable() ? $this->token : '';
    }
}
