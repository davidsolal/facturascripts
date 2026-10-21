<?php
// Plugins/ZasadyBilling/seed.php
// Bootstraps a FacturaScripts instance with the two billing companies and the
// configuration MonthlyRun()/Issuer() expect: shared invoice series, one
// numbering sequence per company, the 0% "out of scope" tax, one bank account
// per company, the client record and both billing profiles.
//
// Every personal value (names, NIFs, addresses, IBANs, amounts) is read from a
// private JSON seed that never enters version control. This file only names the
// fields, so the public fork can ship it as-is.
//
// Run: docker exec facturascripts-app php /var/www/html/Plugins/ZasadyBilling/seed.php [--force]
// Seed path: $ZASADY_SEED, or <FS_FOLDER>/dev-stack/private/billing-seed.json
//
// Contract:
//   (no flag) CREATE only what is missing. Existing rows - companies, series,
//             bank accounts, profiles, the client and its address - are left
//             exactly as the operator left them, and every skip is reported.
//             Re-running is therefore safe after go-live.
//   --force   Also rewrite those seed-derived fields over the existing rows.
//   (both)    The numbering sequence is the one thing never rewritten: numero
//             and inicio keep their value, so a re-run can neither renumber nor
//             collide with invoices already issued. Only its usarhuecos flag is
//             repaired, because that is a policy bit, not a counter.
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
    // Single source of truth for the billing day: Profile's own default.
    $billingDay = (int)($seed['billing_day'] ?? \FacturaScripts\Plugins\ZasadyBilling\Lib\Profile::defaults()['day']);
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

    // Invoice series: created once, then only touched by --force.
    $serie = new \FacturaScripts\Core\Model\Serie();
    $serieExists = $serie->load($codserie);
    if (false === $serieExists || $force) {
        if (false === $serieExists) {
            $serie->codserie = $codserie;
        }
        $serie->descripcion = 'Facturas ' . $exerciseYear;
        $serie->tipo = '';      // empty = ordinary series (R = rectificativa, S = simplificada)
        $serie->siniva = false;
        $serie->iddiario = (int)$diario->iddiario;
        $must($serie->save(), 'save serie ' . $codserie);
    }

    $report = [];
    $report[] = false === $serieExists
        ? 'serie ' . $codserie . ' creada (diario ' . $diario->iddiario . ')'
        : ($force ? 'serie ' . $codserie . ' actualizada' : 'serie ' . $codserie . ' sin cambios (--force la reescribe)');
    $profilesEnabled = 0;

    foreach ($companies as $c) {
        $idempresa = (int)$c['idempresa'];

        // idempresa 1 already exists as the install wizard's placeholder
        // ("E-xxxx"), so load-then-fill updates it instead of adding a third
        // company; idempresa 2 is created with an explicit id. Without --force
        // an existing row is never written: an operator edit must survive a
        // re-run.
        $empresa = new \FacturaScripts\Core\Model\Empresa();
        $empresaExists = $empresa->load($idempresa);
        if (false === $empresaExists || $force) {
            $empresa->idempresa = $idempresa;
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
            $empresa->telefono1 = (string)($c['phone'] ?? '');
            // EmailAndPhonesTrait::$email -> empresas.email (varchar 100);
            // Empresa::test() rejects the row when it is not a valid address.
            $empresa->email = (string)($c['email'] ?? '');
            $empresa->web = $normalizeUrl($c['web'] ?? '');
            $must($empresa->save(), 'save empresa ' . $idempresa);
            $report[] = 'empresa ' . $idempresa . ($empresaExists ? ' actualizada' : ' creada');
        } else {
            $report[] = 'empresa ' . $idempresa . ' sin cambios (--force la reescribe)';
        }

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
        if ($ejercicio->codejercicio !== $exerciseYear) {
            // Not an error (core does this for the second company in a year, and
            // it also opens a fresh alternative code when the year's exercise is
            // closed), but it must be visible: the sequence is keyed by it.
            $report[] = 'AVISO: ejercicio ' . $exerciseYear . ' de empresa ' . $idempresa
                . ' vive bajo el codigo ' . $ejercicio->codejercicio
                . ' (codejercicio es unico, no por empresa; o el ejercicio del ano esta cerrado)';
        }

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
            $numero = $seq->numero;
            if (false === (bool)$seq->usarhuecos) {
                // Policy bit, not a counter: core sets true for FacturaCliente
                // (BusinessDocumentCode / Wizard) and SecuenciaDocumento::test()
                // logs use-holes-invoices-esp when it is false in Spain. With
                // holes enabled a deleted invoice's number can be reused, so a
                // mistake does not leave a permanent gap in a legal series.
                $seq->usarhuecos = true;
                $must($seq->save(), 'enable usarhuecos for empresa ' . $idempresa);
                $must((int)$seq->numero === (int)$numero, 'sequence renumbered for empresa ' . $idempresa);
            }
            $report[] = 'secuencia empresa ' . $idempresa . ' (' . $ejercicio->codejercicio . ') existente, numero '
                . $seq->numero . ' intacto, usarhuecos=' . ((bool)$seq->usarhuecos ? '1' : '0');
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
            $seq->usarhuecos = true;    // see above: allow reusing a deleted number
            $must($seq->save(), 'create sequence for empresa ' . $idempresa);
            $report[] = 'secuencia empresa ' . $idempresa . ' (' . $ejercicio->codejercicio . ') creada, numero '
                . $seq->numero;
        }

        // Bank account of the company itself. empresas has no IBAN column; the
        // bank details the invoice PDF needs live in cuentasbanco, one row per
        // company. codcuenta is that table's only key (numeric per
        // CuentaBanco::test()), so it is derived from idempresa: stable across
        // reruns, and a collision with another company's account is an error
        // instead of a silent hijack.
        $bankCode = (string)(1000 + $idempresa);
        $iban = (string)preg_replace('/\s+/', '', (string)($c['iban'] ?? ''));
        if ('' !== $iban) {
            $cuenta = new \FacturaScripts\Core\Model\CuentaBanco();
            $bankExists = $cuenta->load($bankCode);
            if ($bankExists && (int)$cuenta->idempresa !== $idempresa) {
                $must(false, 'cuenta banco ' . $bankCode . ' already belongs to empresa ' . $cuenta->idempresa);
            }
            if (false === $bankExists || $force) {
                if (false === $bankExists) {
                    $cuenta->codcuenta = $bankCode;
                }
                $cuenta->idempresa = $idempresa;
                // cuentasbanco has no holder column: the account title is the
                // description, and it is what tells the two accounts apart.
                $holder = trim((string)($c['holder'] ?? ''));
                $cuenta->descripcion = mb_substr('' === $holder ? (string)$c['name'] : $holder, 0, 100);
                $cuenta->iban = $iban;
                $cuenta->swift = trim((string)($c['bic'] ?? ''));
                $cuenta->activa = true;
                $must($cuenta->save(), 'save cuenta banco ' . $bankCode . ' for empresa ' . $idempresa);
                $report[] = 'cuenta banco ' . $bankCode . ' empresa ' . $idempresa
                    . ($bankExists ? ' actualizada' : ' creada');
            } else {
                $report[] = 'cuenta banco ' . $bankCode . ' empresa ' . $idempresa . ' sin cambios (--force la reescribe)';
            }
        }

        // Billing profile (settings-backed). The stored properties are read
        // before Profile::load(), because Tools::settings() registers the
        // defaults for any missing key: after load() a key the operator never
        // set is indistinguishable from one they set to Profile::defaults().
        // Only a missing/empty amount means "not configured" - 0 is a value an
        // operator may deliberately choose for a paused retainer.
        $settings = new \FacturaScripts\Core\Model\Settings();
        $stored = false === $settings->load(\FacturaScripts\Plugins\ZasadyBilling\Lib\Profile::group($idempresa))
            ? []
            : $settings->getProperties();

        $profile = \FacturaScripts\Plugins\ZasadyBilling\Lib\Profile::load($idempresa);
        $configured = '' !== $profile->codserie
            && '' !== $profile->codcliente
            && '' !== trim((string)($stored['amount'] ?? ''));
        if (false === $configured || $force) {
            $profile->enabled = true;
            $profile->codserie = $codserie;
            $profile->codcliente = (string)$seed['client']['code'];
            $profile->amount = (float)$c['amount'];
            $profile->day = $billingDay;
            $profile->description = $lineName;
            // Not a seed field: keep whatever rate the operator configured.
            $must($profile->save(), 'persist billing profile for empresa ' . $idempresa);
            $report[] = 'perfil empresa ' . $idempresa . ' aplicado (guardado confirmado)';
        } else {
            $report[] = 'perfil empresa ' . $idempresa . ' sin cambios (--force lo reescribe)';
        }
        if ($profile->enabled) {
            $profilesEnabled++;
        }
        $report[] = 'perfil empresa ' . $idempresa . ' enabled=' . ($profile->enabled ? '1' : '0')
            . ', dia=' . $profile->day;
    }

    // Client: out-of-scope services to a non-EU business. operacion MUST stay
    // empty - TaxExceptions::isValidCombination() only pairs arts. 68-70 with an
    // empty operation, so marking it 'exportacion' (which core pairs with art. 21
    // exports) would make Cliente::test() reject the record.
    $clientSeed = $seed['client'];
    $codcliente = (string)$clientSeed['code'];
    $taxId = trim((string)($clientSeed['tax_id'] ?? ''));
    $cliente = new \FacturaScripts\Core\Model\Cliente();
    $clientExists = $cliente->load($codcliente);
    if (false === $clientExists || $force) {
        $cliente->codcliente = $codcliente;
        $cliente->nombre = (string)$clientSeed['name'];
        $cliente->razonsocial = (string)$clientSeed['name'];
        $cliente->cifnif = $taxId;
        $cliente->tipoidfiscal = '' === $taxId ? null : 'NIF';
        $cliente->personafisica = false;
        $cliente->telefono1 = (string)($clientSeed['phone'] ?? '');
        $cliente->email = (string)($clientSeed['email'] ?? '');
        $cliente->web = $normalizeUrl($clientSeed['web'] ?? '');
        $cliente->operacion = '';   // see comment above: empty on purpose
        // Document language pair is a property of the client: 'en_EN' prints EN/ES,
        // 'fr_FR' prints FR/ES (ZasadyExport::primaryLanguage). Unset = EN/ES.
        $cliente->langcode = ($clientSeed['langcode'] ?? '') !== '' ? (string)$clientSeed['langcode'] : 'en_EN';
        $cliente->excepcioniva = \FacturaScripts\Core\Lib\TaxExceptions::ES_TAX_EXCEPTION_68_70;
        $must($cliente->save(), 'save cliente ' . $codcliente);
        $report[] = 'cliente ' . $codcliente . ($clientExists ? ' actualizado' : ' creado')
            . ', operacion vacia, excepcion ' . \FacturaScripts\Core\Lib\TaxExceptions::ES_TAX_EXCEPTION_68_70;
    } else {
        $report[] = 'cliente ' . $codcliente . ' sin cambios (--force lo reescribe)';
    }

    // Cliente rows carry no address columns: the fiscal address lives on the
    // default Contacto (Cliente::saveInsert creates a bare one on insert) and
    // that is what invoices copy onto the document header. A contact created by
    // this run (or a client created by this run) is always filled; an existing
    // pair is only rewritten by --force.
    $contacto = new \FacturaScripts\Core\Model\Contacto();
    if (false === empty($cliente->idcontactofact)) {
        $contacto->load((int)$cliente->idcontactofact);
    }
    $contactExists = false === empty($contacto->idcontacto);
    if (false === $contactExists || false === $clientExists || $force) {
        if (false === $contactExists) {
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
        $report[] = 'direccion cliente ' . $codcliente . ' guardada';
    } else {
        $report[] = 'direccion cliente ' . $codcliente . ' sin cambios (--force la reescribe)';
    }
    if ((int)$cliente->idcontactofact !== (int)$contacto->idcontacto) {
        $cliente->idcontactofact = (int)$contacto->idcontacto;
        $must($cliente->save(), 'link client billing address');
    }

    echo 'seed ok (' . ($force ? 'force' : 'create-only') . ')' . PHP_EOL;
    echo '  empresas: ' . count($companies) . PHP_EOL;
    echo '  serie: ' . $codserie . ' (diario ' . $diario->iddiario . ')' . PHP_EOL;
    echo '  impuesto IVA0: presente' . PHP_EOL;
    echo '  perfiles activos: ' . $profilesEnabled . ' de ' . count($companies) . PHP_EOL;
    foreach ($report as $entry) {
        echo '  - ' . $entry . PHP_EOL;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
