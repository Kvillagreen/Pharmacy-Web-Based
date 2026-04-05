<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\MethodTransactionRequest;
use App\Models\v1\Medicine;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionItem;
use App\Models\v1\User;
use App\Models\v1\Branch;
use DB;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Cache;
class TransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request){}

    /**
     * Store a newly created resource in storage.public function store(MethodTransactionRequest $request)
     * **/

public function store(MethodTransactionRequest $request){
    DB::beginTransaction();

    try {
        $data = $request->validated();

        if (!empty($data['request_token'])) {
            $lock = Cache::lock('transaction_' . $data['request_token'], 10);

            if (!$lock->get()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate request detected!',
                ], 400);
            }
        }

        // 🧠 Validate items
        if (empty($data['items'])) {
            throw new \Exception('Invalid items data');
        }

        // 🔎 Lock + validate stocks
        $medicines = [];

        foreach ($data['items'] as $item) {

            $medicine = Medicine::where('medicine_id', $item['medicine_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($medicine->stocks < $item['quantity']) {
                throw new \Exception("Insufficient stock for medicine ID {$item['medicine_id']}");
            }
            $medicines[$item['medicine_id']] = $medicine;
        }

        // ✅ Create transaction safely using fillable
        $transaction = Transaction::create([
            ...collect($data)->except('items')->toArray(),
            'transaction_type_id' => null
        ]);

        // 📦 Insert items + deduct stock
        foreach ($data['items'] as $item) {

            TransactionItem::create([
                'transaction_id' => $transaction->transaction_id,
                'medicine_id' => $item['medicine_id'],
                'quantity' => $item['quantity'],
            ]);

            $medicines[$item['medicine_id']]
                ->decrement('stocks', $item['quantity']);
        }

        DB::commit();

        return response()->json([
            'message' => 'Transaction created successfully',
            'data' => $transaction->load('items')
        ], 201);

    } catch (\Throwable $e) {
        DB::rollBack();

        \Log::error('Transaction failed', [
            'error' => $e->getMessage(),
            'request_data' => $request->all()
        ]);

        return response()->json([
            'message' => 'Failed to create transaction',
            'error' => $e->getMessage()
        ], 500);
    }
}
    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
