<?php
namespace App\Services\v1;

use Illuminate\Http\Request;

class UserQuery {
    protected $safeParams = [
        "name" => ['eq'],
        "email" => ['eq'],
        "address" => ['eq'],
    ];

    protected $columnMap = [];

    protected $operatorMap = [
        'eq' => '=',
        'gt' => '>',
        'lt' => '<',
    ];

    public function transform(Request $request){
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
}
