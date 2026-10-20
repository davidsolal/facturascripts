<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

/**
 * TARGET (eurosystem) business-day calendar.
 *
 * The ECB publishes the euro reference rates only on TARGET business days, so
 * a rate whose publication date differs from the invoice date is only
 * legitimate when the invoice date itself was not a business day. Callers use
 * this to tell the two cases apart and warn when a business day was invoiced
 * with an older rate (a real data problem: the rate for that day should exist).
 *
 * Closing days are the ones TARGET actually observes, not the full Spanish
 * holiday list: 1 January, Good Friday, Easter Monday, 1 May, 25 and 26
 * December, plus Saturdays and Sundays.
 */
final class TargetCalendar
{
    /** Month-day pairs, applied every year (MM-DD). */
    private const FIXED_CLOSING_DAYS = ['01-01', '05-01', '12-25', '12-26'];

    public static function isBusinessDay(string $isoDate): bool
    {
        $date = (new \DateTimeImmutable($isoDate))->setTime(0, 0);

        // N: 1 (Monday) .. 7 (Sunday)
        if ((int)$date->format('N') >= 6) {
            return false;
        }

        if (in_array($date->format('m-d'), self::FIXED_CLOSING_DAYS, true)) {
            return false;
        }

        $easter = self::easterSunday((int)$date->format('Y'));
        if ($date == $easter->modify('-2 days') || $date == $easter->modify('+1 day')) {
            // Good Friday and Easter Monday.
            return false;
        }

        return true;
    }

    /**
     * Gregorian Easter Sunday (Meeus/Jones/Butcher). Computed here instead of
     * using easter_date()/easter_days() so the calendar does not depend on the
     * ext-calendar extension being compiled into the image.
     */
    public static function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
