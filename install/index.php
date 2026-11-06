<?php
use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\ModuleTable;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { return; }

class komaroff_b2bassist extends CModule
{
    public $MODULE_ID = 'komaroff.b2bassist';
    public $MODULE_NAME = 'Комплект: B2B-подбор и КП';
    public $MODULE_DESCRIPTION = 'Локальный AI-подбор, расчёт КП, обмен с учебной 1С и агенты синхронизации и контроля каталога.';
    public $PARTNER_NAME = 'Сергей Комаров';
    public $PARTNER_URI = 'https://komaroff-dev.ru';

    public function __construct()
    {
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall()
    {
        global $USER;
        if (!$USER || !$USER->IsAdmin()) { return; }
        if (ModuleManager::isModuleInstalled($this->MODULE_ID)) { return; }
        ModuleManager::registerModule($this->MODULE_ID);
        ModuleTable::cleanCache();
        Option::set($this->MODULE_ID, 'catalog_sync_state', 'not_configured');
        $agent = CAgent::AddAgent('\\Komaroff\\B2bAssist\\CatalogWatchdog::run();', $this->MODULE_ID, 'N', 300, '', 'Y');
        if (!$agent) {
            ModuleManager::unRegisterModule($this->MODULE_ID);
            throw new RuntimeException('Не удалось зарегистрировать агент контроля каталога.');
        }
        $sync = CAgent::AddAgent('\\Komaroff\\B2bAssist\\CatalogSync::run();', $this->MODULE_ID, 'N', 300, '', 'Y');
        if (!$sync) {
            CAgent::RemoveModuleAgents($this->MODULE_ID);
            ModuleManager::unRegisterModule($this->MODULE_ID);
            throw new RuntimeException('Не удалось зарегистрировать агент синхронизации.');
        }
    }

    public function DoUninstall()
    {
        global $USER;
        if (!$USER || !$USER->IsAdmin()) { return; }
        CAgent::RemoveModuleAgents($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);
        ModuleTable::cleanCache();
        // Options are intentionally retained; no business data is dropped.
    }
}
