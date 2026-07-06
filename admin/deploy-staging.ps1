#!/usr/bin/env pwsh
# Build admin and deploy the static SPA to STAGING (staging-admin.eloquentservice.com),
# pointed at the staging API. Mirrors deploy.ps1 but never touches production.
# Usage: ./deploy-staging.ps1

$ErrorActionPreference = "Stop"
$root    = $PSScriptRoot
$server  = "root@64.227.153.90"
$webroot = "/var/www/admin-staging"

Write-Host "==> Building (VITE_API_URL -> staging API)" -ForegroundColor Cyan
Push-Location $root
try {
    # Build-time API base: the staging backend, not production.
    $env:VITE_API_URL = "https://staging-api.eloquentservice.com/api"
    npm run build
    if ($LASTEXITCODE -ne 0) { throw "npm run build failed (exit $LASTEXITCODE)" }
} finally {
    Remove-Item Env:\VITE_API_URL -ErrorAction SilentlyContinue
    Pop-Location
}

$dist = Join-Path $root "dist"
if (-not (Test-Path $dist)) { throw "Build output not found at $dist" }

Write-Host "==> Uploading dist -> $server`:$webroot" -ForegroundColor Cyan
$tar = Join-Path $env:TEMP "admin-staging-dist.tar.gz"
if (Test-Path $tar) { Remove-Item $tar -Force }
tar -czf $tar -C $dist .
if ($LASTEXITCODE -ne 0) { throw "tar failed (exit $LASTEXITCODE)" }
scp -o BatchMode=yes -o ConnectTimeout=15 $tar "$server`:/tmp/admin-staging-dist.tar.gz"
if ($LASTEXITCODE -ne 0) { throw "scp failed" }
ssh -o BatchMode=yes $server "mkdir -p $webroot && rm -rf $webroot/* && tar -xzf /tmp/admin-staging-dist.tar.gz -C $webroot && chown -R www-data:www-data $webroot && rm -f /tmp/admin-staging-dist.tar.gz"
if ($LASTEXITCODE -ne 0) { throw "remote extract failed" }
Remove-Item $tar -Force

Write-Host "==> Verifying" -ForegroundColor Cyan
curl.exe -sI https://staging-admin.eloquentservice.com/ | Select-Object -First 1

Write-Host "==> Done - https://staging-admin.eloquentservice.com" -ForegroundColor Green
