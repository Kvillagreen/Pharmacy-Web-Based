<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\v1\Medicine;
use App\Http\Resources\v1\MedicineCollection;
use App\Services\v1\MedicineQuery;
use App\Http\Requests\v1\MedicineRequest;
class MedicineController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    private function response($success, $message, $data = null, $extra = [])
        {
            return response()->json(array_merge([
                'success' => $success,
                'message' => $message,
                'data' => $data
            ], $extra));
        }

   public function index(Request $request)
    {
        $filter = new MedicineQuery();
        $query = Medicine::query();

        // Apply everything: filter + search + sort
        $query = $filter->apply($request, $query);

        // Paginate dynamically
        $perPage = $request->query('per_page', 10);
        $paginated = $query->paginate($perPage);
        return (new MedicineCollection($paginated))
                ->additional([
                    'total_items' => Medicine::count()
                    'total_amount' => Medicine::count()
                ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(MedicineRequest $request)
    {
        $validated = $request->validated();
        try {
            Medicine::create([
                'medicine_name' => $validated['medicineName'],
                'generic_name' => $validated['genericName'],
                'price' => $validated['price'],
                'category' => $validated['category'],
                'reorder_level' => $validated['reorderLevel'],
                'is_dangerous' => $validated['isDangerous'],
                'needs_protections' => $validated['needsProtection'],
            ]);

            return $this->response(true, 'Medicine created successfully');

        } catch (\Exception $e) {
            return $this->response(false, 'Failed to create account',$e);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(MedicineRequest $request)
    {


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
