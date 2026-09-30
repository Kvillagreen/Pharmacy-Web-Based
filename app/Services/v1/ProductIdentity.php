<?php
namespace App\Services\v1;
class ProductIdentity {
    public static function sku(array $data): string {
        $values = [];
        foreach (['medicine_name','generic_name','dosage','unit','type','units_per_box'] as $key) {
            $value = $data[$key] ?? ($key === 'units_per_box' ? 1 : '');
            $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
            $values[] = in_array($key, ['dosage','units_per_box']) && is_numeric($text) ? (string)(float)$text : $text;
        }
        return 'MED-'.strtoupper(substr(hash('sha256', json_encode($values)), 0, 32));
    }
}
