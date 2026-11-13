# One local baseline run (docs/operations/performance-testing.md): clears
# rate-limit and query statistics, samples CPU and memory of PHP, Nginx and
# PostgreSQL every 2 s while k6 runs, then saves k6's summary, the resource
# samples and the top statements from pg_stat_statements.
#
#   .\scripts\perf\run-baseline.ps1 -Name normal-50 -VisitorVus 30 -StaffVus 20 -Hold 2m
#   .\scripts\perf\run-baseline.ps1 -Script requests -Phase phase-9 -Name requests-25 -StaffVus 20 -ApproverVus 5
#
# -Script picks tests\load\<script>.js (baseline: visitors and staff;
# requests: requesters and approvers, ADR-0033); -Phase the evidence folder.
#
# Local only: k6 refuses non-local hosts, and statistics are read with the
# template's own owner role.
param(
    [Parameter(Mandatory)][ValidatePattern('^[a-z0-9-]+$')][string]$Name,
    [int]$VisitorVus = 30,
    [int]$StaffVus = 20,
    [string]$Hold = '2m',
    [ValidateSet('baseline', 'requests')][string]$Script = 'baseline',
    [ValidatePattern('^phase-[0-9]+$')][string]$Phase = 'phase-3',
    [int]$ApproverVus = 5,
    [string]$BaseUrl = 'http://engineering-template.test',
    [string]$Psql = 'D:\PostgreSQL\18\bin\psql.exe'
)
$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$out = Join-Path $root "docs\evidence\$Phase\$((Get-Date).ToString('yyyy-MM-dd'))-$Name"
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
        # cpu_seconds: CPU time so far of the group's live processes. Short-
        # lived processes (PostgreSQL backends) exit between samples, so their
        # time is undercounted; the "machine" row is total CPU in percent.
        'time,group,processes,cpu_seconds,working_set_mb' | Set-Content $file
        while ($true) {
            $stamp = (Get-Date).ToString('HH:mm:ss')
            $total = (Get-CimInstance Win32_PerfFormattedData_PerfOS_Processor -Filter "Name='_Total'").PercentProcessorTime
            "$stamp,machine,0,$total,0" | Add-Content $file
            foreach ($group in 'php-cgi', 'php', 'nginx', 'postgres', 'k6') {
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

    # k6 writes its progress to stderr; in Windows PowerShell that must not
    # count as a failure of this script.
    $ErrorActionPreference = 'Continue'

    # Every page once, one at a time, so templates compile before the load:
    # on Windows two processes compiling the same view can collide on the
    # final rename ("Access is denied"), which Linux does atomically.
    k6 run --quiet --vus 1 --iterations 1 -e BASE_URL=$BaseUrl tests\load\$Script.js 2>&1 | Out-Null
    php artisan cache:clear --quiet
    Invoke-Owner 'select pg_stat_statements_reset();' | Out-Null

    $started = Get-Date
    k6 run --quiet --summary-export (Join-Path $out 'summary.json') `
        -e BASE_URL=$BaseUrl -e VISITOR_VUS=$VisitorVus -e STAFF_VUS=$StaffVus -e APPROVER_VUS=$ApproverVus -e HOLD=$Hold `
        tests\load\$Script.js 2>&1 | Tee-Object (Join-Path $out 'k6.log') | Out-Null
    $k6Exit = $LASTEXITCODE
    $finished = Get-Date
    $ErrorActionPreference = 'Stop'

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
        "script: $Script.js  visitor VUs: $VisitorVus  staff VUs: $StaffVus  approver VUs: $ApproverVus  hold: $Hold  base: $BaseUrl"
        "k6 exit code: $k6Exit"
        "machine: $((Get-CimInstance Win32_Processor | Select-Object -First 1).Name), $((Get-CimInstance Win32_ComputerSystem).NumberOfLogicalProcessors) logical CPUs, $([math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB, 1)) GB RAM"
        "software: PHP $(php -r 'echo PHP_VERSION;'), $(k6 version | Select-Object -First 1), PostgreSQL $((Invoke-Owner 'show server_version;' | Select-Object -Skip 1 -First 1))"
        'note: application, database, web server and load generator share this machine;'
        'the postgres process group includes every local database on this server, not only the template'
    ) | Set-Content (Join-Path $out 'run.txt')

    "Saved to $out (k6 exit $k6Exit)"
} finally {
    Pop-Location
}
