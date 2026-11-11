#!/bin/sh
# Ask a real MySQL 5.6.51 whether handbook/databases.md tells the truth about
# it: starts the server in Docker, runs bin/mysql56-probe.php against it, and
# removes the server again. Linux and macOS; on Windows use
# scripts/mysql56-probe.ps1, or this script from Git Bash.
#
# Usage: scripts/mysql56-probe.sh [5.6|8.4]
# 8.4 runs the same probes on MySQL 8.4.13 and judges each against its 8.4
# answer, which is how the chapter's "Moving to 8.4" section is proven.
#
# The images are the ones Preflight 0.8.0's mysql56-migrate component runs in
# CI, digests included: mysql:5.6.51 (the pin Squire runs locally; amd64 only,
# so Apple Silicon runs it under emulation) or mysql:8.4.13 (multi-arch), and
# php:8.3-cli-trixie, which gets pdo_mysql installed on the spot. Set
# CREED_PROBE_PHP_IMAGE to use a PHP image that already has pdo_mysql. Nothing
# is installed with Composer, and the repo is mounted read-only.
#
# Exit 0 when every probe agreed, 1 when one did not, 2 when the server never
# came up or a tool failed.
set -eu

cd "$(dirname "$0")/.."

if [ -n "${MSYSTEM:-}" ]; then
  export MSYS_NO_PATHCONV=1
  REPO="$(pwd -W)"
else
  REPO="$PWD"
fi

case "${1:-5.6}" in
  5.6) MYSQL_IMAGE="mysql:5.6.51@sha256:20575ecebe6216036d25dab5903808211f1e9ba63dc7825ac20cb975e34cfcae" ;;
  8.4) MYSQL_IMAGE="mysql:8.4.13@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902" ;;
  *) echo "usage: scripts/mysql56-probe.sh [5.6|8.4]" >&2; exit 2 ;;
esac
PHP_IMAGE="${CREED_PROBE_PHP_IMAGE:-php:8.3-cli-trixie@sha256:628102249d93494233b54490b785f12db9cd60f4dc89416dfca15973f2125f10}"
# A throwaway password for a server that lives for one run and listens only on its own network.
PASSWORD="creed-mysql56-probe"
NAME="creed-mysql56-probe-$$"

cleanup() {
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  docker network rm "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

docker network create "$NAME" >/dev/null
docker run -d --name "$NAME" --network "$NAME" --network-alias mysql \
  -e "MYSQL_ROOT_PASSWORD=$PASSWORD" -e MYSQL_DATABASE=app -e MYSQL_USER=app -e "MYSQL_PASSWORD=$PASSWORD" \
  "$MYSQL_IMAGE" >/dev/null

# The inner code carries no quotes and no backslashes, like the .ps1 twin's copy of it.
INNER='php -m | grep -qx pdo_mysql || docker-php-ext-install pdo_mysql >/dev/null
php bin/mysql56-probe.php --wait=120'

set +e
docker run --rm --network "$NAME" \
  -v "${REPO}:/work:ro" -w /work \
  -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=app -e DB_USERNAME=app -e "DB_PASSWORD=$PASSWORD" \
  --entrypoint sh "$PHP_IMAGE" -c "$INNER"
status=$?
set -e
exit "$status"
