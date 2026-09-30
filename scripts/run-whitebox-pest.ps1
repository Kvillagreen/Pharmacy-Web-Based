param([switch]$Coverage)
$ErrorActionPreference = 'Stop'
$backend = Split-Path $PSScriptRoot -Parent
$workspace = Split-Path $backend -Parent
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$evidence = Join-Path $workspace "outputs\whitebox-testing\pest-$stamp"
New-Item -ItemType Directory -Path $evidence -Force | Out-Null
$transcript = Join-Path $evidence 'terminal-output.txt'
$previousEvidence = $env:WHITEBOX_EVIDENCE_DIR
Push-Location $backend
try {
    $env:WHITEBOX_EVIDENCE_DIR = $evidence
    $pestArgs = @('vendor/bin/pest', '--configuration', 'phpunit.whitebox.xml', '--colors=never',
        '--log-junit', (Join-Path $evidence 'junit.xml'), '--testdox-html', (Join-Path $evidence 'testdox.html'))
    $phpArgs = @()
    if ($Coverage) {
        $phpArgs = @('-d', 'xdebug.mode=coverage')
        $pestArgs += @('--coverage-clover', (Join-Path $evidence 'coverage.xml'), '--coverage-html', (Join-Path $evidence 'coverage'))
    }
    $command = 'php ' + (($phpArgs + $pestArgs | ForEach-Object { '"' + $_ + '"' }) -join ' ')
    @(
        'PHARMACY WHITEBOX TESTING - PEST TERMINAL EVIDENCE',
        ('Started: ' + (Get-Date -Format o)),
        ('Working directory: ' + $backend),
        'Workbook: outputs/whitebox-testing/Pharmacy_Whitebox_Functionality_Test_Cases.xlsx',
        'Scope: WB-001 through WB-021; exact workbook expectations; no placeholder tests.',
        'Database: isolated SQLite :memory:; forced test configuration; live HTTP integrations blocked.',
        ('Command: ' + $command), ''
    ) | Tee-Object -FilePath $transcript
    php --version | Tee-Object -FilePath $transcript -Append
    php vendor/bin/pest --version --colors=never | Tee-Object -FilePath $transcript -Append
    & php @phpArgs @pestArgs 2>&1 | Tee-Object -FilePath $transcript -Append
    $testExit = $LASTEXITCODE
    @('', ('Finished: ' + (Get-Date -Format o)), ('Pest exit code: ' + $testExit), ('Evidence directory: ' + $evidence)) | Tee-Object -FilePath $transcript -Append
    $files = @('composer.json', 'composer.lock', 'phpunit.whitebox.xml', 'tests/Pest.php', 'tests/whitebox-bootstrap.php', 'tests/Whitebox/WorkbookCasesTest.php')
    $manifest = @{}
    foreach ($file in $files) { $manifest[$file] = (Get-FileHash -LiteralPath (Join-Path $backend $file) -Algorithm SHA256).Hash }
    $manifest['source_workbook'] = (Get-FileHash -LiteralPath (Join-Path $workspace 'outputs/whitebox-testing/Pharmacy_Whitebox_Functionality_Test_Cases.xlsx') -Algorithm SHA256).Hash
    @{timestamp=(Get-Date -Format o); exit_code=$testExit; command=$command; sha256=$manifest} | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $evidence 'run-manifest.json') -Encoding UTF8
    Copy-Item -LiteralPath 'tests/Whitebox/WorkbookCasesTest.php' -Destination (Join-Path $evidence 'WorkbookCasesTest.php')
    $evidence | Set-Content -LiteralPath (Join-Path $workspace 'outputs/whitebox-testing/latest-pest-run.txt') -Encoding UTF8
} finally {
    $env:WHITEBOX_EVIDENCE_DIR = $previousEvidence
    Pop-Location
}
exit $testExit
