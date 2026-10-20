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
    public function runFor(\DateTimeImmutable $today, bool $force = false): array
    {
        $out = [];
        foreach (Profile::all() as $profile) {
            // Only the configured billing day triggers a run, unless the
            // caller forces it (used by run.php for tests/backfills on an
            // arbitrary date) - this is what keeps the cron idempotent
            // against being invoked more than once a day.
            if ((int)$today->format('j') !== $profile->day && false === $force) {
                continue;
            }

            $date = Period::invoiceDate((int)$today->format('Y'), (int)$today->format('n'), $profile->day);

            // Everything below touches the DB (IssuerGuard::check() loads
            // Cliente / getDefaultAddress) or the network (EcbClient), on
            // top of Issuer::issue() itself - any of them can throw, and a
            // bare \Error/TypeError from one company must never escape this
            // loop: core's plugin-cron runner only catches \Exception, so an
            // uncaught \Throwable here would abort the sweep for every other
            // company (and every other plugin) behind it.
            try {
                // A manual override skips the ECB lookup entirely (and so
                // can never be null); otherwise we trust EcbClient to
                // return null when the published rate is missing or older
                // than it allows, which IssuerGuard::check() below turns
                // into a blocker.
                $rate = $profile->rateOverride > 0
                    ? new Rate($profile->rateOverride, $date, 'manual')
                    : (new EcbClient())->rateFor($date);

                $blockers = IssuerGuard::check($profile, $rate, $date);
                if ($blockers !== []) {
                    $out[$profile->idempresa] = 'skipped: ' . implode('; ', $blockers);
                    continue;
                }

                $result = (new Issuer())->issue($profile, $rate, $date);
                $out[$profile->idempresa] = sprintf(
                    'issued %s (EUR %.2f, rate %s of %s)',
                    $result['codigo'],
                    $result['totaleuros'],
                    $rate->usdPerEur,
                    $rate->dateUsed
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
}
