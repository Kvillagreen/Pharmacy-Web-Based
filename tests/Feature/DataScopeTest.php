<?php
namespace Tests\Feature;
use App\Models\v1\{Branch, User, Medicine, Inventory, Batch, Transaction};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
class DataScopeTest extends TestCase {
    use RefreshDatabase;
    public static function branchRoles(): array { return [['staff'], ['pharmacist'], ['branch_manager']]; }
    #[DataProvider('branchRoles')]
    public function test_branch_roles_cannot_read_or_mutate_other_branch_records(string $role): void {
        $own=Branch::factory()->create();$other=Branch::factory()->create(['company_id'=>$own->company_id]);
        $user=User::factory()->create(['branch_id'=>$own->branch_id,'role'=>$role,'status'=>'approved']);
        $medicine=Medicine::factory()->create();$batch=Batch::factory()->create(['status'=>'active','expiry_date'=>now()->addYear()]);
        $inventory=Inventory::create(['branch_id'=>$other->branch_id,'medicine_id'=>$medicine->medicine_id,'batch_id'=>$batch->batch_id,'stocks'=>8]);
        $sale=Transaction::create(['branch_id'=>$other->branch_id,'user_id'=>$user->user_id,'status'=>'completed','total_amount'=>10,'sub_total'=>10,'payment_method'=>'Cash','used_amount'=>10,'change'=>0]);
        $this->actingAs($user,'sanctum');
        foreach(['medicine','fefo','controlled-drugs','transaction','sms/orders','header/notifications'] as $path) $this->getJson('/api/v1/'.$path.'?branch_id='.$other->branch_id)->assertForbidden();
        $this->getJson('/api/v1/branch/'.$own->company_id)->assertOk()->assertJsonCount(1,'data.branches');
        $this->getJson('/api/v1/medicine?branch_id=0')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v1/medicine/'.$medicine->medicine_id)->assertForbidden();
        $this->deleteJson('/api/v1/medicine/'.$medicine->medicine_id)->assertForbidden();
        $this->postJson('/api/v1/fefo/'.$batch->batch_id.'/pull-out',[])->assertForbidden();
        $this->postJson('/api/v1/controlled-drugs/'.$batch->batch_id.'/dispose',[])->assertForbidden();
        $this->getJson('/api/v1/transaction/'.$sale->transaction_id)->assertForbidden();
        $this->postJson('/api/v1/transaction/'.$sale->transaction_id.'/void',[])->assertForbidden();
        $this->getJson('/api/v1/user/archived/list')->assertForbidden();
        $this->assertSame(8,(int)$inventory->fresh()->stocks);$this->assertSame('completed',$sale->fresh()->status);
    }
    public function test_owner_and_admin_can_select_company_branches_but_not_other_companies(): void {
        $own=Branch::factory()->create();$other=Branch::factory()->create(['company_id'=>$own->company_id]);$foreign=Branch::factory()->create();
        foreach(['owner','admin'] as $role){
            $user=User::factory()->create(['branch_id'=>$own->branch_id,'role'=>$role,'status'=>'approved']);$this->actingAs($user,'sanctum');
            $this->getJson('/api/v1/branch/'.$own->company_id)->assertOk()->assertJsonCount(2,'data.branches');
            foreach(['dashboard','medicine','fefo','controlled-drugs','transaction','reports','sms/orders'] as $path){
                $this->getJson('/api/v1/'.$path.'?branch_id='.$other->branch_id)->assertOk();
                $this->getJson('/api/v1/'.$path.'?branch_id='.$foreign->branch_id)->assertForbidden();
            }
        }
    }
}
