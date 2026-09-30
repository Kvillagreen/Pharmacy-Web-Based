<?php
namespace App\Http\Controllers\v1;
use App\Http\Controllers\Controller;
use App\Models\v1\Permission;
class PermissionController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => Permission::orderBy('permission_name')->get()]);
    }
    public function show(string $id)
    {
        return response()->json(['success' => true, 'data' => Permission::findOrFail($id)]);
    }
    public function store()
    {
        return response()->json(['success' => false, 'message' => 'Modules are assigned by role. Change the user role to change access.'], 422);
    }
    public function update() { return $this->store(); }
    public function destroy() { return $this->store(); }
}
