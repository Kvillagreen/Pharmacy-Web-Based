<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\TransactionAttachmentRequest;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionAttachment;
use App\Services\v1\FilesApi;
use App\Services\v1\FilesApiException;
use Illuminate\Http\Request;

class TransactionAttachmentController extends Controller
{
    public function __construct(private readonly FilesApi $filesApi)
    {
    }

    public function index(Request $request, string $transactionId)
    {
        $transaction = $this->authorizedTransaction($request, $transactionId);

        return response()->json([
            'success' => true,
            'data' => $transaction->attachments()
                ->whereIn('status', ['active', 'pending_upload', 'upload_failed', 'delete_failed'])
                ->latest('transaction_attachment_id')
                ->get()
                ->map(fn (TransactionAttachment $attachment) => $this->mapAttachment($attachment)),
        ]);
    }

    public function download(Request $request, string $transactionId, string $attachmentId)
    {
        $transaction = $this->authorizedTransaction($request, $transactionId);
        $attachment = $this->resolveAttachment($transaction, $attachmentId);

        if (!$attachment->remote_uuid || $attachment->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Attachment is not available for download.',
            ], 404);
        }

        try {
            $download = $this->filesApi->downloadToTemporaryFile($attachment->remote_uuid);
        } catch (FilesApiException $e) {
            return $this->upstreamError($e);
        }

        return response()
            ->download($download['path'], $attachment->original_name ?: ($attachment->remote_uuid . '.bin'), [
                'Content-Type' => $download['content_type'],
            ])
            ->deleteFileAfterSend(true);
    }

    public function replace(TransactionAttachmentRequest $request, string $transactionId, string $attachmentId)
    {
        $transaction = $this->authorizedTransaction($request, $transactionId);
        $attachment = $this->resolveAttachment($transaction, $attachmentId);

        if (!$attachment->remote_uuid) {
            return response()->json([
                'success' => false,
                'message' => 'Only uploaded attachments can be replaced.',
            ], 422);
        }

        $file = $request->file('file');
        $metadata = $this->metadataForAttachment($transaction, $attachment, $request->validated());

        try {
            $remote = $this->filesApi->replace($attachment->remote_uuid, $file, $attachment->category, $metadata);
        } catch (FilesApiException $e) {
            $attachment->update(['status' => 'replace_failed']);
            return $this->upstreamError($e);
        }

        $attachment->update([
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => 'active',
            'metadata' => array_merge($metadata, ['remote' => $remote]),
            'uploaded_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attachment replaced successfully.',
            'data' => $this->mapAttachment($attachment->fresh()),
        ]);
    }

    public function update(TransactionAttachmentRequest $request, string $transactionId, string $attachmentId)
    {
        $transaction = $this->authorizedTransaction($request, $transactionId);
        $attachment = $this->resolveAttachment($transaction, $attachmentId);
        $metadata = $this->metadataForAttachment($transaction, $attachment, $request->validated());

        try {
            $remote = $attachment->remote_uuid
                ? $this->filesApi->updateMetadata($attachment->remote_uuid, $metadata)
                : [];
        } catch (FilesApiException $e) {
            return $this->upstreamError($e);
        }

        $attachment->update([
            'label' => $request->validated('label') ?: $attachment->label,
            'metadata' => array_merge($metadata, ['remote' => $remote]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attachment metadata updated successfully.',
            'data' => $this->mapAttachment($attachment->fresh()),
        ]);
    }

    public function destroy(Request $request, string $transactionId, string $attachmentId)
    {
        $transaction = $this->authorizedTransaction($request, $transactionId);
        $attachment = $this->resolveAttachment($transaction, $attachmentId);

        try {
            if ($attachment->remote_uuid) {
                $this->filesApi->delete($attachment->remote_uuid);
            }
        } catch (FilesApiException $e) {
            if ($e->statusCode !== 404) {
                $attachment->update(['status' => 'delete_failed']);
                return $this->upstreamError($e);
            }
        }

        $attachment->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attachment deleted successfully.',
        ]);
    }

    private function authorizedTransaction(Request $request, string $transactionId): Transaction
    {
        $transaction = Transaction::query()
            ->with(['branch:branch_id,company_id', 'attachments'])
            ->findOrFail($transactionId);

        $user = $request->user();
        $canAccess = $user && (
            in_array($user->role, ['super_admin'], true)
            || ((int) $user->branch_id === (int) $transaction->branch_id)
            || (in_array($user->role, ['admin', 'owner'], true)
                && (int) ($user->branch?->company_id ?? 0) === (int) ($transaction->branch?->company_id ?? 0))
        );

        abort_unless($canAccess, 403, 'You are not authorized to access this transaction attachment.');

        return $transaction;
    }

    private function resolveAttachment(Transaction $transaction, string $attachmentId): TransactionAttachment
    {
        if ($attachmentId === 'prescription') {
            $attachment = $transaction->attachments
                ->whereIn('category', ['prescription', 'dangerous_drug'])
                ->where('status', 'active')
                ->sortByDesc('transaction_attachment_id')
                ->first();
        } elseif ($attachmentId === 'valid-id') {
            $attachment = $transaction->attachments
                ->where('category', 'valid_id')
                ->where('status', 'active')
                ->sortByDesc('transaction_attachment_id')
                ->first();
        } else {
            $attachment = $transaction->attachments->firstWhere('transaction_attachment_id', (int) $attachmentId);
        }

        abort_unless($attachment, 404, 'Attachment not found.');

        return $attachment;
    }

    private function metadataForAttachment(Transaction $transaction, TransactionAttachment $attachment, array $input): array
    {
        if (in_array($attachment->category, ['document', 'valid_id'], true)) {
            return [];
        }

        $details = $transaction->regulated_details ?? [];
        $prescription = $details['prescription_details'] ?? [];
        $dangerous = $details['dangerous_drug_details'] ?? [];

        return array_filter([
            'record_reference' => 'transaction:' . $transaction->transaction_id,
            'patient_reference' => 'patient:' . md5(strtolower((string) $transaction->patient_name) . '|' . (string) $transaction->customer_id_number . '|' . (string) $transaction->customer_contact_number),
            'prescriber_name' => $input['prescriber_name'] ?? $details['prescriber_name'] ?? $transaction->prescriber_name ?? 'Unknown Prescriber',
            'prescription_reference' => $input['prescription_reference'] ?? $dangerous['yellow_prescription_serial_number'] ?? ('transaction:' . $transaction->transaction_id),
            'authorization_reference' => $input['authorization_reference'] ?? $prescription['prescriber_prc_license_number'] ?? null,
            'drug_name' => $attachment->category === 'dangerous_drug'
                ? ($input['drug_name'] ?? $prescription['brand_name'] ?? $prescription['generic_name'] ?? 'Regulated Drug')
                : null,
            'quantity' => $attachment->category === 'dangerous_drug'
                ? ($input['quantity'] ?? (string) max(1, (int) ($prescription['quantity_dispensed'] ?? 1)))
                : null,
            'unit' => $attachment->category === 'dangerous_drug'
                ? ($input['unit'] ?? 'pcs')
                : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function mapAttachment(TransactionAttachment $attachment): array
    {
        return [
            'transaction_attachment_id' => $attachment->transaction_attachment_id,
            'transaction_id' => $attachment->transaction_id,
            'category' => $attachment->category,
            'label' => $attachment->label,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'status' => $attachment->status,
            'uploaded_at' => $attachment->uploaded_at,
            'deleted_at' => $attachment->deleted_at,
            'download_url' => url('/api/v1/transaction/' . $attachment->transaction_id . '/attachments/' . $attachment->transaction_attachment_id . '/download'),
        ];
    }

    private function upstreamError(FilesApiException $e)
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], $e->statusCode && $e->statusCode >= 400 ? min($e->statusCode, 599) : 502);
    }
}
