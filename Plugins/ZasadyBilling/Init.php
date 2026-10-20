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
