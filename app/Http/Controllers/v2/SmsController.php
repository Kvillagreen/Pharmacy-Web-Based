<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Services\v1\FortmedSmsService;
use App\Models\v1\Branch;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Models\v1\SmsOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SmsController extends Controller
{
    public function __construct(
        private readonly FortmedSmsService $smsService
    ) {
    }

    private function response(bool $success, string $message, $data = null, int $status = 200)
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public function receiver(Request $request)
    {
        $data = $request->validate([
            'from_number' => ['required_without:customer_number', 'nullable', 'string', 'max:30'],
            'customer_number' => ['required_without:from_number', 'nullable', 'string', 'max:30'],
            'message_body' => ['required_without:message', 'nullable', 'string', 'max:500'],
            'message' => ['required_without:message_body', 'nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,branch_id'],
            'provider_message_id' => ['nullable', 'string', 'max:255'],
        ]);

        $customerNumber = trim((string) ($data['customer_number'] ?? $data['from_number']));
        $messageBody = trim((string) ($data['message_body'] ?? $data['message']));
        $branch = Branch::query()
            ->where('status', 'active')
            ->when(!empty($data['branch_id']), fn ($query) => $query->where('branch_id', $data['branch_id']))
            ->orderBy('branch_id')
            ->first();

        if (!$branch) {
            return $this->response(false, 'No active branch is available for this SMS order.', null, 422);
        }

        if (!preg_match('/^MED\s+(.+)$/i', $messageBody, $matches)) {
            return $this->response(false, 'Invalid order format. Use MED <medicine_id> <quantity>, <medicine_id> <quantity>.', null, 422);
        }

        $requested = [];
        foreach (preg_split('/\s*,\s*|\R+/', trim($matches[1])) as $line) {
            if (!preg_match('/^(\d+)\s+(\d+)$/', trim($line), $parts) || (int) $parts[2] < 1) {
                return $this->response(false, 'Invalid order item. Use <medicine_id> <quantity> for every item.', null, 422);
            }

            $medicineId = (int) $parts[1];
            $requested[$medicineId] = ($requested[$medicineId] ?? 0) + (int) $parts[2];
        }

        $medicines = Medicine::query()->whereIn('medicine_id', array_keys($requested))->get()->keyBy('medicine_id');
        $orderItems = [];
        $totalPrice = 0.0;

        foreach ($requested as $medicineId => $quantity) {
            $medicine = $medicines->get($medicineId);
            if (!$medicine) {
                return $this->response(false, "Medicine {$medicineId} was not found.", null, 422);
            }

            $availableStock = (int) Inventory::query()
                ->where('branch_id', $branch->branch_id)
                ->where('medicine_id', $medicineId)
                ->sum('stocks');
            if ($availableStock < $quantity) {
                return $this->response(false, "Medicine {$medicineId} has insufficient stock.", null, 422);
            }

            $unitPrice = round((float) $medicine->price, 2);
            $lineTotal = round($unitPrice * $quantity, 2);
            $totalPrice += $lineTotal;
            $orderItems[] = compact('medicineId', 'quantity', 'unitPrice', 'lineTotal');
        }

        $order = DB::transaction(function () use ($branch, $customerNumber, $messageBody, $data, $orderItems, $totalPrice) {
            $order = SmsOrder::create([
                'branch_id' => $branch->branch_id,
                'customer_number' => $customerNumber,
                'message_body' => $messageBody,
                'provider_message_id' => $data['provider_message_id'] ?? null,
                'status' => 'pending',
                'total_price' => round($totalPrice, 2),
            ]);

            foreach ($orderItems as $item) {
                $order->items()->create([
                    'medicine_id' => $item['medicineId'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unitPrice'],
                    'line_total' => $item['lineTotal'],
                ]);
            }

            return $order->load('items.medicine');
        });

        return $this->response(true, 'SMS order created successfully.', [
            'order_id' => $order->sms_order_id,
            'status' => $order->status,
            'customer_number' => $order->customer_number,
            'total_price' => (float) $order->total_price,
            'items' => $order->items->map(fn ($item) => [
                'medicine_id' => $item->medicine_id,
                'medicine_name' => $item->medicine?->medicine_name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->values(),
        ], 201);
    }

    public function orders(Request $request)
    {
        $user = $request->user();
        $query = SmsOrder::query()
            ->with(['items.medicine'])
            ->whereIn('status', ['pending','confirmed'])
            ->orderByDesc('sms_order_id');

        if ($user && !in_array($user->role, ['owner', 'admin', 'super_admin'], true)) {
            $query->where('branch_id', $user->branch_id);
        } elseif ((int) $request->input('branch_id') > 0) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }

        if ($user?->branch?->company_id) {
            $query->whereIn('branch_id', Branch::where('company_id', $user->branch->company_id)->pluck('branch_id'));
        }

        $orders = $query->limit(50)->get()->map(fn (SmsOrder $order) => [
            'order_id' => $order->sms_order_id,
            'branch_id' => $order->branch_id,
            'customer_number' => $order->customer_number,
            'message_body' => $order->message_body,
            'status' => $order->status,
            'total_price' => (float) $order->total_price,
            'created_at' => $order->created_at,
            'items' => $order->items->map(fn ($item) => [
                'medicine_id' => $item->medicine_id,
                'medicine_name' => $item->medicine?->medicine_name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->values(),
        ])->values();

        return $this->response(true, 'SMS orders loaded successfully.', ['orders' => $orders]);
    }

    public function processOrder(Request $request, int $orderId)
    {
        $data=$request->validate(['action'=>['required','in:confirm,cancel']]);
        return DB::transaction(function()use($request,$orderId,$data){
            $order=SmsOrder::lockForUpdate()->findOrFail($orderId);
            abort_unless(in_array((int)$order->branch_id,$this->smsBranches($request),true),403);
            abort_unless(in_array($order->status,['pending','confirmed'],true) && !$order->transaction_id,409,'This order is already closed.');
            $changes=['status'=>$data['action']==='confirm'?'confirmed':'cancelled'];
            if($data['action']==='confirm' && !$order->confirmed_at){$changes['confirmed_by']=$request->user()->user_id;$changes['confirmed_at']=now();}
            $order->update($changes);
            return $this->response(true,'Order updated. No stock or sale is recorded until pickup is completed in POS.');
        });
    }

    public function showOrder(Request $request,int $orderId) {
        $order=SmsOrder::with('items.medicine')->findOrFail($orderId);
        abort_unless(in_array((int)$order->branch_id,$this->smsBranches($request),true),403);
        return $this->response(true,'Order loaded.',$order);
    }

    public function saveOrder(Request $request) {
        $data=$request->validate(['customer_number'=>['required','regex:/^(?:\+?63|0)9\d{9}$/'],'message_body'=>['required','string','max:1000'],'request_token'=>['required','uuid'],'items'=>['required','array','min:1'],'items.*.medicine_id'=>['required','integer','exists:medicines,medicine_id'],'items.*.quantity'=>['required','integer','min:1','max:100000']]);
        $branchId=(int)$request->input('branch_id');
        abort_unless(in_array($branchId,$this->smsBranches($request),true),403);
        return DB::transaction(function()use($data,$branchId){
            $key='manual:'.$data['request_token'];
            $order=SmsOrder::where('provider_message_id',$key)->first();
            if($order){abort_unless((int)$order->branch_id===$branchId,403);return $this->response(true,'Pending order already saved.',$order);}
            $order=SmsOrder::create(['branch_id'=>$branchId,'customer_number'=>$data['customer_number'],'message_body'=>$data['message_body'],'provider_message_id'=>$key,'status'=>'pending','total_price'=>0]);
            $total=0;
            foreach(collect($data['items'])->groupBy('medicine_id') as $id=>$rows){
                $m=Medicine::whereNull('archived_at')->findOrFail($id);
                abort_unless(Inventory::where('branch_id',$branchId)->where('medicine_id',$id)->exists(),422,'Medicine is not assigned to this branch.');
                $qty=$rows->sum('quantity');$line=round($qty*(float)$m->price,2);$total+=$line;
                $order->items()->create(['medicine_id'=>$id,'quantity'=>$qty,'unit_price'=>$m->price,'line_total'=>$line]);
            }
            $order->update(['total_price'=>round($total,2)]);
            return $this->response(true,'Pending order saved. Staff confirmation and pickup are required.',$order,201);
        });
    }

    private function smsBranches(Request $request): array
    {
        $allowed = $request->attributes->get('allowed_branch_ids', []);
        $selected = (int) $request->input('branch_id');
        return $selected > 0 ? array_values(array_intersect($allowed, [$selected])) : $allowed;
    }

    public function replies(Request $request)
    {
        $history = $this->smsService->getStoredConversations((int) $request->input('limit', 20), $this->smsBranches($request), (int) $request->input('before_id') ?: null);
        return $this->response(true, 'SMS replies loaded successfully.', [
            'messages' => $history['conversations'], 'summary' => $history,
            'defaults' => $this->smsService->defaults(),
        ]);
    }

    public function sync(Request $request)
    {
        $result = $this->smsService->syncGateway();
        return $this->response($result['success'], $result['success']
            ? 'Message refresh completed.' : 'Gateway unavailable. Saved messages remain available.',
            ['synced' => $result['success']], $result['success'] ? 200 : 502);
    }

    public function diagnostics(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin', 'super_admin'], true), 403);
        $result = $this->smsService->fetchReplies(1);
        return $this->response(true, 'SMS connection checked.', [
            'provider' => ['status' => $result['status'], 'success' => $result['status'] >= 200 && $result['status'] < 300],
        ]);
    }

    public function send(Request $request)
    {
        $defaults = $this->smsService->defaults();
        $user = $request->user();
        $userId = $user?->user_id ? (int) $user->user_id : null;
        $branchId = (int) ($request->input('branch_id') ?: $user?->branch_id);
        abort_unless(in_array($branchId, $this->smsBranches($request), true), 403);

        $validated = $request->validate([
            'to_number' => ['required', 'string', 'regex:/^(?:\+?63|0)9\d{9}$/'],
            'message_body' => ['required', 'string', 'max:161'],
            'sender_name' => ['nullable', 'string', 'max:100'],
            'template_tag' => ['nullable', 'string', 'max:120'],
        ]);

        $fromNumber = trim((string) ($defaults['from_number'] ?? ''));
        if ($fromNumber === '') {
            $branch = Branch::find($branchId) ?? Branch::where('status', 'active')->first();
            $fromNumber = trim((string) ($branch?->branch_contact ?? ''));
        }

        $senderName = 'Sto. Rosario Drug Store';
        if ($senderName === '') {
            $senderName = 'KMV Pharmacy';
        }

        $payload = [
            'SenderName' => $senderName,
            'ToNumber' => trim((string) $validated['to_number']),
            'MessageBody' => str_starts_with(trim((string)$validated['message_body']),$senderName) ? trim((string)$validated['message_body']) : $senderName.': '.trim((string)$validated['message_body']),
            'FromNumber' => $fromNumber,
        ];

        if ($payload['SenderName'] === '' || $payload['FromNumber'] === '') {
            return $this->response(false, 'Sender name and from number are required before sending.', [
                'defaults' => $defaults,
            ], 422);
        }

        try {
            $result = $this->smsService->sendMessage($payload);

            if ($result['status'] >= 400) {
                return $this->response(false, 'Unable to send SMS reply.', [
                    'error' => [
                        'operation' => 'sms_send',
                        'provider_status' => $result['status'],
                        'provider_message' => is_array($result['raw']) ? ($result['raw']['message'] ?? null) : null,
                    ],
                    'provider_response' => $result['raw'],
                ], 502);
            }

            $storedMessage = null;
            $storageError = null;

            try {
                $storedMessage = $this->smsService->storeOutboundMessage(
                    $payload,
                    $result['raw'],
                    $userId,
                    $branchId,
                    [
                        'template_tag' => $validated['template_tag'] ?? null,
                    ]
                );
            } catch (\Throwable $storageException) {
                \Log::warning('SMS reply sent but could not be stored in local logs.', [
                    'error' => $storageException->getMessage(),
                ]);

                $storageError = [
                    'operation' => 'sms_send_database_store',
                    'type' => class_basename($storageException),
                    'message' => $storageException->getMessage(),
                ];
            }

            return $this->response(true, 'SMS accepted by the gateway. Delivery is not yet confirmed; the gateway phone must be online with a working SIM.', [
                'delivery_status' => $result['raw']['status'] ?? 'pending',
                'provider_response' => $result['raw'],
                'error' => $storageError,
                'reference_number' => $storedMessage?->reference_number,
                'template_tag' => $storedMessage?->template_tag,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to send SMS reply.', [
                'error' => $e->getMessage(),
            ]);

            return $this->response(false, 'Unable to send SMS reply.', [
                'error' => [
                    'operation' => 'sms_send',
                    'type' => class_basename($e),
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    public function logs(Request $request)
    {
        $limit = (int) $request->input('limit', 100);

        try {
            return $this->response(true, 'SMS logs loaded successfully.', [
                'logs' => $this->smsService->getStoredLogs($limit, $this->smsBranches($request)),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to load SMS logs.', [
                'error' => $e->getMessage(),
            ]);

            return $this->response(false, 'Unable to load SMS logs.', [
                'logs' => [],
            ], 500);
        }
    }

    public function destroyMessage(Request $request, int $messageId)
    {
        try {
            $deleted = $this->smsService->deleteStoredMessage($messageId, $this->smsBranches($request));

            if (!$deleted) {
                return $this->response(false, 'SMS message not found.', null, 404);
            }

            return $this->response(true, 'SMS message deleted successfully.');
        } catch (\Throwable $e) {
            \Log::error('Failed to delete SMS message.', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return $this->response(false, 'Unable to delete SMS message.', null, 500);
        }
    }

    public function destroyConversation(Request $request, string $counterpartyNumber)
    {
        try {
            $cutoffAt = request()->query('cutoff_at');
            $deletedCount = $this->smsService->deleteConversation($counterpartyNumber, $cutoffAt, $this->smsBranches($request));

            if ($deletedCount <= 0) {
                return $this->response(false, 'Conversation not found.', null, 404);
            }

            return $this->response(true, 'Conversation deleted successfully.', [
                'deleted_count' => $deletedCount,
                'cutoff_at' => $cutoffAt,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to delete SMS conversation.', [
                'counterparty_number' => $counterpartyNumber,
                'cutoff_at' => request()->query('cutoff_at'),
                'error' => $e->getMessage(),
            ]);

            return $this->response(false, 'Unable to delete conversation.', null, 500);
        }
    }
}
