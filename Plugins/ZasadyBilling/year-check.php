<?php
// Plugins/ZasadyBilling/year-check.php
// READ-ONLY preflight: for each company, prints what YearSetup::ensure()
// would do for the given invoice date - the exercise code, whether it
// already exists and is open, whether a FacturaCliente sequence exists for
// it, and whether it has a chart of accounts - without creating, saving or
// otherwise touching anything. Run this ahead of a new year's first billing
// day to see the gap (or confirm there isn't one) before MonthlyRun does.
// Usage: docker exec facturascripts-app php /var/www/html/Plugins/ZasadyBilling/year-check.php YYYY-MM-DD
declare(strict_types=1);

define('FS_FOLDER', dirname(__DIR__, 2));
require FS_FOLDER . '/config.php';
require FS_FOLDER . '/vendor/autoload.php';

use FacturaScripts\Core\Model\Empresa;
use FacturaScripts\Plugins\ZasadyBilling\Lib\Profile;
use FacturaScripts\Plugins\ZasadyBilling\Lib\YearSetup;

\FacturaScripts\Core\Kernel::init();

$date = $argv[1] ?? null;
if (null === $date || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, 'Usage: year-check.php YYYY-MM-DD' . PHP_EOL);
    exit(1);
}

foreach ((new Empresa())->all([], ['idempresa' => 'ASC']) as $empresa) {
    $idempresa = (int)$empresa->idempresa;
    $profile = Profile::load($idempresa);

    if ($profile->codserie === '') {
        echo sprintf('[company %d] no billing series configured, skipping' . PHP_EOL, $idempresa);
        continue;
    }

    $info = YearSetup::preview($idempresa, $profile->codserie, $date);

    echo sprintf(
        '[company %d] series %s, date %s -> exercise %s (exists: %s, open: %s) | '
        . 'sequence: %s | chart of accounts: %s (%d subcuentas) | pattern %s, longnumero %d'
        . PHP_EOL,
        $idempresa,
        $profile->codserie,
        $date,
        $info['codejercicio'],
        $info['exerciseExists'] ? 'yes' : 'no',
        $info['exerciseOpen'] ? 'yes' : 'no',
        $info['sequenceExists'] ? 'present' : 'MISSING',
        $info['accountingPlan'] ? 'present' : 'MISSING',
        $info['subcuentaCount'],
        $info['pattern'],
        $info['longnumero']
    );
}
