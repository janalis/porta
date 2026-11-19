# Installs the package silently and opens the real application on a project.
# Usage: installed.ps1 <installer> <project folder>
param([string]$Installer, [string]$Project)
$ErrorActionPreference = 'Stop'

$install = Start-Process -FilePath $Installer -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART' -Wait -PassThru
if ($install.ExitCode -ne 0) { throw "the installer exited with $($install.ExitCode)" }

$app = Join-Path $env:LOCALAPPDATA 'Programs\Porta\Porta.exe'
if (-not (Test-Path $app)) { throw "nothing installed at $app" }
$shortcut = Join-Path ([Environment]::GetFolderPath('Programs')) 'Porta.lnk'
if (-not (Test-Path $shortcut)) { throw "no Start menu entry at $shortcut" }

$first = Start-Process -FilePath $app -ArgumentList "`"$Project`"" -PassThru
$deadline = (Get-Date).AddSeconds(90)
do {
    Start-Sleep -Seconds 1
    $first.Refresh()
    if ($first.HasExited) { throw "Porta.exe exited with $($first.ExitCode)" }
} until ($first.MainWindowTitle -like '*Porta*' -or (Get-Date) -gt $deadline)
if ($first.MainWindowTitle -notlike '*Porta*') { throw 'no Porta window appeared' }
Write-Output "smoke: the window is open: $($first.MainWindowTitle)"

# A second launch hands over to the first and leaves.
$second = Start-Process -FilePath $app -ArgumentList "`"$Project`"" -PassThru
if (-not $second.WaitForExit(20000)) { throw 'a second launch stayed running' }
$running = @(Get-Process -Name Porta -ErrorAction SilentlyContinue).Count
if ($running -ne 1) { throw "$running Porta processes are running" }
Write-Output 'smoke: a second launch handed over to the first'

Stop-Process -Id $first.Id -Force
