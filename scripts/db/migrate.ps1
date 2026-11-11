# Runs migrations as the schema owner (ADR-0012). The application itself
# connects as the DML-only role from .env; real environment variables win
# over .env, so only this process uses the owner.
#
#   .\scripts\db\migrate.ps1            # php artisan migrate
#   .\scripts\db\migrate.ps1 fresh      # php artisan migrate:fresh (local only)
param([string]$Mode = '')
$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$roles = Join-Path $root '.env.db-roles'
if (-not (Test-Path $roles)) { throw 'Missing .env.db-roles; run scripts\db\create-local-databases.ps1 first' }

$values = @{}
foreach ($line in [IO.File]::ReadAllLines($roles)) {
    if ($line -match '^([A-Z_]+)=(.*)$') { $values[$Matches[1]] = $Matches[2] }
}

$env:DB_USERNAME = $values['OWNER_DB_USERNAME']
$env:DB_PASSWORD = $values['OWNER_DB_PASSWORD']
try {
    Push-Location $root
    $command = if ($Mode -eq 'fresh') { 'migrate:fresh' } else { 'migrate' }
    php artisan $command --no-interaction
    if ($LASTEXITCODE -ne 0) { throw "artisan $command failed" }
} finally {
    Pop-Location
    Remove-Item Env:DB_USERNAME, Env:DB_PASSWORD -ErrorAction SilentlyContinue
}
