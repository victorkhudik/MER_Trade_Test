<?php
declare(strict_types=1);

namespace OneCNomenclatures;

class OneCNomenclatures
{
    const API_PATH = __DIR__ . '/../../data/nomenclature_1c.csv';

    public function getNomenclatures(): array
    {
        return $this->getData();
    }

    private function getData(): array
    {
        if (!file_exists(self::API_PATH)) {
            throw new \RuntimeException("Файл данных не найден по пути: " . self::API_PATH);
        }

        if (($handle = fopen(self::API_PATH, 'r')) === false) {
            throw new \RuntimeException("Не удалось открыть CSV файл для чтения.");
        }

        $nomenclatures = [];

        fgetcsv($handle, 0, ';');

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (empty($row) || count($row) < 6) {
                continue;
            }

            $sku = strtoupper(trim($row[1] ?? ''));
            if ($sku === '') {
                continue;
            }

            $nomenclatures[$sku][] = [
                'code'  => trim($row[0]),
                'sku'   => $sku,
                'name'  => trim($row[2]),
                'price' => (float) str_replace([" ", "\xc2\xa0", ","], ["", "", "."], trim($row[4])),
                'stock' => (float) str_replace([" ", "\xc2\xa0", ","], ["", "", "."], trim($row[5])),
            ];
        }

        fclose($handle);

        return $nomenclatures;
    }
}