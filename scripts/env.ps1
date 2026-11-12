# Puts this workspace's toolchain first on PATH for the current PowerShell
# session only: PHP 8.5 from Laravel Herd and the portable Node 24 LTS in
# .tools\node. Nothing machine-wide changes; other projects keep their PHP
# and Node.
#
#   . .\scripts\env.ps1
$php = Join-Path $env:USERPROFILE '.config\herd\bin\php85'
$node = Join-Path $PSScriptRoot '..\.tools\node' | Resolve-Path -ErrorAction SilentlyContinue

if (-not (Test-Path (Join-Path $php 'php.exe'))) { throw "PHP 8.5 not found in $php (install it in Herd > Settings > PHP)" }
if (-not $node) { throw 'Node not found in .tools\node (see docs/development.md)' }

$env:PATH = "$php;$node;" + $env:PATH
# Browsers for the Playwright tests stay in the workspace too.
$env:PLAYWRIGHT_BROWSERS_PATH = Join-Path (Split-Path $PSScriptRoot) '.tools\ms-playwright'
"PHP  " + (php -r 'echo PHP_VERSION;')
"Node " + (node --version)
