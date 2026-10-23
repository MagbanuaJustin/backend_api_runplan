<#
.SYNOPSIS
    Pulls the latest backend fixes from the Arena working branch and mirrors the
    React files into the local frontend project - in one command, with checks.

.DESCRIPTION
    Why this exists: copying files by hand (and pasting snippets into a file) is
    how a perfectly good Dashboard.jsx got corrupted. This script does four
    things and refuses to pretend it worked:

      1. git fetch origin
      2. git checkout origin/arena/381b3c9d-runplan-lavalust-api -- <the files
         that changed>   (never switches branch, never touches main)
      3. copies frontend-sync/phase0/src/** into the React project's src/
      4. verifies the two marker lines are really there

.PARAMETER Frontend
    Path to the React project. Defaults to D:\ReactProjects\runplan_frontend_interface

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\apply_frontend_update.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\apply_frontend_update.ps1 -Frontend "D:\laragon\www\runplan_frontend"
#>
param(
    [string]$Frontend = "D:\ReactProjects\runplan_frontend_interface",
    [string]$Branch    = "arena/381b3c9d-runplan-lavalust-api"
)

$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Mirror   = Join-Path $RepoRoot "frontend-sync\phase0\src"

# The backend files that changed in the fixes for the "unexpected reply" error.
$BackendFiles = @(
    "public/index.php",
    "scheme/libraries/Api.php",
    "app/libraries/HttpClient.php",
    "app/models/AiCoachingModel.php",
    "tests/cases/phase0_core.php",
    "frontend-sync/phase0/src"
)

function Write-Step($message) { Write-Host "`n==> $message" -ForegroundColor Cyan }
function Write-Ok($message)   { Write-Host "    OK  $message" -ForegroundColor Green }
function Write-Bad($message)  { Write-Host "    !!  $message" -ForegroundColor Red }

Push-Location $RepoRoot
try {
    Write-Step "Fetching origin"
    git fetch origin
    if ($LASTEXITCODE -ne 0) { throw "git fetch failed. Is the GitHub connection working?" }
    Write-Ok "fetched"

    Write-Step "Checking out the latest versions of the changed files"
    git checkout "origin/$Branch" -- $BackendFiles
    if ($LASTEXITCODE -ne 0) { throw "git checkout from origin/$Branch failed." }
    Write-Ok ($BackendFiles -join ", ")

    Write-Step "Copying React files into $Frontend"
    if (-not (Test-Path (Join-Path $Frontend "src"))) {
        throw "No src folder at $Frontend. Pass the correct path with -Frontend `"D:\path\to\runplan_frontend`"."
    }

    $copied = 0
    Get-ChildItem -Path $Mirror -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($Mirror.Length) -replace '^[\\/]+', ''
        $target   = Join-Path (Join-Path $Frontend "src") $relative
        $targetDir = Split-Path -Parent $target
        if (-not (Test-Path $targetDir)) { New-Item -ItemType Directory -Path $targetDir -Force | Out-Null }
        Copy-Item $_.FullName $target -Force
        Write-Ok $relative
        $script:copied++
    }
    Write-Ok "$copied file(s) synced"

    Write-Step "Verifying the markers"
    $dashboard = Join-Path $Frontend "src\pages\Dashboard.jsx"
    $api       = Join-Path $Frontend "src\api.js"

    if ((Select-String -Path $dashboard -Pattern "persisted === false" -Quiet)) {
        Write-Ok "Dashboard.jsx contains the coaching fallback note"
    } else {
        Write-Bad "Dashboard.jsx does not contain the new note - the copy did not land"
    }

    if ((Test-Path $api) -and (Select-String -Path $api -Pattern "Server said" -Quiet)) {
        Write-Ok "api.js reports the real server reply"
    } else {
        Write-Bad "api.js is missing the new error message"
    }

    Write-Host "`nDone. Next:" -ForegroundColor Cyan
    Write-Host "  1. Stop the PHP server (Ctrl+C) if it is running, then start it again:" -ForegroundColor Gray
    Write-Host "       php -S 127.0.0.1:3000 -t public" -ForegroundColor White
    Write-Host "  2. Vite hot-reloads on its own. Reload http://localhost:5173/dashboard" -ForegroundColor Gray
    Write-Host "  3. Click 'Generate weekly review'." -ForegroundColor Gray
}
finally {
    Pop-Location
}
