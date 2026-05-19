$ErrorActionPreference = 'Stop'

Write-Host "Starting Laravel HRIS..." -ForegroundColor Cyan

$backendPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoPath = Split-Path -Parent $backendPath
$faceServicePath = Join-Path $repoPath "face_service"
Set-Location $backendPath

function Get-WorkerCount([string] $name, [int] $default = 1) {
  $value = [Environment]::GetEnvironmentVariable($name)
  if ($value) {
    $count = [int]$value
    if ($count -gt 0) { return $count }
  }
  return $default
}

function Start-QueueWorker([string] $queue, [int] $timeout, [int] $count, [int] $tries = 1) {
  for ($i = 1; $i -le $count; $i++) {
    Start-Process php -ArgumentList "artisan queue:work redis --queue=$queue --timeout=$timeout --sleep=1 --tries=$tries" -WorkingDirectory $backendPath -WindowStyle Hidden | Out-Null
  }
}

function Start-FaceServices([string[]] $ports) {
  if (-not (Test-Path $faceServicePath)) {
    Write-Host "face_service directory not found; skipping Python face services." -ForegroundColor DarkYellow
    return
  }

  $python = [Environment]::GetEnvironmentVariable("FACE_SERVICE_PYTHON")
  if (-not $python) { $python = "python" }

  $urls = @()
  foreach ($port in $ports) {
    $port = $port.Trim()
    if (-not $port) { continue }

    $urls += "http://127.0.0.1:$port"
    Start-Process -FilePath $python -ArgumentList @("-m", "uvicorn", "main:app", "--host", "127.0.0.1", "--port", $port) -WorkingDirectory $faceServicePath -WindowStyle Hidden | Out-Null
  }

  if (-not [Environment]::GetEnvironmentVariable("FACE_VERIFICATION_URLS")) {
    $env:FACE_VERIFICATION_URLS = ($urls -join ",")
  }
}

if ([Environment]::GetEnvironmentVariable("START_FACE_SERVICE") -eq "true") {
  $portsValue = [Environment]::GetEnvironmentVariable("FACE_SERVICE_PORTS")
  if (-not $portsValue) { $portsValue = "5000,5001,5002,5003" }
  $ports = $portsValue -split ","
  Write-Host "Starting Python face services on ports $portsValue" -ForegroundColor Yellow
  Start-FaceServices $ports
}

Write-Host "Starting PHP server on :8000" -ForegroundColor Yellow
Start-Process php -ArgumentList "artisan serve --port=8000" -WorkingDirectory $backendPath -WindowStyle Hidden | Out-Null

Write-Host "Starting Redis queue workers" -ForegroundColor Yellow
Start-QueueWorker "payroll" 300 (Get-WorkerCount "PAYROLL_QUEUE_WORKERS")
Start-QueueWorker "payslip-pdf" 300 (Get-WorkerCount "PAYSLIP_QUEUE_WORKERS")
Start-QueueWorker "face-registration" 180 (Get-WorkerCount "FACE_QUEUE_WORKERS" 4) 2
Start-QueueWorker "default" 120 (Get-WorkerCount "DEFAULT_QUEUE_WORKERS")

Write-Host "Laravel HRIS services started." -ForegroundColor Green
Write-Host "Tip: scale workers with PAYROLL_QUEUE_WORKERS, PAYSLIP_QUEUE_WORKERS, FACE_QUEUE_WORKERS, DEFAULT_QUEUE_WORKERS." -ForegroundColor DarkGray
Write-Host "Tip: set START_FACE_SERVICE=true and FACE_SERVICE_PORTS=5000,5001,5002,5003 for concurrent face recognition." -ForegroundColor DarkGray
