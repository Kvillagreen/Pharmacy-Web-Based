<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Services\v1\FortmedSmsService;
use App\Services\v1\SmsOrderService;
use App\Models\v1\SmsOrder;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    public function __construct(
        private readonly FortmedSmsService $smsService,
        private readonly SmsOrderService $orderService
    ) {
    }

    public function orders(Request $request)
    {
        $query = SmsOrder::with('items.medicine')->latest('sms_order_id');
        if (!in_array($request->user()->role, ['owner', 'admin'], true)) {
            $query->where('branch_id', $request->user()->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }
        return $this->response(true, 'SMS orders loaded successfully.', $query->paginate(min(100, max(1, $request->integer('per_page', 20)))));
    }

    public function inboundOrder(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,branch_id'],
            'customer_number' => ['required', 'string', 'max:30'],
            'message_body' => ['required', 'string', 'max:1000'],
            'provider_message_id' => ['nullable', 'string', 'max:255'],
        ]);
        $branchId = (int) ($data['branch_id'] ?? $request->user()->branch_id);
        $order = $this->orderService->createFromInbound($branchId, $data['customer_number'], $data['message_body'], $data['provider_message_id'] ?? null);
        return $this->response(true, 'Pending SMS order created successfully.', $order, 201);
    }

    public function processOrder(Request $request, SmsOrder $order)
    {
        $data = $request->validate([
            'fulfillment_type' => ['required', 'in:walk-in'],
            'payment_method' => ['nullable', 'in:Cash,Card,Gcash'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
        ]);
        $transaction = $this->orderService->processWalkIn($order, $request->user(), $data['payment_method'] ?? 'Cash', isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : null);
        return $this->response(true, 'SMS order processed successfully.', ['order' => $order->fresh('items'), 'transaction' => $transaction]);
    }

    public function updateOrderStatus(Request $request, SmsOrder $order)
    {
        $data = $request->validate(['status' => ['required', 'in:cancelled,invalid']]);
        if ($order->status !== 'pending') {
            return $this->response(false, 'Only pending SMS orders can be cancelled or marked invalid.', null, 422);
        }
        $order->update(['status' => $data['status']]);
        return $this->response(true, 'SMS order status updated successfully.', $order->fresh('items'));
    }

    private function response(bool $success, string $message, $data = null, int $status = 200)
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public function replies(Request $request)
    {
        $limit = (int) $request->input('limit', 20);
        $user = $request->user();
        $userId = $user?->user_id ? (int) $user->user_id : null;
        $branchId = $user?->branch_id ? (int) $user->branch_id : null;

        try {
            $result = $this->smsService->fetchReplies($limit);
            if (!empty($result['messages'])) {
                $result['messages'] = $this->smsService->filterDeletedConversationMessages($result['messages']);
            }

            if ($result['status'] >= 400) {
                return $this->response(false, 'Unable to load SMS replies from the SMS gateway.', [
                    'messages' => [],
                    'summary' => [
                        'conversations' => [],
                        'total_messages' => 0,
                        'unique_customers' => 0,
                    ],
                    'defaults' => $this->smsService->defaults(),
                    'debug' => $this->smsService->debugContext(),
                    'error' => [
                        'operation' => 'sms_history_fetch',
                        'provider_status' => $result['status'],
                        'provider_message' => is_array($result['raw']) ? ($result['raw']['message'] ?? null) : null,
                    ],
                    'provider_response' => $result['raw'],
                ], 502);
            }

            $databaseError = null;

            try {
                $storedConversations = $this->smsService->getStoredConversations($limit);
                $this->smsService->syncInboundMessages($result['messages'], $userId, $branchId);
                $storedConversations = $this->smsService->getStoredConversations($limit);
            } catch (\Throwable $databaseException) {
                \Log::error('SMS provider history loaded but database sync failed.', [
                    'error' => $databaseException->getMessage(),
                ]);

                $databaseError = [
                    'operation' => 'sms_history_database_sync',
                    'type' => class_basename($databaseException),
                    'message' => $databaseException->getMessage(),
                ];

                $fallbackMessages = $this->buildProviderConversationFallback($result['messages'], $limit);
                $storedConversations = [
                    'conversations' => $fallbackMessages,
                    'total_messages' => count($result['messages']),
                    'unique_customers' => count($fallbackMessages),
                ];
            }

            if (!empty($result['messages']) && empty($storedConversations['conversations'])) {
                $fallbackMessages = $this->buildProviderConversationFallback($result['messages'], $limit);

                $storedConversations = [
                    'conversations' => $fallbackMessages,
                    'total_messages' => count($result['messages']),
                    'unique_customers' => count($fallbackMessages),
                ];
            }

            return $this->response(true, 'SMS replies loaded successfully.', [
                'messages' => $storedConversations['conversations'],
                'summary' => $storedConversations,
                'defaults' => $this->smsService->defaults(),
                'debug' => $this->smsService->debugContext(),
                'error' => $databaseError,
                'provider_response' => $result['raw'],
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to fetch SMS replies.', [
                'error' => $e->getMessage(),
            ]);

            return $this->response(false, 'Unable to load SMS replies.', [
                'messages' => [],
                'defaults' => $this->smsService->defaults(),
                'debug' => $this->smsService->debugContext(),
                'error' => [
                    'operation' => 'sms_history_fetch',
                    'type' => class_basename($e),
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    public function diagnostics()
    {
        try {
            return $this->response(true, 'SMS diagnostics loaded successfully.', $this->smsService->diagnostics());
        } catch (\Throwable $e) {
            \Log::error('Failed to load SMS diagnostics.', [
                'error' => $e->getMessage(),
            ]);

            return $this->response(false, 'Unable to load SMS diagnostics.', [
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildProviderConversationFallback(array $messages, int $limit): array
    {
        return collect($messages)
            ->groupBy(function (array $message) {
                $direction = (string) ($message['direction'] ?? 'inbound');

                return (string) ($direction === 'outbound'
                    ? ($message['normalized_to_number'] ?? $message['to_number'] ?? 'unknown')
                    : ($message['normalized_from_number'] ?? $message['from_number'] ?? 'unknown'));
            })
            ->map(function ($conversation, $customerNumber) {
                $latest = collect($conversation)->sortByDesc('received_at')->first();
                $latestDirection = (string) ($latest['direction'] ?? 'inbound');
                $latestCustomerNumber = $latestDirection === 'outbound'
                    ? ($latest['display_to_number'] ?? $latest['to_number'] ?? '')
                    : ($latest['display_from_number'] ?? $latest['from_number'] ?? '');
                $history = collect($conversation)
                    ->sortBy('received_at')
                    ->values()
                    ->map(fn (array $message) => [
                        'id' => $message['id'] ?? null,
                        'reference_number' => null,
                        'template_tag' => null,
                        'direction' => $message['direction'] ?? 'inbound',
                        'from_number' => $message['display_from_number'] ?? $message['from_number'] ?? '',
                        'to_number' => $message['display_to_number'] ?? $message['to_number'] ?? '',
                        'normalized_from_number' => $message['normalized_from_number'] ?? '',
                        'normalized_to_number' => $message['normalized_to_number'] ?? '',
                        'message_body' => $message['message_body'] ?? '',
                        'sender_name' => $message['sender_name'] ?? '',
                        'received_at' => $message['received_at'] ?? null,
                    ])
                    ->all();

                return [
                    'id' => 'conversation-' . ($customerNumber ?: ($latest['id'] ?? 'unknown')),
                    'from_number' => $latestCustomerNumber,
                    'to_number' => $latest['display_to_number'] ?? $latest['to_number'] ?? '',
                    'reply_to_number' => $latestCustomerNumber,
                    'normalized_customer_number' => $customerNumber,
                    'reference_number' => null,
                    'template_tag' => null,
                    'message_body' => $latest['message_body'] ?? '',
                    'sender_name' => $latest['sender_name'] ?? '',
                    'received_at' => $latest['received_at'] ?? null,
                    'last_received_at' => $latest['received_at'] ?? null,
                    'message_count' => count($conversation),
                    'history' => $history,
                    'raw' => $latest['raw'] ?? $latest,
                ];
            })
            ->sortByDesc('last_received_at')
            ->values()
            ->take(max(1, min($limit, 100)))
            ->all();
    }

    public function send(Request $request)
    {
        $defaults = $this->smsService->defaults();
        $user = $request->user();
        $userId = $user?->user_id ? (int) $user->user_id : null;
        $branchId = $user?->branch_id ? (int) $user->branch_id : null;

        $validated = $request->validate([
            'to_number' => ['required', 'string', 'max:30'],
            'message_body' => ['required', 'string', 'max:161'],
            'sender_name' => ['nullable', 'string', 'max:100'],
            'template_tag' => ['nullable', 'string', 'max:120'],
        ]);

        $payload = [
            'SenderName' => trim((string) ($validated['sender_name'] ?? $defaults['sender_name'] ?? '')),
            'ToNumber' => trim((string) $validated['to_number']),
            'MessageBody' => trim((string) $validated['message_body']),
            'FromNumber' => trim((string) ($defaults['from_number'] ?? '')),
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

            return $this->response(true, 'SMS reply sent successfully.', [
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
                'logs' => $this->smsService->getStoredLogs($limit),
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

    public function destroyMessage(int $messageId)
    {
        try {
            $deleted = $this->smsService->deleteStoredMessage($messageId);

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

    public function destroyConversation(string $counterpartyNumber)
    {
        try {
            $cutoffAt = request()->query('cutoff_at');
            $deletedCount = $this->smsService->deleteConversation($counterpartyNumber, $cutoffAt);

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
