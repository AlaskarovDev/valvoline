#!/usr/bin/env bash
# Builds dist/upload/ (synced to the web root by FTP-Deploy-Action):
#   _deploy.php, _releases/app-<sha>.zip, _releases/vendor-<lockhash>.zip
# The vendor zip is byte-for-byte reproducible (sorted entries, fixed mtimes), so the
# FTP sync state sees it unchanged and skips the upload until composer.lock changes.
# Usage: deploy/package.sh <short-sha> <sha256 of the deploy token>
set -euo pipefail

SHA="$1"
TOKEN_HASH="$2"
[[ "$TOKEN_HASH" =~ ^[a-f0-9]{64}$ ]] || { echo "bad token hash" >&2; exit 1; }
test -f public/build/manifest.json || { echo "run npm run build first" >&2; exit 1; }

rm -rf dist && mkdir -p dist/upload/_releases
OUT=dist/upload/_releases

APP_ZIP="app-${SHA}.zip"
zip -rq "${OUT}/${APP_ZIP}" . \
  -x 'vendor/*' 'node_modules/*' '.git/*' 'tests/*' 'dist/*' 'storage/*' '.env' '.env.*' \
     'database/*.sqlite' '.github/*' 'tools/*' '*.log' 'server.env' 'deploy.json' 'artisan.json' 'public/hot'      '.claude/*' '.mcp.json' 'body.html' 'headers.txt'

LOCK="$(sha1sum composer.lock | cut -c1-16)"
VENDOR_ZIP="vendor-${LOCK}.zip"
find vendor -exec touch -h -d '2000-01-01 00:00:00 UTC' {} +
find vendor -type f | LC_ALL=C sort | TZ=UTC zip -X -q "${OUT}/${VENDOR_ZIP}" -@
sha1sum "${OUT}/${VENDOR_ZIP}"

sed "s/__TOKEN_HASH__/${TOKEN_HASH}/" deploy/agent.php > dist/upload/_deploy.php
grep -q "${TOKEN_HASH}" dist/upload/_deploy.php

echo "${APP_ZIP}" > dist/release.txt
echo "${VENDOR_ZIP}" > dist/vendor.txt
ls -la dist/upload dist/upload/_releases
