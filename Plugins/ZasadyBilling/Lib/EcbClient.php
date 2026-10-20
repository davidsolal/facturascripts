<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

final class EcbClient
{
    private const URL = 'https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A'
        . '?startPeriod=%s&endPeriod=%s&format=csvdata';
    private const MAX_AGE_DAYS = 5;

    public function rateFor(string $isoDate): ?Rate
    {
        $start = (new \DateTimeImmutable($isoDate))->modify('-14 days')->format('Y-m-d');
        $url = sprintf(self::URL, $start, $isoDate);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($ch);
        if ($body === false || curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
            return null;
        }
        return self::parseCsv((string)$body, $isoDate);
    }

    /** Picks the last publication on or before $isoDate; rejects anything older than MAX_AGE_DAYS. */
    public static function parseCsv(string $csv, string $isoDate): ?Rate
    {
        $limit = new \DateTimeImmutable($isoDate);
        $best = null;
        $dateCol = 0;
        $rateCol = 1;
        foreach (explode("\n", $csv) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if ($i === 0) {
                // The live ECB "csvdata" export is a wide SDMX CSV (KEY, FREQ, CURRENCY, ...,
                // TIME_PERIOD, OBS_VALUE, OBS_STATUS, ...) so the date/rate positions vary by
                // query. Locate them by header name instead of assuming columns 0/1; fall back
                // to 0/1 for the brief's minimal TIME_PERIOD,OBS_VALUE fixture, which has no
                // other columns to look up.
                $timeIdx = array_search('TIME_PERIOD', $cols, true);
                $valueIdx = array_search('OBS_VALUE', $cols, true);
                if ($timeIdx !== false) {
                    $dateCol = $timeIdx;
                }
                if ($valueIdx !== false) {
                    $rateCol = $valueIdx;
                }
                continue;
            }
            if (count($cols) <= max($dateCol, $rateCol)) {
                continue;
            }
            $day = trim($cols[$dateCol]);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || $day > $isoDate) {
                continue;
            }
            $best = ['date' => $day, 'rate' => (float)$cols[$rateCol]];
        }
        if ($best === null || $best['rate'] <= 0.0) {
            return null;
        }
        $age = (new \DateTimeImmutable($limit->format('Y-m-d')))
            ->diff(new \DateTimeImmutable($best['date']))->days;
        if ($age === false || $age > self::MAX_AGE_DAYS) {
            return null;
        }
        return new Rate($best['rate'], $best['date'], 'ecb');
    }
}
