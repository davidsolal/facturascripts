<?php
// Plugins/ZasadyBilling/export-pdf.php
// Renders one invoice to a PDF file through the plugin's own export option,
// exactly as the "Zasady" print option in the UI does (ExportManager ->
// Dinamic\Lib\Export\ZasadyExport, i.e. PDFExport plus the EUR/rate line and
// the company bank block). Used for acceptance runs and to re-send a copy of an
// already issued invoice without opening the browser.
//
// Usage:
//   php export-pdf.php <codigo> <output.pdf> [idempresa] [langcode]
//
// idempresa is optional but recommended: invoice codes are only unique per
// company (both companies number their own F26 series from the same sequence
// pattern), so a code that exists in two companies must be disambiguated.
// langcode defaults to es_ES because that is the language of the invoices we
// send; pass '' to fall back to the instance's configured language.
declare(strict_types=1);

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Lib\ExportManager;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Where;

define('FS_FOLDER', dirname(__DIR__, 2));
require FS_FOLDER . '/config.php';
require FS_FOLDER . '/vendor/autoload.php';

Kernel::init();

$usage = 'usage: php export-pdf.php <codigo> <output.pdf> [idempresa] [langcode]' . PHP_EOL;

$codigo = $argv[1] ?? '';
$output = $argv[2] ?? '';
if ($codigo === '' || $output === '') {
    fwrite(STDERR, $usage);
    exit(2);
}
$idempresa = (int)($argv[3] ?? 0);
$langcode = $argv[4] ?? 'es_ES';

// Locate the invoice. all() instead of load() because the primary key is
// idfactura, not codigo, and because we want to *detect* an ambiguous code
// rather than silently render whichever row the DB returns first.
$where = [Where::eq('codigo', $codigo)];
if ($idempresa > 0) {
    $where[] = Where::eq('idempresa', $idempresa);
}
$matches = (new FacturaCliente())->all($where, ['idempresa' => 'ASC'], 0, 2);

if ($matches === []) {
    fwrite(STDERR, sprintf('error: no invoice with codigo %s%s%s', $codigo, $idempresa > 0 ? ' for company ' . $idempresa : '', PHP_EOL));
    exit(1);
}

if (count($matches) > 1) {
    $companies = implode(', ', array_map(static fn($f) => (string)$f->idempresa, $matches));
    fwrite(STDERR, sprintf(
        'error: codigo %s exists in several companies (%s); pass idempresa as the third argument%s',
        $codigo,
        $companies,
        PHP_EOL
    ));
    exit(1);
}

$factura = $matches[0];

// Same call sequence as Core/Lib/ExtendedController/ListView.php and
// ApiExportDocument: newDoc(option, title, format, lang) then
// addBusinessDocPage(model) then getDoc(). addBusinessDocPage() returns false
// by design (it means "do not keep appending more pages from this model"), so
// its return value must not be treated as a failure - the page is already in
// the document by then.
$export = new ExportManager();
$export->newDoc('Zasady', $factura->codigo, 0, $langcode);
$export->addBusinessDocPage($factura);
$pdf = $export->getDoc();

if (!is_string($pdf) || strncmp($pdf, '%PDF-', 5) !== 0) {
    fwrite(STDERR, 'error: export produced no PDF data' . PHP_EOL);
    exit(1);
}

$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, 'error: cannot create output directory ' . $dir . PHP_EOL);
    exit(1);
}

if (file_put_contents($output, $pdf) === false) {
    fwrite(STDERR, 'error: cannot write ' . $output . PHP_EOL);
    exit(1);
}

printf(
    "wrote %s (%d bytes) invoice %s company %d [%s]%s",
    $output,
    strlen($pdf),
    $factura->codigo,
    $factura->idempresa,
    $langcode === '' ? 'instance language' : $langcode,
    PHP_EOL
);
