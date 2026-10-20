param(
    [Parameter(Mandatory = $true)]
    [string]$PhpPath,

    [Parameter(Mandatory = $true)]
    [string]$ProjectPath
)

$ErrorActionPreference = 'Stop'
$LogDirectory = Join-Path $ProjectPath 'storage\logs'
New-Item -ItemType Directory -Path $LogDirectory -Force | Out-Null
$LogFile = Join-Path $LogDirectory ("worker-{0}.log" -f (Get-Date -Format 'yyyy-MM-dd'))
if ((Test-Path -LiteralPath $LogFile) -and (Get-Item -LiteralPath $LogFile).Length -gt 5MB) {
    $Archive = "$LogFile.1"
    Remove-Item -LiteralPath $Archive -Force -ErrorAction SilentlyContinue
    Move-Item -LiteralPath $LogFile -Destination $Archive
}
$Stdout = Join-Path $LogDirectory 'worker.stdout.tmp'
$Stderr = Join-Path $LogDirectory 'worker.stderr.tmp'

try {
    $Process = Start-Process -FilePath $PhpPath -ArgumentList 'worker.php' `
        -WorkingDirectory $ProjectPath -PassThru -Wait -NoNewWindow `
        -RedirectStandardOutput $Stdout -RedirectStandardError $Stderr
    $Timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    if (Test-Path -LiteralPath $Stdout) {
        Get-Content -LiteralPath $Stdout | ForEach-Object { Add-Content -LiteralPath $LogFile -Value "[$Timestamp] $_" }
    }
    if (Test-Path -LiteralPath $Stderr) {
        Get-Content -LiteralPath $Stderr | ForEach-Object { Add-Content -LiteralPath $LogFile -Value "[$Timestamp] ERROR $_" }
    }
    Add-Content -LiteralPath $LogFile -Value "[$Timestamp] ExitCode=$($Process.ExitCode)"
    exit $Process.ExitCode
}
finally {
    Remove-Item -LiteralPath $Stdout, $Stderr -Force -ErrorAction SilentlyContinue
}
