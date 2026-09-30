<?php
$request = Illuminate\Http\Request::create('/api/v1/transaction?company_id=1&per_page=16&branch_id=1', 'GET');
$request->headers->set('Accept', 'application/json');
// we bypass auth for a moment by directly resolving the controller method.
$controller = app()->make(\App\Http\Controllers\v1\TransactionController::class);
$response = $controller->index($request);
echo json_encode($response->getData());
