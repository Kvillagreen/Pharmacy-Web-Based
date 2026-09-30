<?php

namespace Tests\Unit;

use App\Services\v1\FortmedSmsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FortmedSmsServiceTest extends TestCase
{
    public static function phoneNumbers(): array
    {
        return [
            ['09171234567', '639171234567', '09171234567'],
            ['9171234567', '639171234567', '09171234567'],
            ['+63 917 123 4567', '639171234567', '09171234567'],
            ['', '', ''],
        ];
    }

    #[DataProvider('phoneNumbers')]
    public function test_phone_numbers_are_normalized_and_formatted_consistently(
        string $input,
        string $normalized,
        string $display
    ): void {
        $service = new FortmedSmsService();

        $this->assertSame($normalized, $service->normalizePhoneNumber($input));
        $this->assertSame($display, $service->formatDisplayPhoneNumber($input));
    }
}
