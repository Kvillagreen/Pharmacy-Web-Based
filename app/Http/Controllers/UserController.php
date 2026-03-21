<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;

class UserController extends Controller
{
    public function store(Request $request)
    {
        // Validate request
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'email'      => 'required|email|unique:tbl_user,email',
            'password'   => 'required|string|min:6',
        ]);

        // Create user
        $user = User::create($validated);

        return response()->json([
            'success' => true,
            'data'    => $user
        ], 201);
    }

   public function display(Request $request){
    $users = User::all()->makeHidden('password'); // hide password

    return response()->json([
        'success' => true,
        'data'    => $users
    ]);
}
}