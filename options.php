<?php
use Bitrix\Main\Config\Option;
use Komaroff\B2bAssist\AiQuoteAgent;
global $USER;
if (!$USER || !$USER->IsAdmin()) { return; }
$state = Option::get('komaroff.b2bassist', 'catalog_sync_state', 'not_configured');
$labels = ['not_configured' => 'Источник каталога не настроен', 'stale' => 'Каталог устарел', 'fresh' => 'Каталог актуален', 'unavailable' => 'Учебная 1С недоступна'];
$last = (int)Option::get('komaroff.b2bassist', 'last_catalog_sync_at', '0');
$count = (int)Option::get('komaroff.b2bassist', 'catalog_item_count', '0');
$ai = (new AiQuoteAgent(dirname(Option::get('komaroff.b2bassist', 'bridge_config_path', '')) . '/komplekt-llm.json'))->available();
?>
<h2>Комплект: состояние интеграции</h2>
<p><?= htmlspecialchars($labels[$state] ?? 'Неизвестное состояние', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
<p>Последнее успешное чтение каталога: <?= $last ? htmlspecialchars(date('d.m.Y H:i:s', $last), ENT_QUOTES, 'UTF-8') : 'ещё не выполнялось' ?>. Товаров: <?= $count ?>.</p>
<p>Локальный стенд использует файловую учебную базу 1С и COM-адаптер.</p>
<p>AI-подбор: <?= $ai ? 'локальный Qwen 2.5 1.5B подключён' : 'локальная модель недоступна, доступен подбор по правилам' ?>. Цены, суммы и остатки проверяет сервер.</p>
<p>Два агента зарегистрированы с интервалом 5 минут: синхронизация и контроль свежести. Веб-запросы запускают штатную проверку агентов Битрикса.</p>
