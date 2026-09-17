<?php

namespace App\Services\v1;

use Illuminate\Http\Request;

class UserQuery
{
    protected $safeParams = [
        'user_id' => ['eq'],
        'first_name' => ['eq'],
        'last_name' => ['eq'],
        'status' => ['eq'],
        'role' => ['eq'],
        'branch_id' => ['eq'],
    ];

    protected $columnMap = [
        // 'branch_name' => 'branches.branch_name',
    ];

    protected $operatorMap = [
        'eq' => '=',
        'gt' => '>',
        'lt' => '<',
    ];

    protected $sortable = [
        'user_id',
        'first_name',
        'last_name',
        'status',
        'role',
        'branch_id',
        'created_at',
    ];

    public function transform(Request $request): array
    {
        $eleQuery = [];

        foreach ($this->safeParams as $param => $operators) {
            $query = $request->query($param);

            if (!isset($query) || !is_array($query)) {
                continue;
            }

            $column = $this->columnMap[$param] ?? $param;

            foreach ($operators as $operator) {
                if (isset($query[$operator]) && isset($this->operatorMap[$operator])) {
                    $eleQuery[] = [
                        $column,
                        $this->operatorMap[$operator],
                        $query[$operator],
                    ];
                }
            }
        }

        return $eleQuery;
    }

    public function getSort(Request $request): ?array
    {
        $sort = $request->query('sort');

        if (!$sort) {
            return null;
        }

        $direction = 'asc';

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $sort = substr($sort, 1);
        }

        if (!in_array($sort, $this->sortable, true)) {
            return null;
        }

        $column = $this->columnMap[$sort] ?? $sort;

        return [$column, $direction];
    }

    public function apply(Request $request, $query)
    {
        $filters = $this->transform($request);

        if (!empty($filters)) {
            foreach ($filters as $item) {
                $query->where($item[0], $item[1], $item[2]);
            }
        }

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('user_id', 'like', "%{$search}%")
                    ->orWhereHas('branch', function ($bq) use ($search) {
                        $bq->where('branch_name', 'like', "%{$search}%")
                           ->orWhere('branch_address', 'like', "%{$search}%");
                    })
                    ->orWhereHas('branch.company', function ($cq) use ($search) {
                        $cq->where('company_name', 'like', "%{$search}%")
                           ->orWhere('company_email', 'like', "%{$search}%");
                    });
            });
        }

        $sort = $this->getSort($request);

        if ($sort) {
            $query->orderBy($sort[0], $sort[1]);
        } else {
            $query->orderBy('user_id', 'desc');
        }

        return $query;
    }
}
