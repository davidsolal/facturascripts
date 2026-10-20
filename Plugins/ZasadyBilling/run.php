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

$force = in_array('--force', $argv, true);
$arg = end($argv);
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$arg) === 1
    ? new \DateTimeImmutable((string)$arg)
    : new \DateTimeImmutable('today');

foreach ((new \FacturaScripts\Plugins\ZasadyBilling\Lib\MonthlyRun())->runFor($date, $force) as $id => $line) {
    echo '[' . $id . '] ' . $line . PHP_EOL;
}
