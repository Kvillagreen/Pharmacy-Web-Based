<?php

namespace Database\Seeders;

use App\Models\v1\Transaction;
use App\Models\v1\TransactionItem;
use App\Models\v1\TransactionType;
use Illuminate\Database\Seeder;
use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\Batch;
use App\Models\v1\Supplier;
use App\Models\v1\Inventory;
class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //

        Branch::factory()
        ->count(5)
        ->create(['status'=> 'active']);

        Branch::factory()
        ->count(5)
        ->create(['status'=> 'inactive']);


        User::factory()
        ->count(5)
        ->create(['status'=>'pending']);

        User::factory()
        ->count(5)
        ->create(['status'=>'deleted']);

        User::factory()
        ->count(5)
        ->create(['status'=>'approved']);

        User::factory()
        ->count(5)
        ->create(['status'=>'rejected']);


        Supplier::factory()
        ->count(5)
        ->create();

        Batch::factory()
        ->count(5)
        ->create();

        Inventory::factory()
        ->count(20)
        ->create();

        Transaction::factory()
        ->count(5)
        ->create();

        TransactionType::factory()
        ->count(5)
        ->create();


        TransactionItem::factory()
        ->count(5)
        ->create();

    }
}
