<?php

namespace Tests\Unit;

use App\Models\v1\Inventory;
use App\Services\v1\MedicineDisplay;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class MedicineDisplayTest extends TestCase
{
    public function test_matching_batches_are_combined_before_pagination_and_variants_stay_separate(): void
    {
        $base = ['branch_id' => 1, 'medicine_name' => 'Brand', 'generic_name' => 'Generic',
            'price' => '5.00', 'type' => 'Tablet', 'dosage' => '500', 'unit' => 'mg',
            'stocks' => 3, 'expiry_date' => '2030-01-01', 'needs_protection' => false, 'is_dangerous' => false];
        $rows = collect([$base, [...$base, 'medicine_id' => 2, 'stocks' => 7, 'needs_protection' => true, 'medicine_name' => ' BRAND ', 'generic_name' => "Generic\u{00A0}", 'type' => 'TABLET', 'unit' => 'MG', 'dosage' => '500.00', 'price' => 5]]);
        foreach (['branch_id' => 2, 'medicine_name' => 'Other', 'generic_name' => 'Other',
            'price' => 6, 'type' => 'Capsule', 'dosage' => '250', 'unit' => 'ml'] as $key => $value) {
            $rows->push([...$base, $key => $value]);
        }
        $query = new class($rows->map(fn ($row) => (new Inventory)->forceFill($row))) {
            public function __construct(private $rows) {}
            public function get() { return $this->rows; }
        };
        $page = MedicineDisplay::paginate($query, Request::create('/'), 1);
        $this->assertSame(8, $page->total());
        $this->assertCount(1, $page->items());
        $this->assertSame(10, $page->items()[0]['stocks']);
        $this->assertCount(2, $page->items()[0]['members']);
        $this->assertTrue($page->items()[0]['needs_protection']);
        $this->assertCount(1, $page->items()[0]['expiry_dates']);
    }

    public function test_public_identity_combines_same_medicine_data_across_internal_ids(): void
    {
        $base = [
            'branch_id' => 1,
            'company_name' => 'Sto. Rosario Drug Store',
            'branch_name' => 'Sto. Rosario Main Branch',
            'branch_address' => 'Sto. Rosario, Mandaue City, Cebu',
            'branch_contact' => '09171194119',
            'medicine_id' => 42,
            'medicine_name' => 'Azithromycin',
            'generic_name' => 'Azithromycin',
            'category' => 'Antibiotic',
            'type' => 'Tablet',
            'dosage' => '500',
            'unit' => 'mg',
            'price' => '42.00',
            'stocks' => 4,
            'expiry_date' => '2030-01-01',
            'needs_protection' => false,
            'is_dangerous' => false,
        ];
        $rows = collect([
            (new Inventory)->forceFill($base),
            (new Inventory)->forceFill([
                ...$base,
                'medicine_id' => 99,
                'medicine_name' => ' AZITHROMYCIN ',
                'generic_name' => "Azithromycin\u{00A0}",
                'dosage' => '500.00',
                'price' => 42,
                'stocks' => 6,
                'expiry_date' => '2031-01-01',
            ]),
            (new Inventory)->forceFill([...$base, 'type' => 'Capsule', 'stocks' => 2]),
        ]);
        $query = new class($rows) {
            public function __construct(private $rows) {}
            public function get() { return $this->rows; }
        };

        $page = MedicineDisplay::paginate($query, Request::create('/'), 10, true);

        $this->assertSame(2, $page->total());
        $this->assertSame(10, $page->items()[0]['stocks']);
        $this->assertCount(2, $page->items()[0]['members']);
        $this->assertCount(2, $page->items()[0]['expiry_dates']);
    }
}
