<?php
require_once __DIR__ . '/vendor/autoload.php';

use Orders\ApiOrders;
use OneCNomenclatures\OneCNomenclatures;

$ordersList = new ApiOrders();
$oneCNomenclatures = new OneCNomenclatures();
// Массивы для вывода ошибок и сообщений
$errors = [];
$reports = [];

$apiOrders = $ordersList->getOrders();
$nomenclatures = $oneCNomenclatures->getNomenclatures();
function normalizeSku($sku): string
{
    return strtoupper(trim($sku ?? ''));
}
// Подсчет совокупного спроса
$skuDemand = [];

foreach ($apiOrders as $order) {
    if ($order['status'] === 'cancelled') {
        continue;
    }

    foreach ($order['items'] ?? [] as $item) {
        $sku = normalizeSku($item['sku']);
        $qty = (int) ($item['quantity'] ?? 0);
        if ($sku !== '' && $qty > 0) {
            $skuDemand[$sku] = ($skuDemand[$sku] ?? 0) + $qty;
        }
    }
}
// Валидация, сверка цен/остатков и построение отчета
$seenOrderIds = [];
foreach ($apiOrders as $order) {
    $orderId = $order['id'] ?? 'НЕИЗВЕСТНО';
    $status  = $order['status'] ?? 'unknown';

    // Проверка дубликатов заказов
    if (isset($seenOrderIds[$orderId])) {
        $errors[] = "Заказ {$orderId}: Обнаружен дубликат заказа (пропущен).";
        $reports[] = [
            'order_id' => $orderId,
            'status'   => $status,
            'action'   => 'ПРОПУЩЕН',
            'reason'   => 'Дубликат заказа'
        ];
        continue;
    }
    $seenOrderIds[$orderId] = true;

    // Проверка отмененных заказов
    if ($status === 'cancelled') {
        $reports[] = [
            'order_id' => $orderId,
            'status'   => $status,
            'action'   => 'ПРОПУЩЕН',
            'reason'   => 'Заказ отменен покупателем'
        ];
        continue;
    }

    // Проверка битых и поврежденных записей
    $items      = $order['items'] ?? [];
    $orderTotal = $order['total'] ?? null;

    if (empty($items)) {
        $errors[] = "Заказ {$orderId}: Пустой состав товаров (items: []).";
        $reports[] = [
            'order_id' => $orderId,
            'status'   => $status,
            'action'   => 'ОШИБКА ДАННЫХ',
            'reason'   => 'Пустой состав заказа'
        ];
        continue;
    }

    if ($orderTotal === null) {
        $errors[] = "Заказ {$orderId}: Не указана итоговая сумма (total: null).";
    }

    // Сверка со справочником 1С
    $orderIssues = [];
    $canExport   = true;

    foreach ($items as $itemIndex => $item) {
        $sku    = normalizeSku($item['sku']);
        $title  = $item['title'] ?? 'Без названия';
        $qty    = (int) ($item['quantity'] ?? 0);
        $price  = isset($item['price']) ? (float) $item['price'] : null;

        if ($price === null) {
            $orderIssues[] = "Строка #{$itemIndex}: У товара '{$title}' ({$sku}) отсутствует цена в заказе.";
            $errors[] = "Заказ {$orderId}: Товар {$sku} без цены.";
            $canExport = false;
            continue;
        }

        // Проверка наличия SKU в 1С
        if (!isset($nomenclatures[$sku])) {
            $orderIssues[] = "Артикул '{$sku}' отсутствует в 1С";
            $canExport = false;
            continue;
        }

        $nomCards = $nomenclatures[$sku];
        if (count($nomCards) > 1) {
            $errors[] = "Заказ {$orderId}: Для артикула {$sku} обнаружено несколько карточек в 1С.";
        }

        $primaryNom = $nomCards[0] ?? $nomCards;

        // Сверка цен
        if (abs($primaryNom['price'] - $price) > 0.001) {
            $orderIssues[] = sprintf(
                "Артикул '%s': цена в заказе (%.2f) != цена в 1С (%.2f)",
                $sku, $price, $primaryNom['price']
            );
            $canExport = false;
        }

        // Сверка остатков
        if ($primaryNom['stock'] < $qty) {
            $orderIssues[] = sprintf(
                "Артикул '%s': не хватает остатка (нужно %d шт, на складе %.0f шт)",
                $sku, $qty, $primaryNom['stock']
            );
            $canExport = false;
        } elseif (($skuDemand[$sku] ?? 0) > $primaryNom['stock']) {
            $orderIssues[] = sprintf(
                "Артикул '%s': риски дефицита по совокупному спросу (затребовано %d шт, остаток %.0f шт)",
                $sku, $skuDemand[$sku], $primaryNom['stock']
            );
        }
    }

    if ($canExport) {
        $reports[] = [
            'order_id' => $orderId,
            'status'   => $status,
            'action'   => 'ГОТОВ К ВЫГРУЗКЕ',
            'reason'   => empty($orderIssues) ? 'Все позици сопоставлены' : implode(' | ', $orderIssues)
        ];
    } else {
        $reportsDt[] = [
            'order_id' => $orderId,
            'status'   => $status,
            'action'   => 'ОШИБКА СОПОСТАВЛЕНИЯ',
            'reason'   => implode(' | ', $orderIssues)
        ];
    }
}

// Вывод отчета в консоли
echo "=========================================================================================================\n";
echo "                              ОТЧЕТ СОПОСТАВЛЕНИЯ ЗАКАЗОВ МАРКЕТПЛЕЙСА И 1С                             \n";
echo "=========================================================================================================\n";
printf("%-12s | %-10s | %-22s | %s\n", "№ Заказа", "Статус", "Действие", "Причина / Детали");
echo "---------------------------------------------------------------------------------------------------------\n";

foreach ($reports as $row) {
    printf(
        "%-12s | %-10s | %-22s | %s\n",
        $row['order_id'],
        $row['status'],
        $row['action'],
        $row['reason']
    );
}
echo "=========================================================================================================\n\n";

if (!empty($errors)) {
    echo "--- ЛОГ ОШИБОК И ПРЕДУПРЕЖДЕНИЙ ---\n";
    foreach ($errors as $error) {
        echo $error . "\n";
    }
    echo "------------------------------------\n";
}