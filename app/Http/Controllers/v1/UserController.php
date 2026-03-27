<?php

namespace App\Http\Controllers\v1;

use App\Models\v1\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\v1\UserCollection;
use App\Http\Resources\v1\UserResource;
use Illuminate\Http\Request;
use App\Services\v1\UserQuery;

class UserController extends Controller
{
    // 🔹 Protect all methods using Sanctum
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // 🔹 List users
    public function index(Request $request)
    {
        $filter = new UserQuery();
        $queryItems = $filter->transform($request);

        $users = User::query();

        if (!empty($queryItems)) {
            $users->where($queryItems);
        }

        return new UserCollection($users->paginate(10));
    }

    // 🔹 Show user
    public function show(string $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        return new UserResource($user);
    }

    // 🔹 Placeholders for CRUD
    public function store(Request $request) {}
    public function update(Request $request, string $id) {}
    public function destroy(string $id) {}
}
