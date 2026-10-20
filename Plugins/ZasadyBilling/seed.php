<?php
// Plugins/ZasadyBilling/seed.php
// Bootstraps a FacturaScripts instance with the two billing companies and the
// configuration MonthlyRun()/Issuer() expect: shared invoice series, one
// numbering sequence per company, the 0% "out of scope" tax, the client record
// and both billing profiles.
//
// Every personal value (names, NIFs, addresses, amounts) is read from a private
// JSON seed that never enters version control. This file only names the fields,
// so the public fork can ship it as-is.
//
// Run: docker exec facturascripts-app php /var/www/html/Plugins/ZasadyBilling/seed.php [--force]
// Seed path: $ZASADY_SEED, or <FS_FOLDER>/dev-stack/private/billing-seed.json
// --force: re-apply company/client/profile fields over existing values. Numbering
//          sequences are never rewound, with or without the flag.
declare(strict_types=1);

// The plugin directory sits inside the document root: refuse to run from a web
// request, otherwise anyone could trigger a reseed (and rewrite company data).
if ('cli' !== PHP_SAPI) {
    http_response_code(404);
    exit;
}

define('FS_FOLDER', dirname(__DIR__, 2));
require FS_FOLDER . '/config.php';
require FS_FOLDER . '/vendor/autoload.php';

\FacturaScripts\Core\Kernel::init();

$force = in_array('--force', array_slice($argv ?? [], 1), true);

/** Abort on the first failed write instead of reporting a half-seeded instance. */
$must = static function (bool $ok, string $what): void {
    if (false === $ok) {
        throw new \RuntimeException('seed failed: ' . $what);
    }
};

/**
 * Core validates a company's web with FILTER_VALIDATE_URL (Empresa::test()),
 * which rejects a bare domain such as "example.com". The seed stores the
 * operator-facing domain without a scheme, so prefix https:// when there is
 * none; leaving it raw makes Empresa::save() return false and aborts seeding.
 */
$normalizeUrl = static function (?string $url): string {
    $url = trim((string)$url);
    if ('' === $url || 1 === preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
        return $url;
    }
    return 'https://' . $url;
};

try {
    $path = getenv('ZASADY_SEED') ?: FS_FOLDER . '/dev-stack/private/billing-seed.json';
    $must(is_file($path), 'seed file not found: ' . $path);
    $seed = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    $companies = $seed['companies'] ?? [];
    $must(count($companies) > 0, 'seed contains no companies');
    $exerciseYear = (string)(int)($seed['exercise'] ?? date('Y'));
    $billingDay = (int)($seed['billing_day'] ?? 19);
    $lineName = (string)($seed['line'] ?? '');

    // Series are global (no idempresa column): both companies must share one
    // code, and their numbering is separated by secuencias_documentos instead.
    $seriesCodes = array_values(array_unique(array_map(
        static fn(array $c): string => (string)($c['serie'] ?? ''),
        $companies
    )));
    $must(1 === count($seriesCodes) && '' !== $seriesCodes[0], 'seed companies must share one series code');
    $codserie = $seriesCodes[0];

    // 0% tax. Issuer::issue() refuses to run without it: the retainer lines are
    // out of scope of Spanish VAT, so they carry a rate, not a percentage.
    $iva = new \FacturaScripts\Core\Model\Impuesto();
    if (false === $iva->load('IVA0')) {
        $iva->codimpuesto = 'IVA0';
        $iva->descripcion = 'IVA 0%';
        $iva->iva = 0.0;
        $iva->recargo = 0.0;
        $must($iva->save(), 'create IVA0 tax');
    }

    // Journals (diarios) are global too: one per exercise is enough, and
    // series.iddiario is what routes each company's invoices into it.
    $diarioName = 'Facturas ' . $exerciseYear;
    $diario = new \FacturaScripts\Core\Model\Diario();
    if (false === $diario->loadWhereEq('descripcion', $diarioName)) {
        $diario->descripcion = $diarioName;
        $must($diario->save(), 'create journal ' . $diarioName);
    }

    // Invoice series, created once and then kept in sync with the seed.
    $serie = new \FacturaScripts\Core\Model\Serie();
    if (false === $serie->load($codserie)) {
        $serie->codserie = $codserie;
    }
    $serie->descripcion = 'Facturas ' . $exerciseYear;
    $serie->tipo = '';          // empty = ordinary series (R = rectificativa, S = simplificada)
    $serie->siniva = false;
    $serie->iddiario = (int)$diario->iddiario;
    $must($serie->save(), 'save serie ' . $codserie);

    $report = [];
    $profilesEnabled = 0;

    foreach ($companies as $c) {
        $idempresa = (int)$c['idempresa'];

        // idempresa 1 already exists as the install wizard's placeholder
        // ("E-xxxx"), so load-then-fill updates it instead of adding a third
        // company; idempresa 2 is created with an explicit id.
        $empresa = new \FacturaScripts\Core\Model\Empresa();
        if (false === $empresa->load($idempresa)) {
            $empresa->idempresa = $idempresa;
        }
        $empresa->nombre = (string)$c['name'];
        $empresa->nombrecorto = mb_substr((string)$c['name'], 0, 32);
        $empresa->cifnif = (string)$c['nif'];
        $empresa->tipoidfiscal = 'NIF';
        $empresa->personafisica = true;
        $empresa->direccion = (string)$c['address'];
        $empresa->codpostal = (string)$c['postcode'];
        $empresa->ciudad = (string)$c['city'];
        $empresa->provincia = (string)$c['province'];
        $empresa->codpais = 'ESP';
        $empresa->telefono1 = (string)$c['phone'];
        $empresa->web = $normalizeUrl($c['web'] ?? '');
        // empresas has no email column in this schema, so the seed's company
        // email is deliberately not written anywhere instead of being smuggled
        // into a free-text field (invoice delivery uses the client contact).
        $must($empresa->save(), 'save empresa ' . $idempresa);

        // Accounting exercise. Must come after the empresa row: ejercicios has a
        // FK to empresas (ca_ejercicios_empresas), so creating it first fails on
        // the second company. ejercicios is keyed by codejercicio alone, so a
        // second company in the same year cannot reuse "2026": loadFromDate()
        // applies core's own collision rule (00YY, 0+id+YY, 4-digit) and creates
        // the row when it is missing. Doing it here, before the sequence, is
        // what lets each company's invoices find an open exercise later.
        $ejercicio = new \FacturaScripts\Core\Model\Ejercicio();
        $ejercicio->idempresa = $idempresa;
        $must(
            $ejercicio->loadFromDate($exerciseYear . '-01-15'),
            'exercise ' . $exerciseYear . ' for empresa ' . $idempresa
        );

        // Numbering counter, one per company + series + exercise. Created with
        // the seed's next number, and never rewritten afterwards: a rerun after
        // go-live must not renumber or reopen a counter that already issued
        // invoices (Issuer relies on core bumping numero on every save).
        $seq = new \FacturaScripts\Core\Model\SecuenciaDocumento();
        $seqWhere = [
            \FacturaScripts\Core\Where::eq('idempresa', $idempresa),
            \FacturaScripts\Core\Where::eq('codserie', $codserie),
            \FacturaScripts\Core\Where::eq('codejercicio', $ejercicio->codejercicio),
            // 'FacturaCliente', not 'factura': BusinessDocumentCode::getSequence()
            // matches tipodoc against the document's modelClassName(). Any other
            // value is ignored and core silently auto-creates its own sequence
            // starting at 1 - which would collide with the invoices already
            // issued on paper under this series.
            \FacturaScripts\Core\Where::eq('tipodoc', 'FacturaCliente'),
        ];
        if ($seq->loadWhere($seqWhere)) {
            $report[] = 'secuencia empresa ' . $idempresa . ' (' . $ejercicio->codejercicio . ') existente, numero '
                . $seq->numero . ' intacto';
        } else {
            $seq->idempresa = $idempresa;
            $seq->codserie = $codserie;
            $seq->codejercicio = $ejercicio->codejercicio;
            $seq->tipodoc = 'FacturaCliente';
            // {ANYO2} = 2-digit year, {0NUM} zero-padded to longnumero -> F2600010.
            $seq->patron = 'F{ANYO2}{0NUM}';
            $seq->longnumero = 5;
            $seq->inicio = (int)$c['next_number'];
            $seq->numero = (int)$c['next_number'];
            $seq->usarhuecos = false;   // never reuse a gap in a legal invoice series
            $must($seq->save(), 'create sequence for empresa ' . $idempresa);
            $report[] = 'secuencia empresa ' . $idempresa . ' (' . $ejercicio->codejercicio . ') creada, numero '
                . $seq->numero;
        }

        // Billing profile (settings-backed). Converge a missing or partial
        // profile, but do not silently re-enable or re-price one the operator
        // already tuned: re-running after pausing a company must not put it
        // back on the billing calendar. --force is the explicit override.
        $profile = \FacturaScripts\Plugins\ZasadyBilling\Lib\Profile::load($idempresa);
        $configured = '' !== $profile->codcliente && 0.0 !== $profile->amount;
        if (false === $configured || $force) {
            $profile->enabled = true;
            $profile->codserie = $codserie;
            $profile->codcliente = (string)$seed['client']['code'];
            $profile->amount = (float)$c['amount'];
            $profile->day = $billingDay;
            $profile->description = $lineName;
            $profile->save();
        }
        if ($profile->enabled) {
            $profilesEnabled++;
        }
        $report[] = 'perfil empresa ' . $idempresa . ' ' . ($configured && false === $force ? 'conservado' : 'aplicado')
            . ', enabled=' . ($profile->enabled ? '1' : '0') . ', dia=' . $profile->day;
    }

    // Client: out-of-scope services to a non-EU business. operacion MUST stay
    // empty - TaxExceptions::isValidCombination() only pairs arts. 68-70 with an
    // empty operation, so marking it 'exportacion' (which core pairs with art. 21
    // exports) would make Cliente::test() reject the record.
    $clientSeed = $seed['client'];
    $codcliente = (string)$clientSeed['code'];
    $taxId = trim((string)($clientSeed['tax_id'] ?? ''));
    $cliente = new \FacturaScripts\Core\Model\Cliente();
    if (false === $cliente->load($codcliente)) {
        $cliente->codcliente = $codcliente;
    }
    $cliente->nombre = (string)$clientSeed['name'];
    $cliente->razonsocial = (string)$clientSeed['name'];
    $cliente->cifnif = $taxId;
    $cliente->tipoidfiscal = '' === $taxId ? null : 'NIF';
    $cliente->personafisica = false;
    $cliente->telefono1 = (string)($clientSeed['phone'] ?? '');
    $cliente->email = (string)($clientSeed['email'] ?? '');
    $cliente->web = $normalizeUrl($clientSeed['web'] ?? '');
    $cliente->operacion = '';       // see comment above: empty on purpose
    $cliente->excepcioniva = \FacturaScripts\Core\Lib\TaxExceptions::ES_TAX_EXCEPTION_68_70;
    $must($cliente->save(), 'save cliente ' . $codcliente);

    // Cliente rows carry no address columns: the fiscal address lives on the
    // default Contacto (Cliente::saveInsert creates a bare one on insert) and
    // that is what invoices copy onto the document header.
    $contacto = new \FacturaScripts\Core\Model\Contacto();
    if (empty($cliente->idcontactofact) === false) {
        $contacto->load((int)$cliente->idcontactofact);
    }
    if (empty($contacto->idcontacto)) {
        $contacto->codcliente = $cliente->codcliente;
        $contacto->nombre = (string)$clientSeed['name'];
        $contacto->descripcion = (string)$clientSeed['name'];
    }
    $contacto->direccion = (string)$clientSeed['address'];
    $contacto->codpostal = (string)$clientSeed['postcode'];
    $contacto->ciudad = (string)$clientSeed['city'];
    $contacto->provincia = (string)($clientSeed['region'] ?? '');
    $contacto->codpais = (string)$clientSeed['country'];
    $contacto->email = (string)($clientSeed['email'] ?? '');
    $contacto->telefono1 = (string)($clientSeed['phone'] ?? '');
    $must($contacto->save(), 'save client address');
    if ((int)$cliente->idcontactofact !== (int)$contacto->idcontacto) {
        $cliente->idcontactofact = (int)$contacto->idcontacto;
        $must($cliente->save(), 'link client billing address');
    }

    $report[] = 'cliente ' . $codcliente . ' operacion vacia, excepcion '
        . \FacturaScripts\Core\Lib\TaxExceptions::ES_TAX_EXCEPTION_68_70;

    echo 'seed ok' . PHP_EOL;
    echo '  empresas: ' . count($companies) . PHP_EOL;
    echo '  serie: ' . $codserie . ' (diario ' . $diario->iddiario . ')' . PHP_EOL;
    echo '  impuesto IVA0: presente' . PHP_EOL;
    echo '  perfiles activos: ' . $profilesEnabled . PHP_EOL;
    foreach ($report as $entry) {
        echo '  - ' . $entry . PHP_EOL;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
