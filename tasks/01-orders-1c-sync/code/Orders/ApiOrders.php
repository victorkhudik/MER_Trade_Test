<?php
declare(strict_types=1);

namespace Orders;

class ApiOrders
{
    const API_PATH = __DIR__ . '/../../data/orders.json';

    /**
     * @return array
     */
    public function getOrders(): array
    {
        return $this->getData();
    }

    private function getData(): array
    {
        if (!file_exists(self::API_PATH)) {
            throw new \RuntimeException("Файл данных не найден по пути: " . self::API_PATH);
        }

        $jsonRaw = file_get_contents(self::API_PATH);

        $data = json_decode($jsonRaw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Ошибка валидации JSON: " . json_last_error_msg());
        }

        return $data['data']['orders'] ?? [];
    }
}