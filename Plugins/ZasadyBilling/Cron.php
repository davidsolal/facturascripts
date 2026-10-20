<?php

namespace FacturaScripts\Plugins\ZasadyBilling;

use FacturaScripts\Core\Template\CronJobClass;
use FacturaScripts\Plugins\ZasadyBilling\Lib\MonthlyRun;

/**
 * Cron entry point core discovers and calls as `(new Cron($pluginName))->run()`.
 * CronJobClass::run() is declared `abstract public static function run(): void`,
 * so this MUST be static too (an instance override would be a fatal PHP
 * error) - PHP allows calling a static method through an instance, which is
 * exactly how core invokes it. Holds no state of its own: all the actual
 * work lives in MonthlyRun, which this and run.php (the CLI backfill runner)
 * both delegate to, so the two never drift apart.
 */
final class Cron extends CronJobClass
{
    public const JOB_NAME = 'zasady-billing';

    public static function run(): void
    {
        foreach ((new MonthlyRun())->runFor(new \DateTimeImmutable('today')) as $idempresa => $line) {
            echo '[' . $idempresa . '] ' . $line . PHP_EOL;
        }
    }
}
