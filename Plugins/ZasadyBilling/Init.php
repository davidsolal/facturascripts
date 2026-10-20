<?php

namespace FacturaScripts\Plugins\ZasadyBilling;

use FacturaScripts\Core\Lib\ExportManager;
use FacturaScripts\Core\Template\InitClass;

/**
 * Entry point FacturaScripts calls on every boot and on install/update/uninstall.
 * No extensions, workers or schema changes are needed by Task 1 (settings-only
 * profile), so most hooks are intentionally no-op placeholders for later tasks.
 */
class Init extends InitClass
{
    public function init(): void
    {
        // Registers the "Zasady" PDF export option (Task 6). ExportManager
        // resolves the key to \FacturaScripts\Dinamic\Lib\Export\ZasadyExport,
        // which dev-stack/install.sh shims to our plugin class — see that
        // script for why the class must live under Dinamic.
        //
        // Degradation mode: the option is registered unconditionally, but the
        // class only resolves once the Dinamic shim exists. If it is missing
        // (fresh checkout where install.sh has not run, or Dinamic/ was
        // wiped), the menu still offers "Zasady" and core's plain PDFExport
        // renders instead — a valid PDF, silently without the EUR/rate line.
        // No runtime check here on purpose: a file_exists() probe on every
        // boot buys nothing that a correct shim does not already give, and
        // failing loudly would take the whole app down over a cosmetic line.
        // Remedy: run dev-stack/install.sh, or re-enable the plugin (core's
        // PluginsDeploy regenerates the shim once the plugin is enabled).
        ExportManager::addOption('Zasady', 'Zasady bilingual invoice (EN/ES)', 'fa-solid fa-file-invoice');
    }

    public function update(): void
    {
        // no schema/data to install; Profile persists via the core settings table
    }

    public function uninstall(): void
    {
        // no plugin-owned tables or files to remove
    }
}
