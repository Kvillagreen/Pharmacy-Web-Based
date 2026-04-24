<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionClaimNote;
use App\Models\v1\TransactionClaimUpdate;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TransactionClaimController extends Controller
{
    private const CLAIM_STATUSES = [
        'pending_documents',
        'documents_ready',
        'under_review',
        'approved',
        'rejected',
        'completed',
    ];

    public function index(Request $request)
    {
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);
        $perPage = (int) $request->input('per_page', 12);
        $search = trim((string) $request->input('search', ''));
        $status = trim((string) $request->input('status', ''));

        $query = $this->claimsQuery($companyId, $branchId)
            ->with([
                'branch:branch_id,branch_name,company_id',
                'user:user_id,first_name,last_name',
                'items.medicine:medicine_id,medicine_name,generic_name,price',
            ])
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($claimQuery) use ($search) {
                    $claimQuery->where('transaction_id', 'like', '%' . $search . '%')
                        ->orWhere('patient_name', 'like', '%' . $search . '%')
                        ->orWhere('membership_id', 'like', '%' . $search . '%')
                        ->orWhere('hmo_provider', 'like', '%' . $search . '%');
                });
            })
            ->when($status !== '' && $status !== 'all', fn ($builder) => $builder->where('claim_status', $status))
            ->latest('created_at');

        $claims = $query->paginate($perPage);

        $summaryQuery = $this->claimsQuery($companyId, $branchId);

        return response()->json([
            'success' => true,
            'data' => $claims->items(),
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'pending_documents' => (clone $summaryQuery)->where('claim_status', 'pending_documents')->count(),
                'documents_ready' => (clone $summaryQuery)->where('claim_status', 'documents_ready')->count(),
                'under_review' => (clone $summaryQuery)->where('claim_status', 'under_review')->count(),
                'approved' => (clone $summaryQuery)->where('claim_status', 'approved')->count(),
                'rejected' => (clone $summaryQuery)->where('claim_status', 'rejected')->count(),
                'completed' => (clone $summaryQuery)->where('claim_status', 'completed')->count(),
            ],
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page' => $claims->lastPage(),
                'per_page' => $claims->perPage(),
                'total' => $claims->total(),
            ],
        ]);
    }

    public function show(Transaction $transaction)
    {
        $this->ensureClaimTransaction($transaction);

        $transaction->load([
            'branch:branch_id,branch_name,company_id',
            'user:user_id,first_name,last_name',
            'items.medicine:medicine_id,medicine_name,generic_name,price',
            'claimUpdates.user:user_id,first_name,last_name',
            'claimNotes.user:user_id,first_name,last_name',
        ]);

        return response()->json([
            'success' => true,
            'data' => $transaction,
        ]);
    }

    public function uploadDocuments(Request $request, Transaction $transaction)
    {
        $this->ensureClaimTransaction($transaction);

        $validated = $request->validate([
            'prescription' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'member_id_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'mark_documents_done' => ['nullable', 'boolean'],
        ]);

        if (!$request->hasFile('prescription') && !$request->hasFile('member_id_image') && !$request->boolean('mark_documents_done')) {
            return response()->json([
                'success' => false,
                'message' => 'Please upload at least one document or mark the claim documents as complete.',
            ], 422);
        }

        if ($request->hasFile('prescription')) {
            $transaction->prescription_path = $this->replaceStoredDocument($transaction->prescription_path, $request, 'prescription');
        }

        if ($request->hasFile('member_id_image')) {
            $transaction->member_id_image_path = $this->replaceStoredDocument($transaction->member_id_image_path, $request, 'member_id_image');
        }

        $hasAllDocuments = (bool) ($transaction->prescription_path && $transaction->member_id_image_path);
        $markDocumentsDone = $request->boolean('mark_documents_done') || $hasAllDocuments;

        $transaction->documents_submitted = $markDocumentsDone;
        $transaction->documents_completed_at = $markDocumentsDone ? now() : null;
        $transaction->claim_status = $markDocumentsDone
            ? ($transaction->claim_status === 'pending_documents' ? 'documents_ready' : $transaction->claim_status)
            : 'pending_documents';
        $transaction->save();

        $this->recordClaimUpdate(
            $transaction,
            'documents',
            $markDocumentsDone ? 'Claim documents completed' : 'Claim documents updated',
            $markDocumentsDone
                ? 'All required claim documents have been marked complete.'
                : 'Claim documents were uploaded or updated.',
            [
                'prescription_uploaded' => $request->hasFile('prescription'),
                'member_id_uploaded' => $request->hasFile('member_id_image'),
                'documents_submitted' => $transaction->documents_submitted,
            ]
        );

        $transaction->load([
            'branch:branch_id,branch_name,company_id',
            'user:user_id,first_name,last_name',
            'items.medicine:medicine_id,medicine_name,generic_name,price',
            'claimUpdates.user:user_id,first_name,last_name',
            'claimNotes.user:user_id,first_name,last_name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Claim documents updated successfully.',
            'data' => $transaction,
        ]);
    }

    public function updateOverview(Request $request, Transaction $transaction)
    {
        $this->ensureClaimTransaction($transaction);

        $validated = $request->validate([
            'claim_status' => ['required', 'string', 'in:' . implode(',', self::CLAIM_STATUSES)],
            'claim_amount_covered' => ['nullable', 'numeric', 'min:0'],
            'documents_submitted' => ['nullable', 'boolean'],
        ]);

        $transaction->claim_status = $validated['claim_status'];
        $transaction->claim_amount_covered = $validated['claim_amount_covered'] ?? null;

        if (array_key_exists('documents_submitted', $validated)) {
            $transaction->documents_submitted = (bool) $validated['documents_submitted'];
            $transaction->documents_completed_at = $transaction->documents_submitted ? now() : null;
        }

        $transaction->save();

        $covered = $transaction->claim_amount_covered !== null
            ? ' Amount covered: PHP ' . number_format((float) $transaction->claim_amount_covered, 2) . '.'
            : '';

        $this->recordClaimUpdate(
            $transaction,
            'status',
            'Claim overview updated',
            'Claim status changed to ' . str_replace('_', ' ', $transaction->claim_status) . '.' . $covered,
            [
                'claim_status' => $transaction->claim_status,
                'claim_amount_covered' => $transaction->claim_amount_covered,
                'documents_submitted' => $transaction->documents_submitted,
            ]
        );

        $transaction->load([
            'branch:branch_id,branch_name,company_id',
            'user:user_id,first_name,last_name',
            'items.medicine:medicine_id,medicine_name,generic_name,price',
            'claimUpdates.user:user_id,first_name,last_name',
            'claimNotes.user:user_id,first_name,last_name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Claim overview updated successfully.',
            'data' => $transaction,
        ]);
    }

    public function addNote(Request $request, Transaction $transaction)
    {
        $this->ensureClaimTransaction($transaction);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:4000'],
        ]);

        $note = TransactionClaimNote::create([
            'transaction_id' => $transaction->transaction_id,
            'user_id' => auth()->id(),
            'note' => $validated['note'],
        ]);

        $this->recordClaimUpdate(
            $transaction,
            'note',
            'Claim note added',
            $validated['note'],
            ['note_id' => $note->transaction_claim_note_id]
        );

        $transaction->load([
            'branch:branch_id,branch_name,company_id',
            'user:user_id,first_name,last_name',
            'items.medicine:medicine_id,medicine_name,generic_name,price',
            'claimUpdates.user:user_id,first_name,last_name',
            'claimNotes.user:user_id,first_name,last_name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Claim note added successfully.',
            'data' => $transaction,
        ], 201);
    }

    private function claimsQuery(int $companyId, int $branchId)
    {
        return Transaction::query()
            ->whereIn('transaction_type', ['hmo', 'philhealth'])
            ->when($branchId > 0, fn ($builder) => $builder->where('branch_id', $branchId))
            ->when($branchId <= 0 && $companyId > 0, function ($builder) use ($companyId) {
                $builder->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId));
            });
    }

    private function ensureClaimTransaction(Transaction $transaction): void
    {
        if (!in_array($transaction->transaction_type, ['hmo', 'philhealth'], true)) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'This transaction is not an HMO or PhilHealth claim.',
            ], 422));
        }
    }

    private function replaceStoredDocument(?string $existingPath, Request $request, string $field): string
    {
        if ($existingPath && Storage::disk('public')->exists($existingPath)) {
            Storage::disk('public')->delete($existingPath);
        }

        return $request->file($field)->store('transactions/documents', 'public');
    }

    private function recordClaimUpdate(
        Transaction $transaction,
        string $updateType,
        string $title,
        ?string $description = null,
        ?array $meta = null
    ): void {
        TransactionClaimUpdate::create([
            'transaction_id' => $transaction->transaction_id,
            'user_id' => auth()->id(),
            'update_type' => $updateType,
            'title' => $title,
            'description' => $description,
            'meta' => $meta,
        ]);
    }
}
