param(
    [ValidateSet('ALL','MODELS','AUTH','VALIDATION','INVENTORY','POS_FEFO','CONTROLLED','DASHBOARD_REPORTS','API_SECURITY','FILES_SMS')]
    [string]$Suite = 'ALL',
    [string]$Filter = '',
    [switch]$PrepareDatabase,
    [switch]$Coverage
)

$ErrorActionPreference = 'Stop'
$backendPath = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $backendPath

$env:APP_ENV = 'testing'
$databaseRequired = $Suite -ne 'MODELS' -or $Filter.Trim() -ne ''
if ($databaseRequired) {
    if (-not $env:WHITEBOX_DB_DATABASE) {
        throw 'Set WHITEBOX_DB_DATABASE to a dedicated database whose name contains test or whitebox.'
    }

    if ($env:WHITEBOX_DB_DATABASE -notmatch '(?i)test|whitebox') {
        throw "Unsafe database name '$($env:WHITEBOX_DB_DATABASE)'. The test database name must contain test or whitebox."
    }

    $env:DB_CONNECTION = 'mysql'
    $env:DB_DATABASE = $env:WHITEBOX_DB_DATABASE

    if ($PrepareDatabase) {
        Write-Host "Rebuilding dedicated test database schema: $($env:DB_DATABASE)"
        php artisan migrate:fresh --force
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
} else {
    $env:DB_CONNECTION = 'mysql'
    $env:DB_DATABASE = if ($env:WHITEBOX_DB_DATABASE) { $env:WHITEBOX_DB_DATABASE } else { 'u742603369_pharmacy' }
}

$suiteFilters = @{
    MODELS = 'ModelContractTest'
    AUTH = 'AuthRateLimitingTest|ProtectedEndpointsAuthenticationTest|SuperAdminAccountsTest'
    VALIDATION = 'RequestValidationCoverageTest|SystemRevisionRulesTest|MedicineUnitsPerBoxValidationTest|BatchShelfLifeTest|TransactionDiscountTest'
    INVENTORY = 'MedicinePricingTest|MedicineUnitsPerBoxValidationTest|BatchShelfLifeTest|QueryServicesTest'
    POS_FEFO = 'FefoDeductionOrderTest|FefoDeductionTest|TransactionDiscountTest'
    CONTROLLED = 'ControlledDrugValidationTest|SystemRevisionRulesTest'
    DASHBOARD_REPORTS = 'DashboardTest|DataScopeTest|HeaderNotificationsTest'
    API_SECURITY = 'ApiResponseEnvelopeTest|ProtectedRouteMatrixTest|PublicEndpointsTest|UnknownRouteTest'
    FILES_SMS = 'DocumentStorageServiceTest|FilesApiTest|FortmedSmsServiceTest'
}

$arguments = @('artisan', 'test', '--compact')
if ($Filter.Trim() -ne '') {
    $arguments += "--filter=$Filter"
} elseif ($Suite -ne 'ALL') {
    $arguments += "--filter=$($suiteFilters[$Suite])"
}

if ($Coverage) {
    $coveragePath = Join-Path (Split-Path -Parent $backendPath) 'outputs\backend-whitebox'
    New-Item -ItemType Directory -Force -Path $coveragePath | Out-Null
    $env:XDEBUG_MODE = 'coverage'
    $arguments += "--coverage-html=$coveragePath\coverage-html"
    $arguments += "--coverage-clover=$coveragePath\coverage.xml"
    $arguments += "--coverage-text=$coveragePath\coverage.txt"
    $arguments += "--log-junit=$coveragePath\coverage-junit.xml"
}

Write-Host "Backend white-box suite: $Suite"
Write-Host "Database: $($env:DB_DATABASE)"
if ($Coverage) { Write-Host "Coverage reports: $coveragePath" }
& php @arguments
exit $LASTEXITCODE
