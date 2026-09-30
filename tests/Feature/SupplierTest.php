<?php
namespace Tests\Feature;

use App\Models\v1\{Batch, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_survives_create_edit_list_report_and_export(): void
    {
        $user = User::factory()->create(['role'=>'owner','status'=>'approved']);
        $this->actingAs($user, 'sanctum');
        $payload = [
            'branch_id'=>$user->branch_id,'medicine_name'=>'Supplier Test','generic_name'=>'Generic',
            'supplier'=>'Example Distribution','category'=>'Test','pricing_type'=>'branded','cost_price'=>10,
            'reorder_level'=>2,'stocks'=>12,'dosage'=>5,'unit'=>'mg','units_per_box'=>1,'type'=>'Tablet',
            'is_dangerous'=>true,'needs_protection'=>true,'location'=>'Shelf A','batch_number'=>'SUPPLIER-TEST',
            'mfg_date'=>now()->subMonth()->toDateString(),'received_date'=>today()->toDateString(),
            'expiry_date'=>now()->addYears(2)->toDateString(),
        ];
        $created=$this->postJson('/api/v1/medicine',$payload)->assertCreated()->assertJsonPath('data.batch.supplier','Example Distribution');
        $medicine=$created->json('data.medicine.medicine_id');
        $payload['inventory_id']=$created->json('data.inventory.inventory_id');
        foreach (['medicine','medicine?export=1','fefo','fefo?export=1','controlled-drugs'] as $url) {
            $this->getJson('/api/v1/'.$url)->assertOk()->assertSee('Example Distribution');
        }
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.tables.inventory_watch.0.supplier','Example Distribution')
            ->assertJsonPath('data.tables.batch_tracking.0.supplier','Example Distribution');
        $payload['supplier']='Updated Distribution';
        $this->putJson('/api/v1/medicine/'.$medicine,$payload)->assertOk();
        $this->assertSame('Updated Distribution',Batch::where('batch_number','SUPPLIER-TEST')->firstOrFail()->supplier);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.tables.inventory_watch.0.supplier','Updated Distribution');
        unset($payload['supplier']);
        $this->putJson('/api/v1/medicine/'.$medicine,$payload)->assertOk();
        $this->assertSame('Updated Distribution',Batch::where('batch_number','SUPPLIER-TEST')->firstOrFail()->supplier);
        $payload['supplier']='Conflicting Delivery';
        $this->postJson('/api/v1/medicine',$payload)->assertStatus(422);
        $this->assertSame('Updated Distribution',Batch::where('batch_number','SUPPLIER-TEST')->firstOrFail()->supplier);
        Batch::where('batch_number','SUPPLIER-TEST')->update(['status'=>'archived']);
        $this->getJson('/api/v1/fefo/archived/list')->assertOk()->assertJsonPath('data.0.supplier','Updated Distribution');
    }
}
