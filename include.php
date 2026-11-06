<?php
use Bitrix\Main\Loader;
Loader::registerAutoLoadClasses('komaroff.b2bassist', [
    'Komaroff\\B2bAssist\\PortalStore' => 'lib/portalstore.php',
    'Komaroff\\B2bAssist\\QuotePdf' => 'lib/quotepdf.php',
    'Komaroff\\B2bAssist\\QuoteEngine' => 'lib/quoteengine.php',
    'Komaroff\\B2bAssist\\CatalogWatchdog' => 'lib/catalogwatchdog.php',
    'Komaroff\\B2bAssist\\OneCClient' => 'lib/onecclient.php',
    'Komaroff\\B2bAssist\\OneCException' => 'lib/onecclient.php',
    'Komaroff\\B2bAssist\\CatalogSync' => 'lib/catalogsync.php',
    'Komaroff\\B2bAssist\\AiQuoteAgent' => 'lib/aiquoteagent.php',
    'Komaroff\\B2bAssist\\AiUnavailable' => 'lib/aiquoteagent.php',
]);
