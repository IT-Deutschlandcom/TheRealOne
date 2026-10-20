param(
    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string]$PhpPath,

    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Container })]
    [string]$ProjectPath,

    [string]$TaskName = 'TheRealOne-MailWorker'
)

$ErrorActionPreference = 'Stop'
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
$ProjectPath = (Resolve-Path -LiteralPath $ProjectPath).Path
$RunnerPath = Join-Path $ProjectPath 'scripts\run-worker.ps1'
if (-not (Test-Path -LiteralPath $RunnerPath -PathType Leaf)) {
    throw "Worker runner nicht gefunden: $RunnerPath"
}

$PowerShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$Arguments = "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$RunnerPath`" -PhpPath `"$PhpPath`" -ProjectPath `"$ProjectPath`""
$Action = New-ScheduledTaskAction -Execute $PowerShell -Argument $Arguments -WorkingDirectory $ProjectPath
$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$Settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 2) `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable
$Principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger `
    -Settings $Settings -Principal $Principal -Description 'Polls the authorized campaign email queue once per minute.' -Force | Out-Null
Write-Host "Geplante Aufgabe '$TaskName' wurde registriert. SMTP-Intervalle werden pro Kampagne in der Datenbank eingehalten."
