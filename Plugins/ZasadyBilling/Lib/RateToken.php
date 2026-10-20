<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

/**
 * Machine-readable record of the exchange rate actually used for an invoice:
 * the ECB publication date (which differs from the invoice date whenever the
 * invoice is dated on a weekend or a TARGET holiday) and the source (ECB or an
 * operator override), so the printed conversion never claims "ECB" for a
 * manual rate.
 *
 * Stored in core Settings - group GROUP, key = the invoice `codigo`, value =
 * compact JSON `{"date":"YYYY-MM-DD","source":"ecb|manual"}` - and NOT in
 * `facturascli.observaciones`. That column is core schema and is printed
 * verbatim by the stock PDFDocument, so any machine-readable payload kept
 * there reached client documents through every render path except ours (the
 * stock "PDF" option emitted `[zasady-rate]{...}` into an invoice). The invoice
 * therefore carries the human sentence only; the metadata lives beside it in
 * Settings, where no template prints it.
 *
 * Why Settings and not a new column: the plugin owns no tables (see
 * Profile/Period, which persist the same way), and Settings needs no
 * migration for a per-invoice key-value.
 *
 * encode()/decode() are pure, so the store/read round-trip is unit-testable
 * without a database. The Settings write/read themselves are exercised by the
 * acceptance re-render.
 *
 * strip()/parseLegacy() only understand the historical
 * `[zasady-rate]{json}[/zasady-rate]` suffix that invoices issued before this
 * change still carry inside `observaciones`; they exist for the one-off
 * migration and as a defence for an unmigrated database, and are not used to
 * store anything.
 */
final class RateToken
{
    /** Settings group holding one entry per invoice code. */
    public const GROUP = 'zasady-rate';

    /** Historical wire format, kept only for cleanup and defence. */
    public const OPEN = '[zasady-rate]';
    public const CLOSE = '[/zasady-rate]';

    private const PATTERN = '/\[zasady-rate\]\s*(\{.*?\})\s*\[\/zasady-rate\]/s';

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * Settings key for an invoice. The code is already unique per company
     * series, and it is what the exporter has on hand.
     */
    public static function key(string $codigo): string
    {
        return trim($codigo);
    }

    /**
     * Compact JSON for the Settings value, or null when the rate cannot be
     * represented (non-ISO date, empty source). Null means "store nothing":
     * a missing entry degrades to printing the invoice date, which is exactly
     * the fallback for invoices issued before the metadata existed, and is far
     * better than persisting a value that decode() would reject.
     */
    public static function encode(Rate $rate): ?string
    {
        if (preg_match(self::DATE_PATTERN, $rate->dateUsed) !== 1 || $rate->source === '') {
            return null;
        }

        $json = json_encode(
            ['date' => $rate->dateUsed, 'source' => $rate->source],
            JSON_UNESCAPED_SLASHES
        );

        return false === $json ? null : $json;
    }

    /**
     * @return array{date: string, source: string}|null null when the invoice
     *     has no metadata (older invoice) or it is malformed/unreadable.
     */
    public static function decode(?string $stored): ?array
    {
        $raw = trim((string)$stored);
        if ($raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        if (!isset($data['date'], $data['source']) || !is_string($data['date']) || !is_string($data['source'])) {
            return null;
        }

        // A value whose date is not ISO cannot be printed as a rate date, so
        // treat it as absent rather than printing something arbitrary.
        if (preg_match(self::DATE_PATTERN, $data['date']) !== 1 || $data['source'] === '') {
            return null;
        }

        return ['date' => $data['date'], 'source' => $data['source']];
    }

    /**
     * Removes the historical token and the whitespace it leaves behind, for
     * printing. Legacy only: nothing writes it any more.
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
     * Rate metadata carried by the historical token inside observaciones.
     *
     * @return array{date: string, source: string}|null
     */
    public static function parseLegacy(?string $observaciones): ?array
    {
        if (preg_match(self::PATTERN, (string)$observaciones, $matches) !== 1) {
            return null;
        }

        // Core's BusinessDocument::test() runs Tools::noHtml() over
        // observaciones on save, which turns every `"` into `&quot;` - so the
        // token as stored in the database is HTML-escaped even though it was
        // written as plain JSON. html_entity_decode is the inverse of what
        // core applied and does not depend on this plugin knowing core's exact
        // replacement table.
        return self::decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5));
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
