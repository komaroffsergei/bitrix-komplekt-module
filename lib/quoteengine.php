<?php
declare(strict_types=1);

namespace Komaroff\B2bAssist;

use InvalidArgumentException;

/** Pure domain logic, shared by the demo and the future Bitrix host. Money is in kopeks. */
final class QuoteEngine
{
    public const DEFAULT_TASK = 'Нужны 8 рабочих мест: ноутбуки, мониторы и комплекты клавиатура + мышь. Бюджет — 650 000 ₽.';

    public static function fromTask(array $catalog, string $task): array
    {
        if (strlen($task) > 8000 || !preg_match('/(\d{1,3})\s*(?:рабоч|комплект|сотрудник)/ui', $task, $match)) {
            throw new InvalidArgumentException('Укажите число рабочих мест, например: «8 рабочих мест».');
        }
        $count = (int)$match[1];
        $budget = null;
        if (preg_match('/бюджет[^\d]{0,24}(\d[\d\s\x{00A0}]*)(?:₽|руб)/ui', $task, $match)) {
            $rubles = preg_replace('/[^0-9]/', '', $match[1]);
            if (strlen($rubles) > 9 || (int)$rubles < 1) {
                throw new InvalidArgumentException('Проверьте бюджет. Максимум — 999 999 999 ₽.');
            }
            $budget = (int)$rubles * 100;
        }
        return self::create($catalog, array_fill_keys(['NOTE-16-512','DISPLAY-24-IPS','INPUT-KIT'], $count), $budget, $task);
    }

    public static function create(array $catalog, array $counts, ?int $budget, string $task, string $mode='demo_rules'): array
    {
        if ($counts === [] || count($counts) > 100) {
            throw new InvalidArgumentException('Добавьте товары в предложение.');
        }
        $index = array_column($catalog, null, 'sku');
        $lines = [];
        $total = 0;
        foreach ($counts as $sku => $quantity) {
            if (!isset($index[$sku])) {
                throw new InvalidArgumentException('Неизвестный артикул: ' . $sku);
            }
            if (!is_int($quantity) || $quantity < 1 || $quantity > 999) {
                throw new InvalidArgumentException('Количество должно быть целым числом от 1 до 999.');
            }
            $product = $index[$sku];
            if ($quantity > $product['stock']) {
                throw new InvalidArgumentException('Недостаточный остаток: ' . $product['name'] . '. Доступно: ' . $product['stock'] . '.');
            }
            $sum = $product['price_minor'] * $quantity;
            $total += $sum;
            $lines[] = $product + ['quantity' => $quantity, 'sum_minor' => $sum];
        }
        $now = time();
        $quote = ['id' => bin2hex(random_bytes(8)), 'task' => $task, 'lines' => $lines,
            'total_minor' => $total, 'budget_minor' => $budget,
            'remaining_minor' => $budget === null ? null : $budget - $total,
            'over_budget' => $budget !== null && $total > $budget,
            'created_at' => gmdate('c', $now), 'expires_at' => $now + 900,
            'catalog_source' => $catalog[0]['source'] ?? 'unknown', 'mode' => $mode];
        $quote['revision'] = hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
        return $quote;
    }

    public static function assertOrderable(array $quote, array $catalog, ?int $now = null): void
    {
        if (($now ?? time()) >= $quote['expires_at']) {
            throw new InvalidArgumentException('Предложение истекло. Повторите подбор, чтобы проверить цены и остатки.');
        }
        if ($quote['over_budget']) {
            throw new InvalidArgumentException('Сумма выше бюджета. Измените состав или бюджет перед заказом.');
        }
        $index = array_column($catalog, null, 'sku');
        foreach ($quote['lines'] as $line) {
            $current = $index[$line['sku']] ?? null;
            if (!$current || $current['stock'] < $line['quantity'] || $current['price_minor'] !== $line['price_minor']) {
                throw new InvalidArgumentException('Каталог изменился. Обновите предложение перед заказом.');
            }
        }
    }
}
