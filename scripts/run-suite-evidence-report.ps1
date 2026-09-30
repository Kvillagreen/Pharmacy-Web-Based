param(
    [string]$Python = "python"
)

$ErrorActionPreference = 'Stop'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Split-Path -Parent (Split-Path -Parent $scriptDir)
$pythonScript = Join-Path $scriptDir 'generate_suite_evidence_report.py'

if (-not (Test-Path $pythonScript)) {
    throw "Missing Python generator: $pythonScript"
}

& $Python $pythonScript
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
