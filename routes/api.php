<?php
use App\Http\Controllers\PostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route:: get('/hello', function(){
    return ["message" => "Hello World"];
});

Route::post('/user', [UserController::class, 'store']);
Route::get('/user', [UserController::class, 'display']);

Route::get('posts', [PostController:: class, 'index'])-> name('posts.index');

Route::get('posts', [PostController:: class, 'store'])-> name('posts.store');