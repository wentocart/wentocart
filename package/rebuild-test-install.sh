#!/usr/bin/env bash
# Local loop: build merchant zip from this git clone, extract to a clean test folder.
#
# Usage:
#   ./package/rebuild-test-install.sh
#   WENTO_TEST_DIR=~/www/wentocart-install ./package/rebuild-test-install.sh
#
# Then in another terminal:
#   cd "$WENTO_TEST_DIR" && ./install.sh
#   # open http://127.0.0.1:8099/
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TEST_DIR="${WENTO_TEST_DIR:-$HOME/www/wentocart-install}"
ZIP_OUT="$ROOT/dist/wentocart.zip"

echo "==> Source (git clone): $ROOT"
echo "==> Test extract dir:   $TEST_DIR"

# Stop a leftover helper so the next ./install.sh can bind :8099.
if pgrep -f 'php -S 127.0.0.1:8099' >/dev/null 2>&1; then
  echo "==> Stopping old PHP helper on :8099"
  pkill -f 'php -S 127.0.0.1:8099' || true
  sleep 1
fi

echo "==> Building zip…"
"$ROOT/package/build-install-zip.sh"

if [[ ! -f "$ZIP_OUT" ]]; then
  echo "Zip missing: $ZIP_OUT" >&2
  exit 1
fi

echo "==> Fresh extract → $TEST_DIR"
mkdir -p "$(dirname "$TEST_DIR")"
rm -rf "$TEST_DIR"
mkdir -p "$TEST_DIR"
unzip -q "$ZIP_OUT" -d "$TEST_DIR"
chmod +x "$TEST_DIR/install.sh" 2>/dev/null || true

echo ""
echo "Ready. In a terminal keep this running:"
echo "  cd $TEST_DIR"
echo "  ./install.sh"
echo ""
echo "Browser: http://127.0.0.1:8099/"
echo "Use an empty MySQL database each run."
