param(
    [string]$Database = 'pharmacy_whitebox_test'
)

$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$backendPath = Join-Path $projectPath 'Backend'
$outputPath = Join-Path $projectPath 'outputs\backend-whitebox'
$qasePath = 'C:\Users\Dell\Downloads\qase_test_cases_133_final.xlsx'
$tracePath = Join-Path $outputPath 'Backend_Whitebox_Testing_133_Qase_Traceability.xlsx'
$junitPath = Join-Path $outputPath 'qase-133-junit.xml'
$detailedTerminalPath = Join-Path $outputPath 'qase-133-detailed-terminal-output.txt'
$playwrightJunitPath = Join-Path $projectPath 'outputs\e2e\junit.xml'
$evidencePath = Join-Path $outputPath 'qase-133-terminal-evidence.txt'
$pythonPath = 'C:\Users\Dell\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'

if ($Database -notmatch '(?i)test|whitebox') {
    throw "Unsafe database '$Database'. Its name must contain test or whitebox."
}

New-Item -ItemType Directory -Force -Path $outputPath | Out-Null
Set-Location -LiteralPath $backendPath
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = $Database

Write-Host "Executing complete backend PHPUnit suite against $Database..."
php artisan test "--log-junit=$junitPath" 2>&1 |
    Tee-Object -FilePath $detailedTerminalPath
$phpunitExit = $LASTEXITCODE

Write-Host "`nExecuting browser-only Qase guard and offline cases..."
Set-Location -LiteralPath (Join-Path $projectPath 'Frontend')
npm run test:e2e -- --project=desktop-chromium --grep "TC-(010|011|133)"
$playwrightExit = $LASTEXITCODE
Set-Location -LiteralPath $backendPath

Write-Host "`nProducing PASS/FAIL terminal evidence for all 133 supplied Qase cases..."
& $pythonPath (Join-Path $PSScriptRoot 'qase-133-terminal-evidence.py') $qasePath $tracePath $junitPath $playwrightJunitPath 2>&1 |
    Tee-Object -FilePath $evidencePath
$evidenceExit = $LASTEXITCODE

Write-Host "`nEvidence saved to: $evidencePath"
Write-Host "Detailed PHPUnit terminal output saved to: $detailedTerminalPath"
if ($phpunitExit -ne 0 -or $playwrightExit -ne 0 -or $evidenceExit -ne 0) { exit 1 }
