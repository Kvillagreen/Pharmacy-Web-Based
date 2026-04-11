<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\v1\Branch;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\v1\BranchResources;
use App\Models\v1\Company;
use App\Http\Requests\v1\MethodBranchRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
class BranchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */

    public function store(MethodBranchRequest $request)
    {
        try {
            $validated = $request->validated();

            DB::beginTransaction();

           $branch = Branch::create([
                'company_id'     => $validated['company_id'],
                'branch_name'    => trim($validated['branch_name']),
                'branch_address' => trim($validated['branch_address']),
                'branch_contact' => trim($validated['branch_contact']),
                'status'         => $validated['status'] ?? 'active', // fallback
            ]);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Branch created successfully.',
                'data' => $branch,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Branch creation failed', [
                'payload' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
   public function show(string $id)
{
    // Company with ONLY active branches
    $company = Company::with([
        'branches' => function ($q) {
            $q->where('status', 'active');
        }
    ])->findOrFail($id);

    // Also filter here
    $branches = Branch::where('company_id', $id)
        ->where('status', 'active')
        ->get();
    return response()->json([
        "success" => true,
        "data" => [
            "company" => $branches,
            "branches" => BranchResources::collection($company->branches),
        ]
    ]);
}

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {

    }

    /**
     * Update the specified resource in storage.
     */
   public function update(MethodBranchRequest $request, string $id)
{
    try {
        $validated = $request->validated();

        DB::beginTransaction();

        $branch = Branch::lockForUpdate()->findOrFail($id);

        $branch->fill([
            'company_id'     => $validated['company_id'],
            'branch_name'    => trim($validated['branch_name']),
            'branch_address' => trim($validated['branch_address']),
            'branch_contact' => trim($validated['branch_contact']),
            'status'         => strtolower(trim($validated['status'])),
        ]);

        if (! $branch->isDirty()) {
            DB::rollBack();

            return response()->json([
                'success' => true,
                'message' => 'No changes were made.',
                'data' => $branch,
            ], 200);
        }

        $branch->save();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Branch updated successfully.',
            'data' => $branch->fresh(),
        ], 200);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => 'Branch not found.',
        ], 404);

    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('Branch update failed', [
            'branch_id' => $id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to update branch.',
        ], 500);
    }
}
    /**
     * Remove the specified resource from storage.
     */

public function destroy(string $id)
{
    try {
        DB::beginTransaction();

        $branch = Branch::lockForUpdate()->findOrFail($id);

        // If already inactive
        if ($branch->status === 'deleted') {
            DB::rollBack();

            return response()->json([
                'success' => true,
                'message' => 'Branch is already inactive.',
                'data' => $branch,
            ], 200);
        }

        // Set inactive instead of deleting
        $branch->update([
            'status' => 'deleted'
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Branch deactivated successfully.',
            'data' => $branch->fresh(),
        ], 200);

    } catch (ModelNotFoundException $e) {
        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => 'Branch not found.',
        ], 404);

    } catch (\Throwable $e) {
        DB::rollBack();

        Log::error('Branch deactivation failed', [
            'branch_id' => $id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to deactivate branch.',
        ], 500);
    }
}

    public function branch(){
      try{
          $branches = Branch::all(); // your existing scope
        return BranchResources::collection($branches);
      }
      catch(\Throwable $e){
        Log::error('Branch retrieval failed', [
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to retrieve branches.' . $e->getMessage(),
        ], 500);
      }
    }
}
