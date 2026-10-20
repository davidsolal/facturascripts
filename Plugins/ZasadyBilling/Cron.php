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
        // CronJobClass::echo()/saveEcho() are protected static, not
        // private, so this subclass can call them directly: echo() both
        // prints (in ECHO_MODE_FULL, the core runner's default) and
        // accumulates, and saveEcho() persists the accumulated text to the
        // LogMessage table under JOB_NAME so outcomes show up in the admin
        // cron screen instead of only on stdout.
        foreach ((new MonthlyRun())->runFor(new \DateTimeImmutable('today')) as $idempresa => $line) {
            self::echo('[' . $idempresa . '] ' . $line . PHP_EOL);
        }
        self::saveEcho();
    }
}
