<?php
/**
 * Reports to Trusteed which cart signals THIS PrestaShop installation can project.
 *
 * Why it exists: the server knows which signal each rule reads
 * (`RULE_SIGNALS_READ`), but not what each installation provides. Without that
 * cross-check, a rule whose signal never arrives returns `NO_SIGNAL` on every
 * checkout: it passes silently, and the merchant sees a rule in ENFORCE that
 * blocks nothing. With the report, the panel can warn them when they enable it.
 *
 * When it is sent: when the module version changes, and also every 7 days. The
 * server treats a store whose `lastSeenAt` is 30 days old as dead and this
 * report is what renews it; with a single report per release, a quiet store
 * would expire. The check is a `Configuration` read, fired from a back-office
 * hook and from a front hook (the latter AFTER the response is sent). A failed
 * attempt waits an hour before retrying.
 *
 * Signature: same scheme as `PaymentFailureEmitter`, HMAC-SHA256 over the
 * canonical JSON of the body without `signature`. The server verifies against
 * the body as received, so the order of the signals array does not matter.
 *
 * The signal list comes from `ValidateOrderHook::signalsProvided()`, which a
 * shared gate keeps in line with what the context builder really writes.
 */

declare(strict_types=1);

namespace Trusteed\Enforcement;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CapabilitiesReporter
{
    private const ENDPOINT_PATH = '/api/v1/enforcement/capabilities';

    /** Key holding the last reported version. */
    private const CONFIG_REPORTED_VERSION = 'TRUSTEED_CEL_CAPS_REPORTED_VERSION';

    /** Epoch of the last accepted report. */
    private const CONFIG_REPORTED_AT = 'TRUSTEED_CEL_CAPS_REPORTED_AT';

    /** Epoch of the last attempt, successful or not (backoff). */
    private const CONFIG_ATTEMPT_AT = 'TRUSTEED_CEL_CAPS_ATTEMPT_AT';

    /** Heartbeat renewal even when nothing changed. */
    private const RENEW_AFTER_SECONDS = 7 * 86400;

    /** Wait after an attempt, so an unresponsive server does not cost a timeout on every page load. */
    private const RETRY_AFTER_SECONDS = 3600;

    /**
     * Is a report due? Pure: no Configuration or clock access.
     */
    public static function isDue(string $version, string $reportedVersion, int $reportedAt, int $attemptAt, int $now): bool
    {
        if ($attemptAt > 0 && $now - $attemptAt < self::RETRY_AFTER_SECONDS) {
            return false;
        }
        if ($reportedVersion !== $version) {
            return true;
        }

        return $reportedAt <= 0 || $now - $reportedAt >= self::RENEW_AFTER_SECONDS;
    }

    private static function due(string $moduleVersion): bool
    {
        return self::isDue(
            $moduleVersion,
            (string) \Configuration::get(self::CONFIG_REPORTED_VERSION),
            (int) \Configuration::get(self::CONFIG_REPORTED_AT),
            (int) \Configuration::get(self::CONFIG_ATTEMPT_AT),
            time()
        );
    }

    /**
     * Sends the report if the version changed or 7 days passed since the last one.
     *
     * The server also deduplicates (it answers `unchanged: true` without writing
     * the content, but does renew the heartbeat), and checking here avoids an
     * HTTP request on every page load.
     */
    public static function maybeReport(string $moduleVersion): void
    {
        try {
            if (!self::due($moduleVersion)) {
                return;
            }
            // Before the request: if the server is slow or down, do not retry on every page load.
            \Configuration::updateValue(self::CONFIG_ATTEMPT_AT, (string) time());
            if (self::report($moduleVersion)) {
                \Configuration::updateValue(self::CONFIG_REPORTED_VERSION, $moduleVersion);
                \Configuration::updateValue(self::CONFIG_REPORTED_AT, (string) time());
            }
        } catch (\Throwable $e) {
            // A diagnostic that fails to arrive must never break the back office.
        }
    }

    /**
     * Like {@see maybeReport()} but for front hooks: the HTTP request (up to
     * 3 s) runs at the end of the request, after the response was sent to the visitor.
     */
    public static function maybeReportAfterResponse(string $moduleVersion): void
    {
        try {
            if (!self::due($moduleVersion)) {
                return;
            }
            register_shutdown_function(static function () use ($moduleVersion): void {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                self::maybeReport($moduleVersion);
            });
        } catch (\Throwable $e) {
            // Idem.
        }
    }

    /**
     * @return bool true if the server accepted the report (202).
     */
    public static function report(string $moduleVersion): bool
    {
        $merchantId     = (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        $installationId = (string) \Configuration::get('TRUSTEED_CEL_INSTALLATION_ID');
        $hmacSecret     = (string) \Configuration::get('TRUSTEED_CEL_HMAC_SECRET');
        $apiBase        = (string) \Configuration::get('TRUSTEED_API_BASE');

        if ($merchantId === '' || $installationId === '' || $hmacSecret === '' || $apiBase === '') {
            return false;
        }

        $signals = [];
        foreach (ValidateOrderHook::signalsProvided() as $attr) {
            // The server vocabulary prefixes with `cartAttr.`; the module
            // works with the raw cart attribute key.
            $signals[] = 'cartAttr.' . $attr;
        }

        $body = [
            'installationId'  => $installationId,
            'merchantId'      => $merchantId,
            'platform'        => 'PRESTASHOP',
            'pluginVersion'   => $moduleVersion,
            'signalsProvided' => $signals,
        ];
        $body['signature'] = self::hmacSign($body, $hmacSecret);

        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!function_exists('curl_init') || $payload === false) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, rtrim($apiBase, '/') . self::ENDPOINT_PATH);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Trusteed-PS/2.0',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 3000);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1000);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        @curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status === 202;
    }

    private static function hmacSign(array $body, string $secret): string
    {
        $sorted    = self::recursiveKsort($body);
        $canonical = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $canonical, $secret);
    }

    /**
     * Sorts keys recursively. Lists (such as `signalsProvided`) keep their
     * order on purpose: the server signs against the body as received, not
     * against the one it normalizes.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function recursiveKsort($value)
    {
        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $k => $v) {
                $value[$k] = self::recursiveKsort($v);
            }
        }

        return $value;
    }
}
