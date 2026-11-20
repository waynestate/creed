# The framework x PHP test matrix: creates each framework major's official
# skeleton on each PHP line its newest release allows, runs the skeleton's own
# tests, serves it and GETs /, and writes data/framework-php-tests.json and the
# table in docs/guides/framework-php-tests.md. Windows PowerShell 5.1 and
# PowerShell 7 through Docker Desktop; on Linux and macOS use
# scripts/framework-matrix.sh. The cells are listed in
# tools/FrameworkMatrix/frameworks.json.
#
# Usage: scripts\framework-matrix.ps1 [-Php 8.5,8.6] [-Framework laravel-13,slim-4] [-KeepImages]
#
# Every container runs alone and is named creed-matrix-*. Composer downloads go
# through one cache volume. One image per PHP line, creed-matrix-php<line>, is
# built from the official php:<line>-cli image pinned by the digest just pulled
# (php:<line>-rc-cli, marked prerelease, when no release image exists yet), and
# removed at the end unless -KeepImages.
#
# Environment:
#   CREED_MATRIX_COMPOSER_CACHE  Composer cache volume (default stack-composer-cache)
#   CREED_MATRIX_LOCK            a lock file shared with other container jobs on this
#                                computer: created holding this script's pid before
#                                every docker run or build, deleted after (default: none)
#   CREED_MATRIX_MAX_COMMIT      hold before each container while the commit charge is
#                                at or above this percent (default 72; 0 turns it off)
#
# Exit 0 when every requested cell was recorded (whatever its result), 1 when a
# step of the harness itself failed, 2 on a usage error.
param(
    [string[]]$Php = @(),
    [string[]]$Framework = @(),
    [switch]$KeepImages
)
$ErrorActionPreference = "Stop"

Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)
$repo = (Get-Location).Path
$build = Join-Path $repo "build\framework-matrix"
New-Item -ItemType Directory -Force (Join-Path $build "cells"), (Join-Path $build "logs") | Out-Null

$cache = if ($env:CREED_MATRIX_COMPOSER_CACHE) { $env:CREED_MATRIX_COMPOSER_CACHE } else { "stack-composer-cache" }
$lock = $env:CREED_MATRIX_LOCK
$maxCommit = if ($env:CREED_MATRIX_MAX_COMMIT) { [int]$env:CREED_MATRIX_MAX_COMMIT } else { 72 }
$config = Get-Content -Raw -LiteralPath (Join-Path $repo "tools\FrameworkMatrix\frameworks.json") | ConvertFrom-Json
$Php = @($Php | ForEach-Object { $_ -split ',' } | Where-Object { $_ })
$Framework = @($Framework | ForEach-Object { $_ -split ',' } | Where-Object { $_ })
$reportLine = "8.5"
$started = Get-Date

# docker with its output dropped and its exit code kept; Windows PowerShell 5.1 would
# otherwise turn the stderr of a container that is already gone into a terminating error.
function Invoke-Quiet {
    $saved = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & docker @args 2>&1 | Out-Null } finally { $ErrorActionPreference = $saved }
    return ($LASTEXITCODE -eq 0)
}

function Wait-Resources {
    if ($maxCommit -le 0) { return }
    while ($true) {
        $o = Get-CimInstance Win32_OperatingSystem
        $pct = 100 * ($o.TotalVirtualMemorySize - $o.FreeVirtualMemory) / $o.TotalVirtualMemorySize
        if ($pct -lt $maxCommit) { return }
        Write-Host ("framework-matrix: commit charge {0:N0}%, holding" -f $pct)
        Start-Sleep -Seconds 30
    }
}

function Enter-Gate {
    Wait-Resources
    if (-not $lock) { return }
    $dir = Split-Path -Parent $lock
    if ($dir) { New-Item -ItemType Directory -Force $dir | Out-Null }
    $said = $false
    while ($true) {
        try {
            $fs = [System.IO.File]::Open($lock, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write, [System.IO.FileShare]::Read)
            $bytes = [System.Text.Encoding]::ASCII.GetBytes("$PID")
            $fs.Write($bytes, 0, $bytes.Length)
            $fs.Close()
            Wait-Resources
            return
        }
        catch [System.IO.IOException] {
            $holder = $null
            try { $holder = [System.IO.File]::ReadAllText($lock) } catch { $holder = $null }
            if ($holder -match '(\d+)') {
                $holderPid = [int]$Matches[1]
                if (-not (Get-Process -Id $holderPid -ErrorAction SilentlyContinue)) {
                    Write-Host "framework-matrix: removing a lock left by pid $holderPid, which is gone"
                    try { [System.IO.File]::Delete($lock) } catch { }
                    continue
                }
                if (-not $said) { Write-Host "framework-matrix: waiting for the container lock (pid $holderPid)"; $said = $true }
            }
            Start-Sleep -Seconds 5
        }
    }
}

function Exit-Gate {
    if (-not $lock) { return }
    try {
        if ((Test-Path -LiteralPath $lock) -and ([System.IO.File]::ReadAllText($lock).Trim() -eq "$PID")) { [System.IO.File]::Delete($lock) }
    } catch { }
}

# Pulls php:<line>-cli (or the rc tag), builds creed-matrix-php<line> on its digest,
# and returns @{ image = "php:...@sha256:..."; prerelease = $bool } or $null when no image exists.
function Build-LineImage([string]$line) {
    $tags = @(@{ tag = $config.image.Replace('%s', $line); pre = $false }, @{ tag = $config.prerelease_image.Replace('%s', $line); pre = $true })
    foreach ($t in $tags) {
        $null = Invoke-Quiet pull --quiet $t.tag
        if ($LASTEXITCODE -ne 0) { continue }
        $digest = (docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' $t.tag) | Where-Object { $_ -like 'php@sha256:*' } | Select-Object -First 1
        if (-not $digest) { throw "no digest for $($t.tag)" }
        $pinned = "$($t.tag)@$($digest.Substring(4))"
        Enter-Gate
        try {
            docker build --quiet --tag "creed-matrix-php$line" --build-arg "BASE=$pinned" --build-arg "EXTENSIONS=$($config.extensions -join ' ')" `
                --file tools/FrameworkMatrix/Dockerfile tools/FrameworkMatrix | Out-Null
            if ($LASTEXITCODE -ne 0) { throw "docker build failed for PHP $line" }
        }
        finally { Exit-Gate }
        return @{ image = $pinned; prerelease = $t.pre }
    }
    return $null
}

function Invoke-MatrixPhp([string]$name, [string]$line, [string[]]$phpArgs) {
    Enter-Gate
    try {
        docker run --rm --name "creed-matrix-$name" -v "${repo}:/creed" -w /creed "creed-matrix-php$line" php @phpArgs
        if ($LASTEXITCODE -ne 0) { throw "php $($phpArgs -join ' ') failed" }
    }
    finally {
        $null = Invoke-Quiet rm -f "creed-matrix-$name"
        Exit-Gate
    }
}

$images = @{}
$status = 0
try {
    # The plan needs PHP 8.3 or later; the report line's image does it.
    $images[$reportLine] = Build-LineImage $reportLine
    if ($null -eq $images[$reportLine]) { throw "no php:$reportLine image to plan with" }
    Enter-Gate
    try {
        $planLines = docker run --rm --name "creed-matrix-plan" -v "${repo}:/creed" -w /creed "creed-matrix-php$reportLine" php bin/framework-matrix.php plan
        if ($LASTEXITCODE -ne 0) { throw "the plan step failed" }
    }
    finally { $null = Invoke-Quiet rm -f "creed-matrix-plan"; Exit-Gate }

    $cells = @($planLines | ForEach-Object { $p = $_ -split ' '; [pscustomobject]@{ framework = $p[0]; line = $p[1]; run = ($p[2] -eq 'run') } })
    $order = @($config.run_order | Where-Object { $Php.Count -eq 0 -or $Php -contains $_ })
    foreach ($line in $order) {
        $todo = @($cells | Where-Object { $_.line -eq $line -and $_.run -and ($Framework.Count -eq 0 -or $Framework -contains $_.framework) })
        if ($todo.Count -eq 0) { continue }
        if (-not $images.ContainsKey($line)) {
            try { $images[$line] = Build-LineImage $line }
            catch { Write-Host "framework-matrix: $_; the PHP $line cells stay not-run"; $status = 1; continue }
        }
        if ($null -eq $images[$line]) { Write-Host "framework-matrix: no official image for PHP $line; its cells stay no-image"; continue }
        foreach ($cell in $todo) {
            $name = "creed-matrix-$($cell.framework)-$($line -replace '\.', '')"
            Write-Host "framework-matrix: $($cell.framework) on PHP $line"
            Enter-Gate
            try {
                docker run --rm --name $name `
                    -v "${repo}:/creed:ro" -v "${build}:/out" -v "${cache}:/tmp/composer-cache" `
                    -e COMPOSER_CACHE_DIR=/tmp/composer-cache -e "CREED_MATRIX_IMAGE=$($images[$line].image)" `
                    -e "CREED_MATRIX_PRERELEASE=$(if ($images[$line].prerelease) { '1' } else { '0' })" `
                    -w /tmp "creed-matrix-php$line" `
                    php /creed/bin/framework-matrix-cell.php "--framework=$($cell.framework)" "--php=$line" "--out=/out/cells/$($cell.framework)--$line.json" "--logs=/out/logs"
                if ($LASTEXITCODE -ne 0) { Write-Host "framework-matrix: the cell runner itself failed for $($cell.framework) on PHP $line"; $status = 1 }
            }
            finally {
                $null = Invoke-Quiet rm -f $name
                Exit-Gate
            }
        }
        if (-not $KeepImages -and $line -ne $reportLine) { $null = Invoke-Quiet image rm "creed-matrix-php$line" }
    }

    $imageFile = Join-Path $build "images.json"
    $record = [ordered]@{}
    foreach ($k in $images.Keys) { $record[$k] = $images[$k] }
    ($record | ConvertTo-Json -Depth 4) | Set-Content -LiteralPath $imageFile -Encoding ascii
    Invoke-MatrixPhp "report" $reportLine @("bin/framework-matrix.php", "report", "--images=build/framework-matrix/images.json")
}
catch {
    Write-Host "framework-matrix: $_"
    $status = 1
}
finally {
    if (-not $KeepImages) {
        foreach ($line in $config.php_lines) { $null = Invoke-Quiet image rm "creed-matrix-php$line" }
    }
    Exit-Gate
}
Write-Host ("framework-matrix: done in {0:N0} min" -f ((Get-Date) - $started).TotalMinutes)
exit $status
