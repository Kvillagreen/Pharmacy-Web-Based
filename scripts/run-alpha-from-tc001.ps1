param(
    [string]$From = 'TC-001',
    [string]$To = 'TC-133',
    [switch]$StopOnFail
)

$ErrorActionPreference = 'Stop'
$backendPath = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $backendPath

$jsonPath = Join-Path $backendPath 'tests\qase-workflow-map.json'
$map = Get-Content -LiteralPath $jsonPath -Raw | ConvertFrom-Json

$ids = [System.Collections.Generic.List[string]]::new()
foreach ($key in $map.PSObject.Properties.Name) {
    $ids.Add([string]$key)
}
$ids = $ids | Sort-Object {
    [int]($_ -replace '.*?(\d+)', '$1')
}

$fromNum = [int]($From -replace '.*?(\d+)', '$1')
$toNum = [int]($To -replace '.*?(\d+)', '$1')

$selected = @()
foreach ($id in $ids) {
    $num = [int]($id -replace '.*?(\d+)', '$1')
    if ($num -ge $fromNum -and $num -le $toNum) {
        $selected += $id
    }
}

if (-not $selected.Count) {
    throw "No TC IDs found in range $From to $To"
}

$failures = 0
foreach ($id in $selected) {
    $method = $map.PSObject.Properties[$id].Value
    if ($method -match '\|') {
        $method = ($method -split '\|')[0].Trim()
    }
    if ($method -match '::') {
        $method = $method.Split('::')[-1]
    }
    Write-Host "=== $id ==="
    php artisan test --filter=$method
    if ($LASTEXITCODE -ne 0) {
        $failures++
        Write-Host "[FAIL] $id -> $method"
        if ($StopOnFail) { exit $LASTEXITCODE }
    } else {
        Write-Host "[PASS] $id -> $method"
    }
}

Write-Host "TOTAL RUN: $($selected.Count) | FAILURES: $failures"
if ($failures -gt 0) { exit 1 }
exit 0
