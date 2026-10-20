<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

/**
 * Machine-readable record of the exchange rate actually used for an invoice,
 * stored inside the invoice's own `observaciones` together with the human
 * sentence that the client reads.
 *
 * Why a token and not a new column: `facturascli` is core schema and the plugin
 * owns no tables, so the only durable per-invoice slot it can write is
 * `observaciones`. Why it must carry both the publication date and the source:
 * the printed conversion must state the date the rate was actually published
 * (ECB publishes only on TARGET business days, so a Saturday invoice uses
 * Friday's rate) and must not claim "ECB" when the operator supplied a manual
 * override. Deriving either from `fecha` or from a hardcoded string - the
 * previous behaviour - printed a date that was not the one used.
 *
 * The token is written by Issuer and read by Lib\Export\ZasadyExport. It is
 * stripped before the observations are printed, so it never reaches a client.
 *
 * Format: [zasady-rate]{"date":"2026-09-18","source":"ecb"}[/zasady-rate]
 */
final class RateToken
{
    public const OPEN = '[zasady-rate]';
    public const CLOSE = '[/zasady-rate]';

    private const PATTERN = '/\[zasady-rate\]\s*(\{.*?\})\s*\[\/zasady-rate\]/s';

    /**
     * Appends the token to the human observation text. Returns the text
     * unchanged if the JSON cannot be encoded: a missing token degrades to
     * "print the invoice date, no source label", which is exactly the
     * fallback for older invoices, and is far better than writing a malformed
     * token into an immutable document.
     */
    public static function embed(string $observaciones, Rate $rate): string
    {
        $json = json_encode(
            ['date' => $rate->dateUsed, 'source' => $rate->source],
            JSON_UNESCAPED_SLASHES
        );
        if (false === $json) {
            return $observaciones;
        }

        return rtrim($observaciones) . ' ' . self::OPEN . $json . self::CLOSE;
    }

    /**
     * Removes the token and the whitespace it leaves behind, for printing.
     */
    public static function strip(?string $observaciones): string
    {
        $text = (string)$observaciones;
        $clean = preg_replace(self::PATTERN, '', $text);
        if (null === $clean) {
            return trim($text);
        }

        return trim((string)preg_replace('/[ \t]{2,}/', ' ', $clean));
    }

    /**
     * @return array{date: string, source: string}|null null when the invoice
     *     predates the token or carries a malformed one.
     */
    public static function parse(?string $observaciones): ?array
    {
        if (preg_match(self::PATTERN, (string)$observaciones, $matches) !== 1) {
            return null;
        }

        // Core's BusinessDocument::test() runs Tools::noHtml() over
        // observaciones on save, which turns every `"` into `&quot;` - so the
        // token as stored in the database is HTML-escaped even though embed()
        // wrote plain JSON. Decode before parsing, otherwise every real invoice
        // (as opposed to a freshly built object) would fall back to the invoice
        // date. html_entity_decode is the inverse of what core applied and does
        // not depend on this plugin knowing core's exact replacement table.
        $json = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['date'], $data['source'])) {
            return null;
        }

        $date = (string)$data['date'];
        $source = (string)$data['source'];
        // A token whose date is not ISO cannot be printed as a rate date, so
        // treat it as absent rather than printing something arbitrary.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $source === '') {
            return null;
        }

        return ['date' => $date, 'source' => $source];
    }

    /**
     * Client-facing label for a rate source. Unknown values are printed as
     * "<source> rate" instead of being silently labelled ECB.
     */
    public static function sourceLabel(string $source): string
    {
        switch ($source) {
            case Rate::SOURCE_ECB:
                return 'ECB reference rate';
            case Rate::SOURCE_MANUAL:
                return 'manual rate';
            default:
                return $source . ' rate';
        }
    }
}
