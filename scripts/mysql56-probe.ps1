# Ask a real MySQL 5.6.51 whether handbook/databases.md tells the truth about
# it: starts the server in Docker, runs bin/mysql56-probe.php against it, and
# removes the server again. Windows PowerShell 5.1 and PowerShell 7; on Linux
# and macOS use scripts/mysql56-probe.sh.
#
# Usage: scripts\mysql56-probe.ps1 [-Server 5.6|8.4]
# 8.4 runs the same probes on MySQL 8.4.13 and judges each against its 8.4
# answer, which is how the chapter's "Moving to 8.4" section is proven.
#
# The images are the ones Preflight 0.8.0's mysql56-migrate component runs in
# CI, digests included: mysql:5.6.51 (the pin Squire runs locally; amd64 only)
# or mysql:8.4.13 (multi-arch), and php:8.3-cli-trixie, which gets pdo_mysql
# installed on the spot. Set CREED_PROBE_PHP_IMAGE to use a PHP image that
# already has pdo_mysql. Nothing is installed with Composer, and the repo is
# mounted read-only.
#
# Exit 0 when every probe agreed, 1 when one did not, 2 when the server never
# came up or a tool failed.
param(
    [ValidateSet('5.6', '8.4')]
    [string]$Server = '5.6'
)
$ErrorActionPreference = "Stop"

Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)

$mysqlImage = if ($Server -eq '8.4') {
    "mysql:8.4.13@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902"
} else {
    "mysql:5.6.51@sha256:20575ecebe6216036d25dab5903808211f1e9ba63dc7825ac20cb975e34cfcae"
}
$phpImage = if ($env:CREED_PROBE_PHP_IMAGE) { $env:CREED_PROBE_PHP_IMAGE } else { "php:8.3-cli-trixie@sha256:628102249d93494233b54490b785f12db9cd60f4dc89416dfca15973f2125f10" }
# A throwaway password for a server that lives for one run and listens only on its own network.
$password = "creed-mysql56-probe"
$name = "creed-mysql56-probe-$PID"

# The inner code carries no quotes and no backslashes: PowerShell 5.1 mangles
# both when it builds docker's command line. sh fails on CRLF line endings, so
# strip the carriage returns this CRLF-checked-out file gives the string.
$inner = 'php -m | grep -qx pdo_mysql || docker-php-ext-install pdo_mysql >/dev/null
php bin/mysql56-probe.php --wait=120'
$inner = $inner -replace "`r", ''

$status = 2
try {
    docker network create $name | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "docker network create failed" }
    docker run -d --name $name --network $name --network-alias mysql `
        -e "MYSQL_ROOT_PASSWORD=$password" -e MYSQL_DATABASE=app -e MYSQL_USER=app -e "MYSQL_PASSWORD=$password" `
        $mysqlImage | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "the MySQL $Server container did not start" }

    docker run --rm --network $name `
        -v "${PWD}:/work:ro" -w /work `
        -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=app -e DB_USERNAME=app -e "DB_PASSWORD=$password" `
        --entrypoint sh $phpImage -c $inner
    $status = $LASTEXITCODE
}
finally {
    docker rm -f $name 2>$null | Out-Null
    docker network rm $name 2>$null | Out-Null
}

exit $status
