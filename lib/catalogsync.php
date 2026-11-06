<?php
declare(strict_types=1);
namespace Komaroff\B2bAssist;
use Bitrix\Main\Config\Option;

/** Read the real educational catalog; record only successful synchronization times. */
final class CatalogSync
{
    private const MODULE = 'komaroff.b2bassist';
    public static function fetch(): array
    {
        $path = Option::get(self::MODULE, 'bridge_config_path', '');
        if ($path === '') {
            Option::set(self::MODULE, 'catalog_sync_state', 'not_configured');
            throw new OneCException('Источник 1С не настроен в модуле Битрикса.', 503);
        }
        try {
            $items = (new OneCClient($path))->call('/catalog')['catalog'];
            Option::set(self::MODULE, 'catalog_cache_json', json_encode($items,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            Option::set(self::MODULE, 'last_catalog_sync_at', (string)time());
            Option::set(self::MODULE, 'catalog_item_count', (string)count($items));
            Option::set(self::MODULE, 'last_catalog_error', '');
            Option::set(self::MODULE, 'catalog_sync_state', 'fresh');
            return $items;
        } catch (OneCException $error) {
            Option::set(self::MODULE, 'last_catalog_error', $error->getMessage());
            Option::set(self::MODULE, 'catalog_sync_state', 'unavailable');
            throw $error;
        }
    }
    public static function run(): string
    {
        try { self::fetch(); } catch (OneCException $error) { /* State is already recorded. */ }
        return '\\Komaroff\\B2bAssist\\CatalogSync::run();';
    }
    public static function cached(): ?array
    {
        $json=Option::get(self::MODULE,'catalog_cache_json','');
        if($json==='')return null;
        $items=json_decode($json,true);
        return is_array($items)&&$items!==[]?$items:null;
    }
}
