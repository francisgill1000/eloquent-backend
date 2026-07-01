#!/usr/bin/env pwsh
# Build admin and deploy the static SPA to admin.eloquentservice.com.
# Mirrors the eloquent-bookings static-SPA serving model on the shared droplet.
# Usage: ./deploy.ps1

$ErrorActionPreference = "Stop"
$root   = $PSScriptRoot
$server = "root@64.227.153.90"
$webroot = "/var/www/admin"

Write-Host "==> Building" -ForegroundColor Cyan
Push-Location $root
try {
    npm run build
    if ($LASTEXITCODE -ne 0) { throw "npm run build failed (exit $LASTEXITCODE)" }
} finally {
    Pop-Location
}

$dist = Join-Path $root "dist"
if (-not (Test-Path $dist)) { throw "Build output not found at $dist" }

Write-Host "==> Uploading dist -> $server`:$webroot" -ForegroundColor Cyan
# Upload as a single tarball, not per-file scp. Per-file `scp -r` has been seen
# to hang mid-transfer; because the remote `rm -rf` runs first, a hung upload
# would leave the webroot empty and nginx looping on try_files -> HTTP 500.
# A one-shot tarball avoids that failure window: clear + extract happen together.
$tar = Join-Path $env:TEMP "admin-dist.tar.gz"
if (Test-Path $tar) { Remove-Item $tar -Force }
tar -czf $tar -C $dist .
if ($LASTEXITCODE -ne 0) { throw "tar failed (exit $LASTEXITCODE)" }
scp -o BatchMode=yes -o ConnectTimeout=15 $tar "$server`:/tmp/admin-dist.tar.gz"
if ($LASTEXITCODE -ne 0) { throw "scp failed" }
ssh -o BatchMode=yes $server "mkdir -p $webroot && rm -rf $webroot/* && tar -xzf /tmp/admin-dist.tar.gz -C $webroot && chown -R www-data:www-data $webroot && rm -f /tmp/admin-dist.tar.gz"
if ($LASTEXITCODE -ne 0) { throw "remote extract failed" }
Remove-Item $tar -Force

Write-Host "==> Verifying" -ForegroundColor Cyan
curl.exe -sI https://admin.eloquentservice.com/ | Select-Object -First 1

Write-Host "==> Done - https://admin.eloquentservice.com" -ForegroundColor Green
