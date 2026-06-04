<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Services\v1\FortmedSmsService;
use Illuminate\Http\Request;

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
                    'provider_response' => $result['raw'],
                ], 502);
            }

            $storedConversations = $this->smsService->getStoredConversations($limit);

            $this->smsService->syncInboundMessages($result['messages'], $userId, $branchId);
            $storedConversations = $this->smsService->getStoredConversations($limit);

            if (!empty($result['messages']) && empty($storedConversations['conversations'])) {
                $fallbackMessages = collect($result['messages'])
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
                                'template_tag' => ($message['direction'] ?? 'inbound') === 'outbound' ? 'Sent Reply' : 'Incoming Reply',
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
                            'template_tag' => $latestDirection === 'outbound' ? 'Sent Reply' : 'Incoming Reply',
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
                'provider_response' => $result['raw'],
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to fetch SMS replies.', [
                'error' => $e->getMessage(),
            ]);

            return $this->response(false, 'Unable to load SMS replies.', [
                'messages' => [],
                'defaults' => $this->smsService->defaults(),
            ], 500);
        }
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
                    'provider_response' => $result['raw'],
                ], 502);
            }

            $storedMessage = null;

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
            }

            return $this->response(true, 'SMS reply sent successfully.', [
                'provider_response' => $result['raw'],
                'reference_number' => $storedMessage?->reference_number,
                'template_tag' => $storedMessage?->template_tag,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to send SMS reply.', [
                'error' => $e->getMessage(),
            ]);

            return $this->response(false, 'Unable to send SMS reply.', null, 500);
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
