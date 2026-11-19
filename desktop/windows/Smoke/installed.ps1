# Installs the package silently, as an update from Porta does, and checks the real application.
# Usage: installed.ps1 <installer> <project folder>
param([string]$Installer, [string]$Project)
$ErrorActionPreference = 'Stop'

# The installer alone: -Wait would also wait for the Porta it opens when it is done.
$install = Start-Process -FilePath $Installer -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART' -PassThru
$install.WaitForExit()
if ($install.ExitCode -ne 0) { throw "the installer exited with $($install.ExitCode)" }

$app = Join-Path $env:LOCALAPPDATA 'Programs\Porta\Porta.exe'
if (-not (Test-Path $app)) { throw "nothing installed at $app" }
$shortcut = Join-Path ([Environment]::GetFolderPath('Programs')) 'Porta.lnk'
if (-not (Test-Path $shortcut)) { throw "no Start menu entry at $shortcut" }

# A quiet install opens Porta again, as an update needs.
$deadline = (Get-Date).AddSeconds(90)
do {
    Start-Sleep -Seconds 1
    $running = @(Get-Process -Name Porta -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowTitle -like '*Porta*' })
} until ($running.Count -gt 0 -or (Get-Date) -gt $deadline)
if ($running.Count -eq 0) { throw 'the installer did not open Porta again' }
Write-Output "smoke: the installer opened Porta: $($running[0].MainWindowTitle)"

# A launch on a project hands it to the Porta already running, and leaves.
$second = Start-Process -FilePath $app -ArgumentList "`"$Project`"" -PassThru
if (-not $second.WaitForExit(20000)) { throw 'a second launch stayed running' }
$count = @(Get-Process -Name Porta -ErrorAction SilentlyContinue).Count
if ($count -ne 1) { throw "$count Porta processes are running" }
Write-Output 'smoke: a second launch handed over to the first'

Get-Process -Name Porta | Stop-Process -Force
