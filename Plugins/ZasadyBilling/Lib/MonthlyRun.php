<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

/**
 * The actual monthly billing sweep: one pass over every company's Profile,
 * issuing at most one invoice per company per calendar month. Kept separate
 * from Cron so the cron entry point and the CLI backfill runner (run.php)
 * share one implementation instead of duplicating the loop/guard/issue logic.
 */
final class MonthlyRun
{
    /**
     * @return array<int, string> human-readable outcome per company, keyed
     *     by idempresa so callers/tests can look up a specific company.
     */
    public function runFor(\DateTimeImmutable $today, bool $force = false, ?\DateTimeImmutable $invoiceDate = null): array
    {
        $out = [];
        foreach (Profile::all() as $profile) {
            // Catch-up, not a single shot on one day: the run fires when the
            // configured billing day has been reached, and the period marker
            // (plus IssuerGuard's existing-invoice check) is what makes it
            // idempotent. Requiring an exact day match meant that a failed or
            // missed 19th was never retried, so the month was simply lost.
            // The invoice date below is still the configured billing day of
            // the current month, never "today", so a late run cannot produce a
            // future-dated invoice. An explicit --date override skips this
            // check: the operator has named the date, not the calendar.
            if ($invoiceDate === null
                && (int)$today->format('j') < $profile->day
                && false === $force) {
                continue;
            }

            $date = self::resolveInvoiceDate($today, $profile->day, $invoiceDate);

            // Everything below touches the DB (IssuerGuard::check() loads
            // Cliente / getDefaultAddress) or the network (EcbClient), on
            // top of Issuer::issue() itself - any of them can throw, and a
            // bare \Error/TypeError from one company must never escape this
            // loop: core's plugin-cron runner only catches \Exception, so an
            // uncaught \Throwable here would abort the sweep for every other
            // company (and every other plugin) behind it.
            try {
                // Provision the exercise and its FacturaCliente sequence for
                // this profile's series/date BEFORE anything else runs, so
                // a brand-new year never reaches core's own silent
                // auto-create (BusinessDocument::save() ->
                // Ejercicio::loadFromDate(..., create:true), then
                // BusinessDocumentCode::getSequence()'s default pattern) -
                // see YearSetup's docblock for exactly what that loses.
                // Skipped only when there is no series to provision for:
                // IssuerGuard::check() below already reports that profile
                // state as 'client or series missing', and creating a
                // sequence keyed on an empty codserie would be a garbage
                // row for a profile that was never going to issue anyway.
                $codejercicio = $profile->codserie !== ''
                    ? YearSetup::ensure($profile->idempresa, $profile->codserie, $date)
                    : null;

                // A manual override skips the ECB lookup entirely (and so
                // can never be null); otherwise we trust EcbClient to
                // return null when the published rate is missing or older
                // than it allows, which IssuerGuard::check() below turns
                // into a blocker.
                $rate = $profile->rateOverride > 0
                    ? new Rate($profile->rateOverride, $date, Rate::SOURCE_MANUAL)
                    : (new EcbClient())->rateFor($date);

                $blockers = IssuerGuard::check($profile, $rate, $date, $codejercicio);
                if ($blockers !== []) {
                    $out[$profile->idempresa] = 'skipped: ' . implode('; ', $blockers);
                    continue;
                }

                $result = (new Issuer())->issue($profile, $rate, $date);
                $out[$profile->idempresa] = sprintf(
                    'issued %s (EUR %.2f, rate %s of %s)%s',
                    $result['codigo'],
                    $result['totaleuros'],
                    $rate->usdPerEur,
                    $rate->dateUsed,
                    self::rateDateWarning($rate, $date)
                );
            } catch (\Throwable $e) {
                // Never let one company's failure (bad series, missing tax,
                // a core validation rejection, a DB error from the guard, a
                // network error from the rate lookup) abort the sweep for
                // the rest.
                $out[$profile->idempresa] = 'error: ' . $e->getMessage();
            }
        }
        return $out;
    }

    /**
     * Warns when a TARGET business day was invoiced with an older rate.
     *
     * The spec allows the previous publication only for days without one
     * (weekend or TARGET holiday). On a business day the ECB did publish, so a
     * different dateUsed means the lookup ran before publication or the feed
     * was incomplete: the invoice is still issued (it is already correct enough
     * to be legal, and blocking would lose the month again) but the operator
     * must see it.
     */
    public static function rateDateWarning(Rate $rate, string $invoiceDate): string
    {
        if ($rate->dateUsed === $invoiceDate || false === TargetCalendar::isBusinessDay($invoiceDate)) {
            return '';
        }

        return sprintf(
            ' | WARNING: no rate for %s, a TARGET business day; used the publication of %s',
            $invoiceDate,
            $rate->dateUsed
        );
    }

    /**
     * The date an invoice must carry. Without an operator override it is the
     * profile's configured billing day of $today's month (never "today", so a
     * late catch-up run cannot produce a future-dated invoice). An explicit
     * override wins and needs no billing-day check: it is the operator naming
     * the date - the first issuance, a backfill, a contractual change - not the
     * calendar deciding.
     *
     * Static and public so the rule can be unit-tested without a database: the
     * sweep itself needs one, this decision does not.
     */
    public static function resolveInvoiceDate(\DateTimeImmutable $today, int $day, ?\DateTimeImmutable $override): string
    {
        if ($override !== null) {
            return $override->format('Y-m-d');
        }

        return Period::invoiceDate((int)$today->format('Y'), (int)$today->format('n'), $day);
    }
}
