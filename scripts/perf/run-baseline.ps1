# One local baseline run (docs/operations/performance-testing.md): clears
# rate-limit and query statistics, samples CPU and memory of PHP, Nginx and
# PostgreSQL every 2 s while k6 runs, then saves k6's summary, the resource
# samples and the top statements from pg_stat_statements.
#
#   .\scripts\perf\run-baseline.ps1 -Name normal-50 -VisitorVus 30 -StaffVus 20 -Hold 2m
#
# Local only: k6 refuses non-local hosts, and statistics are read with the
# template's own owner role.
param(
    [Parameter(Mandatory)][ValidatePattern('^[a-z0-9-]+$')][string]$Name,
    [int]$VisitorVus = 30,
    [int]$StaffVus = 20,
    [string]$Hold = '2m',
    [string]$BaseUrl = 'http://engineering-template.test',
    [string]$Psql = 'D:\PostgreSQL\18\bin\psql.exe'
)
$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$out = Join-Path $root "docs\evidence\phase-3\$((Get-Date).ToString('yyyy-MM-dd'))-$Name"
New-Item -ItemType Directory $out -Force | Out-Null

$roles = @{}
foreach ($line in [IO.File]::ReadAllLines((Join-Path $root '.env.db-roles'))) {
    if ($line -match '^([A-Z_]+)=(.*)$') { $roles[$Matches[1]] = $Matches[2] }
}
function Invoke-Owner([string]$Sql) {
    $env:PGPASSWORD = $roles['OWNER_DB_PASSWORD']
    try { & $Psql -h 127.0.0.1 -U $roles['OWNER_DB_USERNAME'] -d engineering_template -X -A -q -c $Sql }
    finally { Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue }
}

Push-Location $root
try {
    php artisan cache:clear --quiet
    Invoke-Owner 'select pg_stat_statements_reset();' | Out-Null

    $sampler = Start-Job -ArgumentList (Join-Path $out 'resources.csv') -ScriptBlock {
        param($file)
        'time,group,processes,cpu_seconds,working_set_mb' | Set-Content $file
        while ($true) {
            $stamp = (Get-Date).ToString('HH:mm:ss')
            foreach ($group in 'php-cgi', 'php', 'nginx', 'postgres') {
                $p = @(Get-Process -Name $group -ErrorAction SilentlyContinue)
                if ($p.Count) {
                    $cpu = ($p | Measure-Object -Property CPU -Sum).Sum
                    $mem = ($p | Measure-Object -Property WorkingSet64 -Sum).Sum / 1MB
                    "$stamp,$group,$($p.Count),$([math]::Round($cpu, 2)),$([math]::Round($mem, 1))" | Add-Content $file
                }
            }
            Start-Sleep -Seconds 2
        }
    }

    $started = Get-Date
    k6 run --quiet --summary-export (Join-Path $out 'summary.json') `
        -e BASE_URL=$BaseUrl -e VISITOR_VUS=$VisitorVus -e STAFF_VUS=$StaffVus -e HOLD=$Hold `
        tests\load\baseline.js 2>&1 | Tee-Object (Join-Path $out 'k6.log') | Out-Null
    $k6Exit = $LASTEXITCODE
    $finished = Get-Date

    Stop-Job $sampler; Remove-Job $sampler

    Invoke-Owner @'
select round(total_exec_time::numeric, 1) as total_ms, calls,
       round(mean_exec_time::numeric, 3) as mean_ms, rows,
       regexp_replace(left(query, 160), '\s+', ' ', 'g') as query
from pg_stat_statements
where dbid = (select oid from pg_database where datname = 'engineering_template')
order by total_exec_time desc limit 15;
'@ | Set-Content (Join-Path $out 'top-statements.txt')

    @(
        "run: $Name"
        "started: $($started.ToString('s'))  finished: $($finished.ToString('s'))"
        "visitor VUs: $VisitorVus  staff VUs: $StaffVus  hold: $Hold  base: $BaseUrl"
        "k6 exit code: $k6Exit"
        "machine: $((Get-CimInstance Win32_Processor | Select-Object -First 1).Name), $((Get-CimInstance Win32_ComputerSystem).NumberOfLogicalProcessors) logical CPUs, $([math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB, 1)) GB RAM"
        "software: $(php -r 'echo "PHP ", PHP_VERSION;'), $(k6 version | Select-Object -First 1), PostgreSQL $((Invoke-Owner 'show server_version;'))"
        'note: application, database, web server and load generator share this machine'
    ) | Set-Content (Join-Path $out 'run.txt')

    "Saved to $out (k6 exit $k6Exit)"
} finally {
    Pop-Location
}
