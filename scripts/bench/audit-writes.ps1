# Audit-write throughput with 1, 4 and 8 concurrent processes, then a full
# chain verification (ADR-0013). Local development database only.
#
#   .\scripts\bench\audit-writes.ps1 -Writes 250
param([int]$Writes = 250, [int[]]$Workers = @(1, 4, 8))
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$php = (Get-Command php).Source
$script = Join-Path $root 'scripts\bench\audit-writes.php'
$out = Join-Path $root "docs\evidence\phase-3\$((Get-Date).ToString('yyyy-MM-dd'))-audit-writes.txt"
New-Item -ItemType Directory (Split-Path $out) -Force | Out-Null

$lines = @("Audit write benchmark, $(Get-Date -Format s), PHP $(& $php -r 'echo PHP_VERSION;'), $Writes writes per process", '')
foreach ($n in $Workers) {
    # @(): with one process a bare string would be indexed by character.
    $stdout = @(1..$n | ForEach-Object { [IO.Path]::GetTempFileName() })
    $stderr = @(1..$n | ForEach-Object { [IO.Path]::GetTempFileName() })
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $procs = for ($i = 0; $i -lt $n; $i++) {
        Start-Process -FilePath $php -ArgumentList "`"$script`" $Writes w$i" `
            -WorkingDirectory $root -RedirectStandardOutput $stdout[$i] -RedirectStandardError $stderr[$i] -NoNewWindow -PassThru
    }
    $procs | Wait-Process
    $wall = $sw.Elapsed.TotalSeconds
    $errors = $stderr | ForEach-Object { Get-Content $_ -Raw } | Where-Object { $_ }
    if ($errors) { throw "worker failed: $($errors | Select-Object -First 1)" }
    $results = $stdout | ForEach-Object { Get-Content $_ | ConvertFrom-Json }
    $stdout + $stderr | Remove-Item -ErrorAction SilentlyContinue
    $total = $n * $Writes
    $lines += "processes=$n total_writes=$total wall_s=$([math]::Round($wall, 2)) writes_per_s=$([math]::Round($total / $wall, 1)) " +
        "p50_ms=$(($results | Measure-Object p50_ms -Average).Average.ToString('0.00')) " +
        "p95_ms_worst=$(($results | Measure-Object p95_ms -Maximum).Maximum) max_ms=$(($results | Measure-Object max_ms -Maximum).Maximum)"
}

Push-Location $root
try { $verify = & $php artisan platform:audit-verify 2>&1 | Select-Object -Last 1 } finally { Pop-Location }
$lines += '', "chain after the runs: $verify"
$lines | Set-Content $out
$lines
