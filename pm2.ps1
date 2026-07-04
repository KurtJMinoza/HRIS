param(
  [Parameter(ValueFromRemainingArguments=$true)]
  $arguments
)

$pm2 = Join-Path $PSScriptRoot "node_modules\pm2\bin\pm2"
$node = "node"
$allArgs = @($pm2) + $arguments

if ($arguments.Count -eq 0) {
  Write-Host "USAGE: .\pm2.ps1 <pm2 command> [args]"
  Write-Host ""
  Write-Host "Examples:"
  Write-Host "  .\pm2.ps1 start ecosystem.config.cjs --update-env"
  Write-Host "  .\pm2.ps1 restart all"
  Write-Host "  .\pm2.ps1 status"
  Write-Host "  .\pm2.ps1 stop all"
  Write-Host "  .\pm2.ps1 logs"
  exit 0
}

# For daemon commands (start, restart) — fully hidden, detach
if ($arguments[0] -in @('start', 'restart')) {
  Start-Process -WindowStyle Hidden -FilePath $node -ArgumentList $allArgs
  Write-Host "PM2 command '$($arguments -join ' ')' launched in hidden window."
}
else {
  # For interactive commands (status, logs, stop, delete, etc.) — run hidden but wait for output
  $psi = New-Object System.Diagnostics.ProcessStartInfo
  $psi.FileName = $node
  $psi.Arguments = $allArgs -join ' '
  $psi.UseShellExecute = $false
  $psi.CreateNoWindow = $true
  $psi.RedirectStandardOutput = $true
  $psi.RedirectStandardError = $true
  $p = [System.Diagnostics.Process]::Start($psi)
  $stdout = $p.StandardOutput.ReadToEnd()
  $stderr = $p.StandardError.ReadToEnd()
  $p.WaitForExit()
  if ($stdout) { Write-Host $stdout }
  if ($stderr) { Write-Host -ForegroundColor Red $stderr }
}
