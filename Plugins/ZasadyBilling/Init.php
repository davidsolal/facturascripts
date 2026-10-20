<?php

namespace FacturaScripts\Plugins\ZasadyBilling;

use FacturaScripts\Core\Template\InitClass;

/**
 * Entry point FacturaScripts calls on every boot and on install/update/uninstall.
 * No extensions, workers or schema changes are needed by Task 1 (settings-only
 * profile), so each hook is intentionally a no-op placeholder for later tasks.
 */
class Init extends InitClass
{
    public function init(): void
    {
        // nothing to register yet; invoice generation lands in a later task
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
