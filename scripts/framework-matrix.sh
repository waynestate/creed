#!/bin/sh
# The framework x PHP test matrix: creates each framework major's official
# skeleton on each PHP line its newest release allows, runs the skeleton's own
# tests, serves it and GETs /, and writes data/framework-php-tests.json and the
# table in docs/guides/framework-php-tests.md. Linux and macOS (bash 3.2 and
# BSD tools are enough); on Windows use scripts/framework-matrix.ps1, or this
# script from Git Bash. The cells are listed in tools/FrameworkMatrix/frameworks.json.
#
# Usage: scripts/framework-matrix.sh [--php 8.5,8.6] [--framework laravel-13,slim-4] [--keep-images]
#
# Every container runs alone and is named creed-matrix-*. Composer downloads go
# through one cache volume. One image per PHP line, creed-matrix-php<line>, is
# built from the official php:<line>-cli image pinned by the digest just pulled
# (php:<line>-rc-cli, marked prerelease, when no release image exists yet), and
# removed at the end unless --keep-images.
#
# Environment:
#   CREED_MATRIX_COMPOSER_CACHE  Composer cache volume (default stack-composer-cache)
#   CREED_MATRIX_LOCK            a lock file shared with other container jobs on this
#                                computer: created holding this script's pid before
#                                every docker run or build, deleted after (default: none)
#
# Exit 0 when every requested cell was recorded (whatever its result), 1 when a
# step of the harness itself failed, 2 on a usage error.
set -eu

cd "$(dirname "$0")/.."

if [ -n "${MSYSTEM:-}" ]; then
  export MSYS_NO_PATHCONV=1
  REPO="$(pwd -W)"
  # Other jobs on a Windows computer write Windows pids into the lock; MSYS_NO_PATHCONV
  # also keeps tasklist's /NH and /FI as they are.
  MYPID="$(cat /proc/$$/winpid 2>/dev/null || echo $$)"
else
  REPO="$PWD"
  MYPID="$$"
fi

PHP_FILTER=""
FW_FILTER=""
KEEP=0
while [ $# -gt 0 ]; do
  case "$1" in
    --php) PHP_FILTER=",$2,"; shift 2 ;;
    --framework) FW_FILTER=",$2,"; shift 2 ;;
    --keep-images) KEEP=1; shift ;;
    *) echo "usage: scripts/framework-matrix.sh [--php 8.5,8.6] [--framework laravel-13,slim-4] [--keep-images]" >&2; exit 2 ;;
  esac
done

CACHE="${CREED_MATRIX_COMPOSER_CACHE:-stack-composer-cache}"
LOCK="${CREED_MATRIX_LOCK:-}"
BUILD="build/framework-matrix"
REPORT_LINE="8.5"
CONFIG="tools/FrameworkMatrix/frameworks.json"
STARTED="$(date +%s)"
mkdir -p "$BUILD/cells" "$BUILD/logs"

# The config read without jq: the lists and patterns this script needs.
json_list() { tr -d '\n' < "$CONFIG" | sed -n "s/.*\"$1\": *\[\([^]]*\)\].*/\1/p" | tr -d '" ' | tr ',' ' '; }
json_str() { tr -d '\n' < "$CONFIG" | sed -n "s/.*\"$1\": *\"\([^\"]*\)\".*/\1/p"; }
LINES="$(json_list php_lines)"
ORDER="$(json_list run_order)"
EXTENSIONS="$(json_list extensions)"
IMAGE_PATTERN="$(json_str image)"
RC_PATTERN="$(json_str prerelease_image)"

alive() {
  if [ -n "${MSYSTEM:-}" ]; then
    tasklist /NH /FI "PID eq $1" 2>/dev/null | grep -q " $1 "
  else
    kill -0 "$1" 2>/dev/null
  fi
}

enter_gate() {
  [ -n "$LOCK" ] || return 0
  mkdir -p "$(dirname "$LOCK")"
  said=0
  while :; do
    if ( set -C; echo "$MYPID" > "$LOCK" ) 2>/dev/null; then
      return 0
    fi
    holder="$(sed -n 's/[^0-9]*\([0-9][0-9]*\).*/\1/p' "$LOCK" 2>/dev/null | head -n 1)"
    if [ -n "$holder" ] && ! alive "$holder"; then
      echo "framework-matrix: removing a lock left by pid $holder, which is gone"
      rm -f "$LOCK"
      continue
    fi
    if [ "$said" = 0 ]; then echo "framework-matrix: waiting for the container lock (pid ${holder:-unknown})"; said=1; fi
    sleep 5
  done
}

exit_gate() {
  [ -n "$LOCK" ] || return 0
  if [ -f "$LOCK" ] && [ "$(tr -d ' \r\n' < "$LOCK")" = "$MYPID" ]; then rm -f "$LOCK"; fi
}

cleanup() {
  docker rm -f creed-matrix-plan creed-matrix-report >/dev/null 2>&1 || true
  if [ "$KEEP" = 0 ]; then
    for l in $LINES; do docker image rm "creed-matrix-php$l" >/dev/null 2>&1 || true; done
  fi
  exit_gate
}
trap cleanup EXIT INT TERM

IMAGES_JSON=""
add_image() { # line, json value
  IMAGES_JSON="${IMAGES_JSON:+$IMAGES_JSON,}
    \"$1\": $2"
}

# Pulls php:<line>-cli (or the rc tag), builds creed-matrix-php<line> on its digest,
# and sets PINNED and PRE; returns 1 when no image exists for the line.
build_line_image() {
  line="$1"
  for pre in 0 1; do
    if [ "$pre" = 0 ]; then tag="$(printf '%s' "$IMAGE_PATTERN" | sed "s/%s/$line/")"; else tag="$(printf '%s' "$RC_PATTERN" | sed "s/%s/$line/")"; fi
    docker pull --quiet "$tag" >/dev/null 2>&1 || continue
    digest="$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$tag" | grep '^php@sha256:' | head -n 1)"
    [ -n "$digest" ] || { echo "framework-matrix: no digest for $tag" >&2; exit 1; }
    PINNED="$tag@${digest#php@}"
    PRE="$pre"
    enter_gate
    if ! docker build --quiet --tag "creed-matrix-php$line" --build-arg "BASE=$PINNED" --build-arg "EXTENSIONS=$EXTENSIONS" \
      --file tools/FrameworkMatrix/Dockerfile tools/FrameworkMatrix >/dev/null; then
      exit_gate
      echo "framework-matrix: docker build failed for PHP $line; its cells stay not-run" >&2
      BUILD_FAILED=1
      return 1
    fi
    exit_gate
    if [ "$pre" = 1 ]; then add_image "$line" "{\"image\": \"$PINNED\", \"prerelease\": true}"; else add_image "$line" "{\"image\": \"$PINNED\", \"prerelease\": false}"; fi
    return 0
  done
  add_image "$line" "null"
  return 1
}

# The plan needs PHP 8.3 or later; the report line's image does it.
build_line_image "$REPORT_LINE" || { echo "framework-matrix: no php:$REPORT_LINE image to plan with" >&2; exit 1; }
BUILT=" $REPORT_LINE "
PINNED_REPORT="$PINNED"
PRE_REPORT="$PRE"
enter_gate
PLAN="$(docker run --rm --name creed-matrix-plan -v "$REPO:/creed" -w /creed "creed-matrix-php$REPORT_LINE" php bin/framework-matrix.php plan)" || { exit_gate; echo "framework-matrix: the plan step failed" >&2; exit 1; }
exit_gate

status=0
for line in $ORDER; do
  case "$PHP_FILTER" in ""|*",$line,"*) ;; *) continue ;; esac
  todo="$(printf '%s\n' "$PLAN" | awk -v l="$line" '$2 == l && $3 == "run" { print $1 }')"
  if [ -n "$FW_FILTER" ]; then
    kept=""
    for fw in $todo; do case "$FW_FILTER" in *",$fw,"*) kept="$kept $fw" ;; esac; done
    todo="$kept"
  fi
  [ -n "$(printf '%s' "$todo" | tr -d ' \n')" ] || continue
  if [ "$line" = "$REPORT_LINE" ]; then
    PINNED="$PINNED_REPORT"; PRE="$PRE_REPORT"
  else
    case "$BUILT" in
      *" $line "*) ;;
      *) BUILD_FAILED=0
         if ! build_line_image "$line"; then
           if [ "$BUILD_FAILED" = 1 ]; then status=1; else echo "framework-matrix: no official image for PHP $line; its cells stay no-image"; fi
           continue
         fi
         BUILT="$BUILT $line " ;;
    esac
  fi
  for fw in $todo; do
    name="creed-matrix-$fw-$(printf '%s' "$line" | tr -d .)"
    echo "framework-matrix: $fw on PHP $line"
    enter_gate
    if ! docker run --rm --name "$name" \
      -v "$REPO:/creed:ro" -v "$REPO/$BUILD:/out" -v "$CACHE:/tmp/composer-cache" \
      -e COMPOSER_CACHE_DIR=/tmp/composer-cache -e "CREED_MATRIX_IMAGE=$PINNED" -e "CREED_MATRIX_PRERELEASE=$PRE" \
      -w /tmp "creed-matrix-php$line" \
      php /creed/bin/framework-matrix-cell.php "--framework=$fw" "--php=$line" "--out=/out/cells/$fw--$line.json" "--logs=/out/logs"; then
      echo "framework-matrix: the cell runner itself failed for $fw on PHP $line"
      status=1
    fi
    docker rm -f "$name" >/dev/null 2>&1 || true
    exit_gate
  done
  if [ "$KEEP" = 0 ] && [ "$line" != "$REPORT_LINE" ]; then docker image rm "creed-matrix-php$line" >/dev/null 2>&1 || true; fi
done

printf '{%s\n}\n' "$IMAGES_JSON" > "$BUILD/images.json"
enter_gate
docker run --rm --name creed-matrix-report -v "$REPO:/creed" -w /creed "creed-matrix-php$REPORT_LINE" \
  php bin/framework-matrix.php report --images=build/framework-matrix/images.json || status=1
exit_gate

echo "framework-matrix: done in $(( ($(date +%s) - STARTED) / 60 )) min"
exit "$status"
