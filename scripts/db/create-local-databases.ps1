# Creates this template's own PostgreSQL roles and databases on the local
# server (ADR-0012), and writes their credentials to git-ignored files. It
# touches only the names below; every other role and database is left alone.
#
#   .\scripts\db\create-local-databases.ps1
#
# psql asks for the postgres password itself; this script never sees it.
# Safe to run again: missing objects are created, passwords are rotated and
# the files rewritten.
param(
    [string]$Psql = 'D:\PostgreSQL\18\bin\psql.exe',
    [string]$DbHost = '127.0.0.1',
    [int]$Port = 5432,
    [string]$Superuser = 'postgres'
)
$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')

$owner = 'template_owner'
$app = 'template_app'
$backup = 'template_backup'
$databases = @('engineering_template', 'engineering_template_testing')

if (-not (Test-Path $Psql)) { throw "psql not found at $Psql (pass -Psql)" }

Write-Host "This creates or updates, on ${DbHost}:${Port}:"
Write-Host "  roles     $owner (migrations), $app (runtime, DML only), $backup (read only)"
Write-Host "  databases $($databases -join ', ') (owned by $owner)"
Write-Host 'Nothing else on the server is changed.'
if ((Read-Host 'Type yes to continue') -ne 'yes') { Write-Host 'Stopped.'; exit 1 }

function New-Secret {
    $bytes = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return [Convert]::ToBase64String($bytes).Replace('+', 'x').Replace('/', 'y').Replace('=', '')
}
$secrets = @{ $owner = New-Secret; $app = New-Secret; $backup = New-Secret }

$perDatabase = foreach ($db in $databases) {
@"
SELECT 'CREATE DATABASE $db OWNER $owner' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '$db')\gexec
REVOKE ALL ON DATABASE $db FROM PUBLIC;
GRANT CONNECT ON DATABASE $db TO $owner, $app, $backup;
\connect $db
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO $app, $backup;
ALTER DEFAULT PRIVILEGES FOR ROLE $owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $app;
ALTER DEFAULT PRIVILEGES FOR ROLE $owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO $app;
\connect postgres
"@
}

$sql = @"
\set ON_ERROR_STOP on
DO `$`$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$owner') THEN CREATE ROLE $owner; END IF;
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$app') THEN CREATE ROLE $app; END IF;
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$backup') THEN CREATE ROLE $backup; END IF;
END
`$`$;
ALTER ROLE $owner WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION CONNECTION LIMIT 10 PASSWORD '$($secrets[$owner])';
ALTER ROLE $app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION CONNECTION LIMIT 40 PASSWORD '$($secrets[$app])';
ALTER ROLE $backup WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION CONNECTION LIMIT 2 PASSWORD '$($secrets[$backup])';
ALTER ROLE $app SET lock_timeout = '5s';
ALTER ROLE $app SET statement_timeout = '30s';
ALTER ROLE $app SET idle_in_transaction_session_timeout = '60s';
ALTER ROLE $owner SET lock_timeout = '10s';
GRANT pg_read_all_data TO $backup;
$($perDatabase -join "`n")
"@

$enc = New-Object System.Text.UTF8Encoding($false)
$sqlFile = Join-Path $env:TEMP ("template-db-" + [guid]::NewGuid() + '.sql')
try {
    [IO.File]::WriteAllText($sqlFile, $sql, $enc)
    & $Psql -h $DbHost -p $Port -U $Superuser -d postgres -X -q -f $sqlFile
    if ($LASTEXITCODE -ne 0) { throw "psql failed (exit $LASTEXITCODE); no credential files were written" }
} finally {
    Remove-Item -LiteralPath $sqlFile -Force -ErrorAction SilentlyContinue
}

# Sets KEY=value lines in a dotenv file: replaces an existing (or commented)
# line for the key, otherwise appends. A missing file is created.
function Set-EnvValues([string]$Path, [System.Collections.IDictionary]$Values) {
    $lines = New-Object 'System.Collections.Generic.List[string]'
    if (Test-Path $Path) { $lines.AddRange([IO.File]::ReadAllLines($Path)) }
    foreach ($key in $Values.Keys) {
        $line = "$key=$($Values[$key])"
        $pattern = '^\s*#?\s*' + [regex]::Escape([string]$key) + '='
        $index = $lines.FindIndex([Predicate[string]]{ param($l) $l -match $pattern })
        if ($index -ge 0) { $lines[$index] = $line } else { $lines.Add($line) }
    }
    [IO.File]::WriteAllLines($Path, $lines.ToArray(), $enc)
}

$envFile = Join-Path $root '.env'
$current = [IO.File]::ReadAllText($envFile)
$appKey = [regex]::Match($current, '(?m)^APP_KEY=(.*)$').Groups[1].Value.Trim()
$auditKey = [regex]::Match($current, '(?m)^AUDIT_HMAC_KEY=(.*)$').Groups[1].Value.Trim()

# Development: the application runs as the DML-only role.
Set-EnvValues $envFile ([ordered]@{
    DB_CONNECTION = 'pgsql'; DB_HOST = $DbHost; DB_PORT = "$Port"; DB_DATABASE = $databases[0]
    DB_USERNAME = $app; DB_PASSWORD = $secrets[$app]; DB_APP_ROLE = $app
})

# Owner and backup credentials, for scripts/db/migrate.ps1 and later backups.
Set-EnvValues (Join-Path $root '.env.db-roles') ([ordered]@{
    OWNER_DB_USERNAME = $owner; OWNER_DB_PASSWORD = $secrets[$owner]
    BACKUP_DB_USERNAME = $backup; BACKUP_DB_PASSWORD = $secrets[$backup]
})

# Tests run as the owner (they migrate) against the test database only.
Set-EnvValues (Join-Path $root '.env.testing') ([ordered]@{
    APP_KEY = $appKey; AUDIT_HMAC_KEY = $auditKey
    DB_HOST = $DbHost; DB_PORT = "$Port"; DB_TESTING_DATABASE = $databases[1]
    DB_USERNAME = $owner; DB_PASSWORD = $secrets[$owner]; DB_APP_ROLE = $app
})

Write-Host 'Done. Written: .env (DB_*), .env.db-roles, .env.testing (all git-ignored).'
Write-Host 'Next: .\scripts\db\migrate.ps1, then composer test:pgsql'
