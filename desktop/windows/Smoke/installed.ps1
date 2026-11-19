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
# The build for this machine: Windows on Arm gets the arm64 window, not x64 under emulation.
$bytes = [IO.File]::ReadAllBytes($app)
$machine = [BitConverter]::ToUInt16($bytes, [BitConverter]::ToInt32($bytes, 0x3C) + 4)
$expected = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64' -or $env:PROCESSOR_ARCHITEW6432 -eq 'ARM64') { 0xAA64 } else { 0x8664 }
if ($machine -ne $expected) { throw ('Porta.exe is built for machine 0x{0:X4}, this one is 0x{1:X4}' -f $machine, $expected) }
Write-Output ('smoke: Porta.exe is built for this machine (0x{0:X4})' -f $machine)

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

# Font… is in the window's system menu: this window has no menu bar of its own to hold it.
Add-Type -Namespace Smoke -Name SystemMenu -MemberDefinition @'
[DllImport("user32.dll")] public static extern IntPtr GetSystemMenu(IntPtr window, bool revert);
[DllImport("user32.dll")] public static extern int GetMenuItemCount(IntPtr menu);
[DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern int GetMenuString(IntPtr menu, uint item, System.Text.StringBuilder text, int length, uint flags);
'@
$menu = [Smoke.SystemMenu]::GetSystemMenu($running[0].MainWindowHandle, $false)
$labels = foreach ($i in 0..([Smoke.SystemMenu]::GetMenuItemCount($menu) - 1)) {
    $text = New-Object System.Text.StringBuilder 128
    [void][Smoke.SystemMenu]::GetMenuString($menu, $i, $text, 128, 0x400)
    $text.ToString()
}
if ($running[0].MainWindowTitle -ne 'Porta needs WSL') {
    if ($labels -notcontains 'Font…') { throw "no Font… in the system menu: $($labels -join ', ')" }
    Write-Output 'smoke: the system menu offers Font…'
} else {
    # Without WSL, the window says what is missing and offers to install it.
    Add-Type -AssemblyName UIAutomationClient, UIAutomationTypes
    $needs = [System.Windows.Automation.AutomationElement]::FromHandle($running[0].MainWindowHandle)
    $install = $needs.FindFirst([System.Windows.Automation.TreeScope]::Descendants, (New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, 'Install WSL')))
    if (-not $install) { throw 'the window for a missing WSL offers no Install WSL button' }
    Write-Output 'smoke: without WSL, the window offers to install it'
}

# Screen readers: the terminal control answers UI Automation with a text provider of its own.
if ($running[0].MainWindowTitle -ne 'Porta needs WSL') {
    Add-Type -AssemblyName UIAutomationClient, UIAutomationTypes
    $window = [System.Windows.Automation.AutomationElement]::FromHandle($running[0].MainWindowHandle)
    $condition = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::IsTextPatternAvailableProperty, $true)
    $deadline = (Get-Date).AddSeconds(30)
    do {
        Start-Sleep -Seconds 1
        $text = $window.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $condition)
        $read = if ($text) { $text.GetCurrentPattern([System.Windows.Automation.TextPattern]::Pattern).DocumentRange.GetText(2000).Trim() } else { '' }
    } until ($read.Length -gt 0 -or (Get-Date) -gt $deadline)
    if ($read.Length -eq 0) { throw 'no text reaches UI Automation from the terminal' }
    Write-Output "smoke: a screen reader reads the terminal: $($read.Substring(0, [Math]::Min(60, $read.Length)) -replace '\s+', ' ')"
}

# A launch on a project hands it to the Porta already running, and leaves.
$second = Start-Process -FilePath $app -ArgumentList "`"$Project`"" -PassThru
if (-not $second.WaitForExit(20000)) { throw 'a second launch stayed running' }
$count = @(Get-Process -Name Porta -ErrorAction SilentlyContinue).Count
if ($count -ne 1) { throw "$count Porta processes are running" }
Write-Output 'smoke: a second launch handed over to the first'

Get-Process -Name Porta | Stop-Process -Force
