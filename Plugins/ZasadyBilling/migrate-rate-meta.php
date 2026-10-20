<?php
// Plugins/ZasadyBilling/migrate-rate-meta.php
// One-off migration: move the machine-readable rate metadata out of
// `facturascli.observaciones` into core Settings (group RateToken::GROUP,
// key = idempresa + invoice codigo), and clear it out of the printed field.
//
// Why it is needed: invoices issued before the metadata moved to Settings carry
// a `[zasady-rate]{...}[/zasady-rate]` suffix in observaciones, and core's stock
// PDFDocument prints that column verbatim - the stock "PDF" export of those
// invoices emits the raw token into a client document. Our own export strips it,
// but a legal document must not depend on which print option was used.
//
// Idempotent: rows are selected by the presence of the token, an existing
// (decodable) settings entry is never overwritten, and nothing is written
// unless the token can be parsed. Run with --dry-run to only report.
//
// Usage:
//   php Plugins/ZasadyBilling/migrate-rate-meta.php [--dry-run]
//
// observaciones is updated with a single-column SQL statement on purpose: an
// issued invoice is non-editable, and TransformerDocument::save() refuses field
// changes on it. The accounting entry, totals, dates and status of the document
// are untouched - only the token suffix the client must never see is removed.
declare(strict_types=1);

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Kernel;
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

$rows = $db->select(
    'SELECT idfactura, idempresa, codigo, observaciones FROM facturascli'
    // Quoted by hand: escapeString() only escapes, it does not add the quotes
    // (this query silently returned 0 rows until that was fixed), and var2str()
    // would re-format anything that looks like a date.
    . " WHERE observaciones LIKE '" . $db->escapeString('%' . RateToken::OPEN . '%') . "'"
    . ' ORDER BY idfactura;'
);

if ($rows === []) {
    printf("nothing to do: no invoice carries %s in observaciones%s", RateToken::OPEN, PHP_EOL);
    exit(0);
}

$errors = 0;
$moved = 0;
$cleaned = 0;

foreach ($rows as $row) {
    $idfactura = (int)$row['idfactura'];
    $codigo = trim((string)$row['codigo']);
    $label = sprintf('invoice %s (#%d, company %d)', $codigo, $idfactura, (int)$row['idempresa']);

    $meta = RateToken::parseLegacy((string)$row['observaciones']);
    if (null === $meta) {
        // Unknown/legacy-damaged payload: printing it is not an option, but
        // guessing a rate date would be worse. Strip it and report, so the
        // operator can re-enter the provenance by hand.
        fwrite(STDERR, sprintf('warning: %s: unparsable rate token, stripping without metadata%s', $label, PHP_EOL));
    }

    // The key must carry the company: the same code exists in several
    // companies (see RateToken::key()), and this row's own idempresa is the
    // only thing that says which invoice the metadata belongs to.
    $key = RateToken::key((int)$row['idempresa'], $codigo);
    if ($key === '') {
        fwrite(STDERR, sprintf('error: %s: empty codigo, cannot key the settings entry%s', $label, PHP_EOL));
        $errors++;
        continue;
    }

    if (null !== $meta) {
        $existing = Tools::settings(RateToken::GROUP, $key, '');
        if (is_string($existing) && null !== RateToken::decode($existing)) {
            printf("%s: settings entry already present, left untouched%s", $label, PHP_EOL);
        } else {
            if ($dryRun) {
                printf("%s: would store %s = %s%s", $label, $key, json_encode($meta, JSON_UNESCAPED_SLASHES), PHP_EOL);
            } else {
                Tools::settingsSet(RateToken::GROUP, $key, (string)json_encode($meta, JSON_UNESCAPED_SLASHES));
                if (false === Tools::settingsSave()) {
                    fwrite(STDERR, sprintf('error: %s: could not save settings entry %s%s', $label, $key, PHP_EOL));
                    $errors++;
                    continue;
                }
            }
            $moved++;
        }
    }

    $cleanedObservaciones = RateToken::strip((string)$row['observaciones']);
    if ($dryRun) {
        printf("%s: would rewrite observaciones as \"%s\"%s", $label, $cleanedObservaciones, PHP_EOL);
        $cleaned++;
        continue;
    }

    $sql = 'UPDATE facturascli SET observaciones = \'' . $db->escapeString($cleanedObservaciones) . '\''
        . ' WHERE idfactura = ' . $idfactura . ';';
    if (false === $db->exec($sql)) {
        fwrite(STDERR, sprintf('error: %s: observaciones update failed%s', $label, PHP_EOL));
        $errors++;
        continue;
    }

    printf("%s: token removed from observaciones%s", $label, PHP_EOL);
    $cleaned++;
}

printf(
    "%s: %d invoice(s) inspected, %d settings entr(ies) written, %d observaciones cleaned, %d error(s)%s",
    $dryRun ? 'dry run' : 'done',
    count($rows),
    $moved,
    $cleaned,
    $errors,
    PHP_EOL
);

exit($errors === 0 ? 0 : 1);
