<?php

namespace App\Services\v1;

use Illuminate\Http\Request;

class MedicineQuery {

    // ✅ Allowed filters
    protected $safeParams = [
        "medicine_name" => ['eq'],
        "price" => ['eq', 'gt', 'lt'],
        "created_at" => ['eq', 'gt', 'lt'],
        "medicine_id" => ['eq'],
        "generic_name" => ['eq'],
        "category" => ['eq'],
    ];

    // ✅ Optional column mapping (if DB column differs)
    protected $columnMap = [
        'stocks' => 'inventories.stocks',
        'created_at' => 'inventories.created_at',
        'medicine_id' => 'inventories.medicine_id',
    ];

    // ✅ Operators mapping
    protected $operatorMap = [
        'eq' => '=',
        'gt' => '>',
        'lt' => '<',
    ];

    // ✅ Allowed sortable fields
    protected $sortable = [
        "medicine_name",
        "price",
        "created_at",
        "medicine_id",
        "generic_name",
        "batch_id",
        "expiry_date",
        "stocks"
    ];

    // ---------------------------------
    // ✅ TRANSFORM FILTERS
    // ---------------------------------
    public function transform(Request $request) {
        $eleQuery = [];

        foreach ($this->safeParams as $param => $operators) {
            $query = $request->query($param);

            if (!isset($query) || !is_array($query)) {
                continue;
            }

            $column = $this->columnMap[$param] ?? $param;

            foreach ($operators as $operator) {
                if (isset($query[$operator])) {
                    $eleQuery[] = [
                        $column,
                        $this->operatorMap[$operator],
                        $query[$operator]
                    ];
                }
            }
        }
        return $eleQuery;
    }

    // ---------------------------------
    // ✅ GET SORT
    // ---------------------------------
    public function getSort(Request $request) {
        $sort = $request->query('sort');

        if (!$sort) return null;

        $direction = 'asc';

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $sort = substr($sort, 1);
        }

        if (!in_array($sort, $this->sortable)) return null;

        $column = $this->columnMap[$sort] ?? $sort;

        return [$column, $direction];
    }

    // ---------------------------------
    // ✅ APPLY FILTER + SEARCH + SORT TO QUERY
    // ---------------------------------
    public function apply(Request $request, $query) {
        // Apply filters
        $filters = $this->transform($request);
        if (!empty($filters)) {
            foreach ($filters as $item) {
                $query->where($item[0], $item[1], $item[2]);
            }
        }

        // Apply search
        $search = $request->query('search');
       if ($search) {
    $query->where(function($q) use ($search) {
        $q->where('medicines.category', 'like', "%$search%")
          ->orWhere('medicines.generic_name', 'like', "%$search%")
          ->orWhere('medicines.medicine_name', 'like', "%$search%")
          ->orWhere('medicines.medicine_id', 'like', "%$search%");
    });
}

        // Apply sort
        $sort = $this->getSort($request);
        if ($sort) {
            $query->orderBy($sort[0], $sort[1]);
        }

        return $query;
    }
}
