<?php
namespace Tests\Feature;
use App\Models\v1\{User, Medicine, Inventory, Batch, Branch};
use App\Services\v1\{StockMovementService, ProductIdentity};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalIntegrityTest extends TestCase {
    use RefreshDatabase;
    public function test_stock_changes_are_atomic_audited_and_derived():void {
        $user=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($user,'sanctum');
        $medicine=Medicine::factory()->create(['stocks'=>999]);$batch=Batch::factory()->create();
        $row=Inventory::create(['medicine_id'=>$medicine->medicine_id,'branch_id'=>$user->branch_id,'batch_id'=>$batch->batch_id,'stocks'=>5]);
        $service=app(StockMovementService::class);$service->change($row,-2,'sold','test');
        $this->assertSame(3,(int)$row->fresh()->stocks);$this->assertSame(3,(int)$medicine->fresh()->stocks);
        $this->assertDatabaseHas('stock_movements',['stock_before'=>5,'stock_after'=>3,'quantity_change'=>-2,'actor_id'=>$user->user_id]);
        try{$service->change($row,-4,'sold');$this->fail('Negative stock accepted');}catch(\Illuminate\Validation\ValidationException $e){}
        $this->assertSame(3,(int)$row->fresh()->stocks);
    }
    public function test_transfer_preserves_total_and_tracks_both_sides():void {
        $medicine=Medicine::factory()->create();$batch=Batch::factory()->create();$a=Branch::factory()->create();$b=Branch::factory()->create(['company_id'=>$a->company_id]);
        $source=Inventory::create(['medicine_id'=>$medicine->medicine_id,'batch_id'=>$batch->batch_id,'branch_id'=>$a->branch_id,'stocks'=>5]);
        $destination=Inventory::create(['medicine_id'=>$medicine->medicine_id,'batch_id'=>$batch->batch_id,'branch_id'=>$b->branch_id,'stocks'=>1]);
        app(StockMovementService::class)->transfer($source,$destination,2,'Test transfer');
        $this->assertSame(3,(int)$source->fresh()->stocks);$this->assertSame(3,(int)$destination->fresh()->stocks);$this->assertSame(6,(int)$medicine->fresh()->stocks);
        $this->assertDatabaseCount('stock_movements',2);
    }
    public function test_sku_normalizes_identity_and_distinguishes_pack_size():void {
        $a=['medicine_name'=>'  Sample   Brand ','generic_name'=>'Generic','dosage'=>'50.0','unit'=>'MG','type'=>'Tablet','units_per_box'=>10];
        $b=[...$a,'medicine_name'=>'sample brand','dosage'=>50,'unit'=>'mg'];
        $this->assertSame(ProductIdentity::sku($a),ProductIdentity::sku($b));
        $this->assertNotSame(ProductIdentity::sku($a),ProductIdentity::sku([...$b,'units_per_box'=>20]));
    }
    public function test_archived_user_restores_pending_without_old_sessions():void {
        $owner=User::factory()->create(['role'=>'owner','status'=>'approved']);$user=User::factory()->create(['branch_id'=>$owner->branch_id,'status'=>'approved']);
        $user->createToken('old');$this->actingAs($owner,'sanctum');
        $this->deleteJson('/api/v1/user/'.$user->user_id)->assertOk();$this->assertSame(0,$user->tokens()->count());
        $this->getJson('/api/v1/user/archived/list')->assertOk()->assertJsonPath('data.0.user_id',$user->user_id);
        $this->postJson('/api/v1/user/'.$user->user_id.'/restore',['reason'=>'Reviewed return to work'])->assertOk();
        $this->assertDatabaseHas('users',['user_id'=>$user->user_id,'status'=>'pending','deleted_at'=>null]);
    }
    public function test_cross_company_restore_is_denied():void {
        $owner=User::factory()->create(['role'=>'owner','status'=>'approved']);$other=User::factory()->create(['branch_id'=>Branch::factory()->create()->branch_id]);$other->delete();$this->actingAs($owner,'sanctum');
        $this->postJson('/api/v1/user/'.$other->user_id.'/restore',['reason'=>'Test'])->assertForbidden();
    }
    public function test_removed_report_record_endpoints_are_not_registered():void {
        $uris=collect(\Illuminate\Support\Facades\Route::getRoutes())->map(fn($route)=>$route->uri());
        foreach(['tax-profiles','expenses','snapshots'] as $path) $this->assertFalse($uris->contains('api/v1/reports/'.$path));
    }
    public function test_catalog_contract_has_structured_availability_and_prices():void {
        $a=Branch::factory()->create();$b=Branch::factory()->create(['company_id'=>$a->company_id]);$c=Branch::factory()->create(['company_id'=>$a->company_id]);
        $medicine=Medicine::factory()->create(['units_per_box'=>10,'price'=>12]);$batch=Batch::factory()->create(['status'=>'active','expiry_date'=>now()->addYear()->toDateString()]);
        foreach([[$a,4],[$b,0]] as [$branch,$stock]) Inventory::create(['branch_id'=>$branch->branch_id,'medicine_id'=>$medicine->medicine_id,'batch_id'=>$batch->batch_id,'stocks'=>$stock]);
        $response=$this->getJson('/api/v1/catalog')->assertOk();$availability=collect($response->json('data.0.branch_availability'))->keyBy('branch_id');
        $this->assertSame('in_stock',$availability[$a->branch_id]['status']);$this->assertSame(4,$availability[$a->branch_id]['sellable_quantity']);
        $this->assertSame('out_of_stock',$availability[$b->branch_id]['status']);$this->assertSame('not_carried',$availability[$c->branch_id]['status']);
    }
}
