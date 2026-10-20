<?php
// Plugins/ZasadyBilling/run.php
// CLI entry point for tests and backfills - exercises the same MonthlyRun
// the real cron job uses, but lets an operator force a run on any date
// without waiting for the configured billing day or the cron scheduler.
// Usage: docker exec facturascripts-app php /var/www/html/Plugins/ZasadyBilling/run.php [--force] [YYYY-MM-DD]
declare(strict_types=1);

define('FS_FOLDER', dirname(__DIR__, 2));
require FS_FOLDER . '/config.php';
require FS_FOLDER . '/vendor/autoload.php';

\FacturaScripts\Core\Kernel::init();

// Order-independent: walk every arg after the script name so
// `run.php --force 2026-09-19` and `run.php 2026-09-19 --force` behave the
// same; the previous end($argv) lookup silently dropped the date whenever
// --force came last. Unknown tokens are ignored.
//
// Two distinct dates:
// - a bare YYYY-MM-DD (or none) sets the "today" clock the sweep runs on;
// - --date=YYYY-MM-DD pins the INVOICE date itself, overriding the profiles'
//   configured billing day (19th) without touching it. Use it for the first
//   issuance, a backfill, or any month the operator names the date - the
//   monthly cron never passes it.
$force = false;
// Server-independent date: the container clock is UTC while the operator is
// UTC+2, so "today" must be resolved in the billing timezone (Period::today()),
// not from DateTimeImmutable('today') which follows PHP's UTC default.
$date = \FacturaScripts\Plugins\ZasadyBilling\Lib\Period::today();
$invoiceDate = null;
foreach (array_slice($argv, 1) as $token) {
    if ($token === '--force') {
        $force = true;
        continue;
    }
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $token, $m) === 1) {
        $invoiceDate = new \DateTimeImmutable($m[1]);
        continue;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token) === 1) {
        $date = new \DateTimeImmutable($token);
    }
}

foreach ((new \FacturaScripts\Plugins\ZasadyBilling\Lib\MonthlyRun())->runFor($date, $force, $invoiceDate) as $id => $line) {
    echo '[' . $id . '] ' . $line . PHP_EOL;
}
