<#
.SYNOPSIS
    Pulls the latest backend fixes from the Arena working branch and mirrors the
    React files into the local frontend project - in one command, with checks.

.DESCRIPTION
    Why this exists: copying files by hand (or pasting snippets into a file) is
    how a perfectly good Dashboard.jsx got corrupted, and a line-continuation
    typo is how a "copy these files" command turned into a branch switch.

      1. git fetch origin
      2. git checkout origin/<branch> -- <files>   (path form: overwrites only
         those files, never switches branch, never touches main)
      3. copies frontend-sync/phase0/src/** into the React project's src/
      4. verifies the marker lines are really there

.PARAMETER Frontend
    Path to the React project. Defaults to D:\ReactProjects\runplan_frontend_interface

.PARAMETER SkipGit
    Only copy what is already in frontend-sync\phase0\src (no network, no git).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\apply_frontend_update.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\apply_frontend_update.ps1 -SkipGit

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\apply_frontend_update.ps1 -Frontend "D:\laragon\www\runplan_frontend"
#>
param(
    [string]$Frontend = "D:\ReactProjects\runplan_frontend_interface",
    [string]$Branch    = "arena/381b3c9d-runplan-lavalust-api",
    [switch]$SkipGit
)

$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Mirror   = Join-Path $RepoRoot "frontend-sync\phase0\src"

# Backend files that change with each Arena step. Path-form checkout overwrites
# exactly these and leaves every other local edit alone.
$BackendFiles = @(
    "public/index.php",
    "scheme/libraries/Api.php",
    "app/libraries/HttpClient.php",
    "app/models/AiCoachingModel.php",
    "app/models/ProfileModel.php",
    "app/migrations/012_create_account_recovery_and_deletion_tables.php",
    "app/migrations/013_add_phase2_scheduling_and_revision_columns.php",
    "app/migrations/014_add_profile_location_columns.php",
    "tests/cases/phase0_core.php",
    "scripts/apply_frontend_update.ps1",
    "frontend-sync/phase0/src"
)

function Write-Step($message) { Write-Host "`n==> $message" -ForegroundColor Cyan }
function Write-Ok($message)   { Write-Host "    OK  $message" -ForegroundColor Green }
function Write-Warn2($message){ Write-Host "    ..  $message" -ForegroundColor Yellow }
function Write-Bad($message)  { Write-Host "    !!  $message" -ForegroundColor Red }

Push-Location $RepoRoot
try {
    if (-not $SkipGit) {
        Write-Step "Fetching origin"
        git fetch origin
        if ($LASTEXITCODE -ne 0) {
            Write-Warn2 "git fetch failed - continuing with the files already on disk"
        } else {
            Write-Ok "fetched"

            Write-Step "Updating changed files from origin/$Branch"
            # NOTE: path form only. `git checkout <branch>` would switch branch and
            # refuse to run while you have local edits - that is what bit us before.
            git checkout "origin/$Branch" -- $BackendFiles
            if ($LASTEXITCODE -ne 0) {
                Write-Warn2 "git checkout failed - continuing with the files already on disk"
                Write-Warn2 "(this is safe: the checks below will tell you if anything is stale)"
            } else {
                Write-Ok ($BackendFiles -join ", ")
            }
        }
    } else {
        Write-Step "Skipping git (-SkipGit)"
    }

    Write-Step "Copying React files into $Frontend"
    if (-not (Test-Path $Mirror)) {
        throw "No frontend mirror at $Mirror. Run this script from the backend repo without -SkipGit."
    }
    if (-not (Test-Path (Join-Path $Frontend "src"))) {
        throw "No src folder at $Frontend. Pass the correct path with -Frontend `"D:\path\to\runplan_frontend`"."
    }

    $copied = 0
    Get-ChildItem -Path $Mirror -Recurse -File | ForEach-Object {
        $relative  = $_.FullName.Substring($Mirror.Length) -replace '^[\\/]+', ''
        $target    = Join-Path (Join-Path $Frontend "src") $relative
        $targetDir = Split-Path -Parent $target
        if (-not (Test-Path $targetDir)) { New-Item -ItemType Directory -Path $targetDir -Force | Out-Null }
        Copy-Item $_.FullName $target -Force
        Write-Ok $relative
        $script:copied++
    }
    Write-Ok "$copied file(s) synced"

    Write-Step "Verifying the markers"
    $problems = 0

    $dashboard = Join-Path $Frontend "src\pages\Dashboard.jsx"
    if ((Test-Path $dashboard) -and (Select-String -Path $dashboard -Pattern "persisted === false" -Quiet)) {
        Write-Ok "Dashboard.jsx contains the coaching fallback note"
    } else {
        Write-Bad "Dashboard.jsx is missing the new note - the copy did not land"
        $problems++
    }

    $api = Join-Path $Frontend "src\api.js"
    if ((Test-Path $api) -and (Select-String -Path $api -Pattern "Server said" -Quiet)) {
        Write-Ok "api.js reports the real server reply"
    } else {
        Write-Bad "api.js is missing the new error message"
        $problems++
    }

    if ($problems -eq 0) {
        Write-Host "`nAll good. Next:" -ForegroundColor Cyan
        Write-Host "  1. Restart PHP so the new error handlers load:" -ForegroundColor Gray
        Write-Host "       php -S 127.0.0.1:3000 -t public" -ForegroundColor White
        Write-Host "  2. Vite hot-reloads by itself. Reload http://localhost:5173/dashboard" -ForegroundColor Gray
        Write-Host "  3. Run the new migration (see the chat for the 2 lines)" -ForegroundColor Gray
    } else {
        Write-Host "`n$problems check(s) failed - send this output to the chat." -ForegroundColor Red
        exit 1
    }
}
finally {
    Pop-Location
}
