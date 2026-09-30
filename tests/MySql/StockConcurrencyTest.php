<?php
namespace Tests\MySql;
use Tests\TestCase;
use Illuminate\Support\Facades\{Artisan,DB};
use App\Models\v1\{Branch,Medicine,Batch,Inventory};
use Symfony\Component\Process\Process;
class StockConcurrencyTest extends TestCase {
    public function test_two_connections_cannot_sell_the_same_last_unit():void {
        $config=config('database.connections.mysql');
        $this->assertSame('mysql',config('database.default'));
        $this->assertStringStartsWith('pharmacy_test_',$config['database'],'Refuse to reset a non-test database.');
        $this->assertSame(0,Artisan::call('migrate:fresh',['--force'=>true]));
        $branch=Branch::factory()->create();$medicine=Medicine::factory()->create(['stocks'=>1]);$batch=Batch::factory()->create();
        $inventory=Inventory::create(['branch_id'=>$branch->branch_id,'medicine_id'=>$medicine->medicine_id,'batch_id'=>$batch->batch_id,'stocks'=>1]);
        $environment=['APP_ENV'=>'testing','DB_CONNECTION'=>'mysql','DB_DATABASE'=>$config['database'],'DB_HOST'=>$config['host'],
            'DB_PORT'=>(string)$config['port'],'DB_USERNAME'=>$config['username'],'DB_PASSWORD'=>$config['password']??'','DB_URL'=>'','CACHE_STORE'=>'array'];
        $workers=[];for($i=0;$i<2;$i++){$process=new Process([PHP_BINARY,__DIR__.'/stock-worker.php',(string)$inventory->inventory_id],base_path(),$environment);$process->setTimeout(30);$process->start();$workers[]=$process;}
        $out=[];foreach($workers as $process){$process->wait();$this->assertTrue($process->isSuccessful(),$process->getErrorOutput());$out[]=trim($process->getOutput());}
        sort($out);$this->assertSame(['rejected','sold'],$out);$this->assertSame(0,(int)$inventory->fresh()->stocks);
        $this->assertSame(0,(int)$medicine->fresh()->stocks);$this->assertSame(1,DB::table('stock_movements')->count());
    }
}
