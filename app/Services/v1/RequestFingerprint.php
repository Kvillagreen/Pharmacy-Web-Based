<?php
namespace App\Services\v1;
use Symfony\Component\HttpFoundation\File\UploadedFile;
class RequestFingerprint {
    public static function make(array $data): string {
        unset($data['request_token']);
        return hash('sha256', json_encode(self::normalize($data), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
    private static function normalize(mixed $value): mixed {
        if ($value instanceof UploadedFile) return ['sha256' => hash_file('sha256', $value->getPathname())];
        if (!is_array($value)) return $value === null ? null : (string) $value;
        if (!array_is_list($value)) ksort($value);
        return array_map([self::class, 'normalize'], $value);
    }
}
