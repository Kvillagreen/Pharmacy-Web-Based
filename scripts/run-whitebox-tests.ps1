param(
    [string]$CaseId = 'SYSTEM'
)

$ErrorActionPreference = 'Stop'
$backendPath = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $backendPath

$cases = [ordered]@{
    'WB-001' = 'ApiResponseEnvelopeTest'
    'WB-002' = 'DashboardTest'
    'WB-003' = 'DataScopeTest'
    'WB-004' = 'FefoDeductionOrderTest'
    'WB-005' = 'HeaderNotificationsTest'
    'WB-006' = 'MedicineUnitsPerBoxValidationTest'
    'WB-007' = 'ProtectedEndpointsAuthenticationTest'
    'WB-008' = 'PublicEndpointsTest'
    'WB-009' = 'SuperAdminAccountsTest'
    'WB-010' = 'UnknownRouteTest'
    'WB-011' = 'test_operational_roles_receive_sales_pos_permission'
    'WB-012' = 'test_super_admin_is_kept_out_of_branch_pos_permissions'
    'WB-013' = 'test_transaction_items_support_exact_inventory_and_batch_selection'
    'WB-014' = 'test_s2_license_is_limited_to_exactly_twelve_digits'
    'WB-015' = 'test_inventory_accepts_generated_batches_and_requires_packaging'
    'WB-016' = 'test_inventory_requires_real_world_pricing_fields'
    'WB-017' = 'test_the_application_returns_a_successful_response'
    'WB-018' = 'ProtectedRouteMatrixTest'
    'WB-019' = 'QueryServicesTest'
    'WB-020' = 'FortmedSmsServiceTest'
    'WB-021' = 'RequestValidationCoverageTest'
}

$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = if ($env:WHITEBOX_DB_DATABASE) { $env:WHITEBOX_DB_DATABASE } else { 'u742603369_pharmacy' }

$normalized = $CaseId.ToUpperInvariant()

if ($normalized -in @('ALL', 'BACKEND')) {
    php artisan test
    exit $LASTEXITCODE
}

if ($normalized -eq 'FRONTEND') {
    $frontendPath = Join-Path (Split-Path -Parent $backendPath) 'Frontend'
    Set-Location -LiteralPath $frontendPath
    npm test -- --watch=false
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    npm run build
    exit $LASTEXITCODE
}

if ($normalized -eq 'SYSTEM') {
    Write-Host '=== Backend white-box suite ==='
    php artisan test
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    $frontendPath = Join-Path (Split-Path -Parent $backendPath) 'Frontend'
    Set-Location -LiteralPath $frontendPath
    Write-Host '=== Frontend component suite ==='
    npm test -- --watch=false
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    Write-Host '=== Frontend production compilation ==='
    npm run build
    exit $LASTEXITCODE
}

if (-not $cases.Contains($normalized)) {
    Write-Error "Unknown case '$CaseId'. Valid values: SYSTEM, BACKEND, FRONTEND, ALL, $($cases.Keys -join ', ')"
}

Write-Host "Running $normalized -> $($cases[$normalized])"
php artisan test --filter=$cases[$normalized]
exit $LASTEXITCODE
