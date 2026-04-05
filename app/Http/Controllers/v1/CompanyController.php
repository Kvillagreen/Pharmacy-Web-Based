<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\v1\MethodCompanyRequest;
use App\Models\v1\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
class CompanyController extends Controller
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
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
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
    public function update(MethodCompanyRequest $request, string $id)
{
    try {
        $validated = $request->validated();

        DB::beginTransaction();

        $company = Company::lockForUpdate()->findOrFail($id);

        $company->fill([
            'company_name'  => trim($validated['company_name']),
            'tin_number'    => trim($validated['tin_number']),
            'company_email' => strtolower(trim($validated['company_email'])),
        ]);

        // Avoid unnecessary write if nothing changed
        if (! $company->isDirty()) {
            DB::rollBack();

            return response()->json([
                'success' => true,
                'message' => 'No changes were made.',
                'data' => $company,
            ], 200);
        }

        $company->save();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Company updated successfully.',
            'data' => $company->fresh(),
        ], 200);

    } catch (ModelNotFoundException $e) {
        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => 'Company not found.',
        ], 404);

    } catch (\Throwable $e) {
        DB::rollBack();

        Log::error('Company update failed', [
            'company_id' => $id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to update company.',
        ], 500);
    }
}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
