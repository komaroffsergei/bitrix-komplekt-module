<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/quoteengine.php';
use Komaroff\B2bAssist\QuoteEngine as Engine;
$catalog = json_decode(file_get_contents(dirname(__DIR__).'/tests/fixtures/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$count = 0;
function expect(bool $condition, string $label): void { global $count; if (!$condition) { throw new RuntimeException($label); } ++$count; echo "PASS: $label\n"; }
function rejects(callable $call, string $label): void { try { $call(); } catch (InvalidArgumentException $e) { expect(true, $label); return; } throw new RuntimeException('Accepted invalid input: '.$label); }
$q=Engine::fromTask($catalog, Engine::DEFAULT_TASK);
expect($q['total_minor']===52232000,'integer money total');
expect($q['remaining_minor']===12768000,'budget extraction');
expect(count($q['lines'])===3 && $q['lines'][0]['quantity']===8,'task becomes complete set');
rejects(fn()=>Engine::fromTask($catalog,'собери что-нибудь'),'ambiguous quantity rejected');
rejects(fn()=>Engine::fromTask($catalog,'999 рабочих мест'),'insufficient stock rejected');
rejects(fn()=>Engine::create($catalog,['INVENTED'=>1],null,''),'unknown SKU rejected');
rejects(fn()=>Engine::create($catalog,['INPUT-KIT'=>1.5],null,''),'fractional quantity rejected');
rejects(fn()=>Engine::create($catalog,['INPUT-KIT'=>0],null,''),'zero quantity rejected');
$over=Engine::fromTask($catalog,'8 рабочих мест. Бюджет 100 000 ₽');
expect($over['over_budget']===true,'over-budget quote clearly marked');
rejects(fn()=>Engine::assertOrderable($over,$catalog),'over-budget order blocked');
rejects(fn()=>Engine::assertOrderable($q,$catalog,$q['expires_at']),'expired quote blocked');
$changed=$catalog;$changed[0]['price_minor']++;
rejects(fn()=>Engine::assertOrderable($q,$changed),'changed price requires fresh quote');
$changed=$catalog;$changed[0]['stock']=7;
rejects(fn()=>Engine::assertOrderable($q,$changed),'stock rechecked at order');
$edited=Engine::create($catalog,['INPUT-KIT'=>10],null,'custom');
expect($edited['total_minor']===2490000,'catalog price is authoritative');
Engine::assertOrderable($q,$catalog);
expect(true,'valid quote accepted');
echo "$count domain checks passed\n";
