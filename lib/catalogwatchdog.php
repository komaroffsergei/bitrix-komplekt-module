<?php
declare(strict_types=1);
namespace Komaroff\B2bAssist;
use Bitrix\Main\Config\Option;

/** A real Bitrix scheduler agent. It reports freshness; it does not pretend to exchange with 1C. */
final class CatalogWatchdog
{
    public static function run(): string
    {
        $module = 'komaroff.b2bassist';
        $now = time();
        $last = (int)Option::get($module, 'last_catalog_sync_at', '0');
        $error = Option::get($module, 'last_catalog_error', '');
        $state = $error !== '' ? 'unavailable' : ($last === 0 ? 'not_configured' : ($now - $last > 3600 ? 'stale' : 'fresh'));
        Option::set($module, 'catalog_sync_state', $state);
        Option::set($module, 'watchdog_last_run_at', (string)$now);
        $lastWarning = (int)Option::get($module, 'watchdog_last_warning_at', '0');
        if ($state === 'stale' && $now - $lastWarning > 3600) {
            \CEventLog::Add(['SEVERITY' => 'WARNING', 'AUDIT_TYPE_ID' => 'KOMPLEKT_CATALOG_STALE',
                'MODULE_ID' => $module, 'ITEM_ID' => 'catalog',
                'DESCRIPTION' => 'Каталог не обновлялся более часа. Новые КП требуют проверки актуальности.']);
            Option::set($module, 'watchdog_last_warning_at', (string)$now);
        }
        return '\\Komaroff\\B2bAssist\\CatalogWatchdog::run();';
    }
}
