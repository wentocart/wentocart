#!/usr/bin/env bash
# Serve the shop with upload limits that allow review videos (up to ~40MB).
# Important: artisan serve spawns a child `php -S` process. Plain `php -d ... artisan serve`
# does NOT apply those -d flags to the child. PHP_INI_SCAN_DIR does.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

mkdir -p "$ROOT/php.d"
cat > "$ROOT/php.d/99-wento-uploads.ini" <<'EOF'
upload_max_filesize = 40M
post_max_size = 48M
max_file_uploads = 20
EOF

# Prepend project scan dir; keep system CLI conf.d so mysqlnd/opcache still load.
SCAN_SYSTEM="${PHP_INI_SCAN_DIR:-/etc/php/8.3/cli/conf.d}"
export PHP_INI_SCAN_DIR="$ROOT/php.d:${SCAN_SYSTEM}"

php -r 'fwrite(STDERR, "PHP upload_max_filesize=".ini_get("upload_max_filesize")." post_max_size=".ini_get("post_max_size").PHP_EOL);'

exec php artisan serve --host=127.0.0.1 --port="${PORT:-8097}" "$@"
