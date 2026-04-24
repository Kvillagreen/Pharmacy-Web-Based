<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\LoginRequest;
use App\Http\Requests\v1\MobileRegisterRequest;
use App\Models\v1\Batch;
use App\Models\v1\Branch;
use App\Models\v1\Company;
use App\Models\v1\Inventory;
use App\Models\v1\MobileUser;
use App\Models\v1\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class MobileAuthController extends Controller
{
    private function response($success, $message, $data = null, $extra = [])
    {
        return response()->json(array_merge([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $extra));
    }



    public function companies()
    {
        $companies = Company::query()
            ->whereHas('branches', fn ($query) => $query->where('status', 'active'))
            ->with(['branches' => fn ($query) => $query->where('status', 'active')->orderBy('branch_name')])
            ->orderBy('company_name')
            ->get()
            ->map(function (Company $company) {
                return [
                    'company_id' => (int) $company->company_id,
                    'company_name' => $company->company_name,
                    'company_email' => $company->company_email,
                    'branches' => $company->branches->map(fn (Branch $branch) => [
                        'branch_id' => (int) $branch->branch_id,
                        'branch_name' => $branch->branch_name,
                        'branch_address' => $branch->branch_address,
                        'branch_contact' => $branch->branch_contact,
                    ])->values(),
                ];
            })
            ->values();

        return $this->response(true, 'Companies loaded successfully', $companies);
    }




}
