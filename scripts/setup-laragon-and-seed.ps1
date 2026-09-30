param(
    [string]$DbName = 'pharmacy_whitebox_test',
    [string]$DbUser = 'root',
    [string]$DbPass = '',
    [string]$DbHost = '127.0.0.1',
    [int]$DbPort = 3306
)

$ErrorActionPreference = 'Stop'
$backendPath = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $backendPath

Write-Host "Running local seed + migrate script using Laragon-style DB settings"

# Ensure PHP is available
$php = (& php -v) 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Error "PHP is not available in PATH. Please start Laragon (which provides PHP) and re-open this PowerShell session, or add PHP to PATH."
    exit 1
}

# Prepare .env
$envFile = Join-Path $backendPath '.env'
$envExample = Join-Path $backendPath '.env.example'
if (-not (Test-Path $envFile) -and (Test-Path $envExample)) {
    Copy-Item -LiteralPath $envExample -Destination $envFile
    Write-Host "Copied .env.example -> .env"
}

if (-not (Test-Path $envFile)) {
    Write-Error ".env file not found and .env.example was not available. Please create an .env file from your environment template."
    exit 1
}

# Update DB settings in .env
(Get-Content -LiteralPath $envFile) -replace '^DB_CONNECTION=.*', "DB_CONNECTION=mysql" |
    ForEach-Object {$_ -replace '^DB_HOST=.*', "DB_HOST=$DbHost"} |
    ForEach-Object {$_ -replace '^DB_PORT=.*', "DB_PORT=$DbPort"} |
    ForEach-Object {$_ -replace '^DB_DATABASE=.*', "DB_DATABASE=$DbName"} |
    ForEach-Object {$_ -replace '^DB_USERNAME=.*', "DB_USERNAME=$DbUser"} |
    ForEach-Object {$_ -replace '^DB_PASSWORD=.*', "DB_PASSWORD=$DbPass"} |
    Set-Content -LiteralPath $envFile -Encoding UTF8

Write-Host "Updated .env DB settings: host=$DbHost db=$DbName user=$DbUser"

# Optional: install composer dependencies if missing
if (-not (Test-Path (Join-Path $backendPath 'vendor'))) {
    if (Get-Command composer -ErrorAction SilentlyContinue) {
        Write-Host "Installing PHP dependencies via composer..."
        composer install --no-interaction --prefer-dist
    } else {
        Write-Warning "Composer not found. Please install composer or run 'composer install' manually in Backend/."
    }
}

# Create database in MariaDB/MySQL (Laragon default) if possible
try {
    $mysqlExe = 'mysql'
    if (Get-Command $mysqlExe -ErrorAction SilentlyContinue) {
        $createCmd = "CREATE DATABASE IF NOT EXISTS `$DbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        $args = "-h $DbHost -P $DbPort -u $DbUser"
        if ($DbPass -ne '') { $args += " -p$DbPass" }
        & $mysqlExe $args -e $createCmd
        Write-Host "Ensured database $DbName exists (via mysql)."
    } else {
        Write-Warning "mysql not found in PATH. Please create the database $DbName in Laragon's DB manager or using phpMyAdmin."
    }
} catch {
    Write-Warning "Could not create database automatically: $_"
}

# Run migrations & seed
Write-Host "Running migrations and seeding (this may take a while)..."
php artisan migrate:fresh --seed

# Run backend whitebox suite once seeded
Write-Host "Running backend white-box tests (php artisan test)..."
php artisan test

Write-Host "Setup, seed and test run complete. If tests still fail due to missing PDO drivers, ensure Laragon's PHP has pdo_mysql and pdo_sqlite enabled." 
