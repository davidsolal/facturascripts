<?php
// Plugins/ZasadyBilling/migrate-rate-keys.php
// One-off migration: company-scope the rate metadata keys in the Settings group
// RateToken::GROUP (zasady-rate).
//
// Why it is needed: RateToken::key() used to be the bare invoice code. Invoice
// codes are unique per company only - both legal entities number their own F26
// series from 10, so both September invoices are F2600010 - which means one
// settings entry was shared by two different legal documents. Both hold the
// same rate today, so nothing was visibly wrong; a manual override written for
// one company would have been printed on the other company's invoice.
//
// This splits every bare key into one entry per company that actually owns an
// invoice with that code, taking each invoice's own idempresa, and then removes
// the bare key so no stale entry is left behind.
//
// Idempotent: already-scoped keys are ignored, an existing scoped entry is
// never overwritten, and a bare key that cannot be attributed to at least one
// invoice is left untouched with an error - inventing a company would put one
// company's rate on another company's document.
//
// Run with --dry-run to only report.
//
// Usage:
//   php Plugins/ZasadyBilling/migrate-rate-keys.php [--dry-run]
declare(strict_types=1);

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Model\Settings;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ZasadyBilling\Lib\RateToken;

define('FS_FOLDER', dirname(__DIR__, 2));
require FS_FOLDER . '/config.php';
require FS_FOLDER . '/vendor/autoload.php';

Kernel::init();

$dryRun = in_array('--dry-run', $argv, true);

$db = new DataBase();
if (false === $db->connect()) {
    fwrite(STDERR, 'error: cannot connect to the database' . PHP_EOL);
    exit(1);
}

// Read the group through the model core itself uses (Tools::settingsLoad() does
// the same Settings::all()): the properties column holds the whole key => value
// map, so the split has to rewrite that map in one go.
$group = null;
foreach (Settings::all() as $item) {
    if ($item->name === RateToken::GROUP) {
        $group = $item;
        break;
    }
}

if (null === $group) {
    printf("nothing to do: no settings group %s%s", RateToken::GROUP, PHP_EOL);
    exit(0);
}

$properties = $group->getProperties();
if ($properties === []) {
    printf("nothing to do: settings group %s is empty%s", RateToken::GROUP, PHP_EOL);
    exit(0);
}

$errors = 0;
$splits = 0;
$removed = 0;

foreach ($properties as $key => $value) {
    // A scoped key looks like "<idempresa>-<codigo>"; anything else is a legacy
    // bare code.
    if (preg_match('/^[1-9]\d*-/', (string)$key) === 1) {
        continue;
    }

    $codigo = (string)$key;
    $rows = $db->select(
        'SELECT DISTINCT idempresa FROM facturascli'
        . " WHERE codigo = '" . $db->escapeString($codigo) . "'"
        . ' ORDER BY idempresa;'
    );

    if ($rows === []) {
        fwrite(STDERR, sprintf(
            'error: legacy key %s matches no invoice in any company; left in place (%s)%s',
            $codigo,
            json_encode($value, JSON_UNESCAPED_SLASHES),
            PHP_EOL
        ));
        $errors++;
        continue;
    }

    if (null === RateToken::decode(is_string($value) ? $value : null)) {
        fwrite(STDERR, sprintf('warning: legacy key %s holds an unreadable value, copied as-is%s', $codigo, PHP_EOL));
    }

    foreach ($rows as $row) {
        $idempresa = (int)$row['idempresa'];
        $scoped = RateToken::key($idempresa, $codigo);
        if ($scoped === '') {
            fwrite(STDERR, sprintf('error: cannot build a key for %s/%d%s', $codigo, $idempresa, PHP_EOL));
            $errors++;
            continue;
        }

        if (array_key_exists($scoped, $properties)) {
            printf("company %d: %s already present, left untouched%s", $idempresa, $scoped, PHP_EOL);
            continue;
        }

        if ($dryRun) {
            printf("company %d: would store %s = %s%s", $idempresa, $scoped, json_encode($value, JSON_UNESCAPED_SLASHES), PHP_EOL);
        } else {
            $properties[$scoped] = $value;
        }
        $splits++;
    }

    if ($dryRun) {
        printf("would remove legacy key %s%s", $codigo, PHP_EOL);
    } else {
        unset($properties[$key]);
    }
    $removed++;
}

if ($splits === 0 && $removed === 0) {
    printf("nothing to do: every key in %s is already company-scoped%s", RateToken::GROUP, PHP_EOL);
    exit($errors === 0 ? 0 : 1);
}

if ($dryRun) {
    printf(
        'dry run: %d key(s) split into %d company-scoped entr(ies), %d legacy key(s) to remove, %d error(s)%s',
        $removed,
        $splits,
        $removed,
        $errors,
        PHP_EOL
    );
    exit($errors === 0 ? 0 : 1);
}

$group->setProperties($properties);
if (false === $group->save()) {
    fwrite(STDERR, sprintf('error: could not save settings group %s%s', RateToken::GROUP, PHP_EOL));
    exit(1);
}

// Tools::settings() is served from the FileCache (key tools-settings, 1 hour),
// so a write that leaves the cache in place is invisible to the very next CLI
// render. Drop it here: the export must read the split keys, not the old map.
Tools::settingsClear();

printf(
    'done: %d legacy key(s) split into %d company-scoped entr(ies), %d legacy key(s) removed, %d error(s)%s',
    $removed,
    $splits,
    $removed,
    $errors,
    PHP_EOL
);

exit($errors === 0 ? 0 : 1);
