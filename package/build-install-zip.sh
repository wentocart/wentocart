#!/usr/bin/env bash
# Build the merchant install zip from this repository (any path).
# Output: dist/wentocart.zip (override with WENTO_ZIP_OUT=/path/to/file.zip)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PKG="$ROOT/package"
DIST="${WENTO_ZIP_OUT:-$ROOT/dist/wentocart.zip}"
DIST_DIR="$(dirname "$DIST")"
STAGING="$DIST_DIR/.staging-inner"
WRAPPER="$DIST_DIR/.staging-wrapper"

cleanup() {
  rm -rf "$STAGING" "$WRAPPER"
}
trap cleanup EXIT

if [[ ! -f "$ROOT/artisan" ]]; then
  echo "Could not find artisan next to package/" >&2
  exit 1
fi

cd "$ROOT"

require_build_tools() {
  local missing=0
  for cmd in php composer node npm tar; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
      echo "Missing command: $cmd" >&2
      missing=1
    fi
  done
  if [[ "$missing" -ne 0 ]]; then
    print_ubuntu_tool_help
    exit 1
  fi
  if ! php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
    echo "PHP $(php -r 'echo PHP_VERSION;') is too old. The zip build needs PHP 8.3 or newer as the \`php\` on PATH." >&2
    print_ubuntu_tool_help
    exit 1
  fi
  if ! php -r 'foreach (["ctype","curl","dom","fileinfo","mbstring","openssl","phar","tokenizer","xml","zip"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "PHP extension missing: $ext\n"); exit(1); } }'; then
    print_ubuntu_tool_help
    exit 1
  fi
  if ! composer -V 2>/dev/null | grep -q 'Composer version 2\.'; then
    echo "Composer 2 is required (composer -V)." >&2
    print_ubuntu_tool_help
    exit 1
  fi
  # Vite 8: ^20.19.0 || >=22.12.0. Ubuntu apt nodejs (18.x, or 22.0–22.11) cannot build assets.
  if ! node -e 'const p=process.versions.node.split(".").map(Number); const M=p[0], m=p[1]||0; const ok=(M===20&&m>=19)||(M===22&&m>=12)||M>22; if(!ok) process.exit(1);'; then
    echo "Node $(node -v) is too old. Need Node 20.19+ or 22.12+ (Vite 8)." >&2
    print_ubuntu_tool_help
    exit 1
  fi
  if [[ ! -f package-lock.json ]]; then
    echo "package-lock.json is missing. Keep it next to package.json so npm ci is the same on every machine." >&2
    exit 1
  fi
  if ! node -e 'const fs=require("fs"); const pkg=JSON.parse(fs.readFileSync("package.json","utf8")); const lock=JSON.parse(fs.readFileSync("package-lock.json","utf8")); const root=(lock.packages&&lock.packages[""])||{}; const keys=["dependencies","devDependencies","optionalDependencies"]; for (const key of keys) { const a=JSON.stringify(Object.fromEntries(Object.entries(pkg[key]||{}).sort())); const b=JSON.stringify(Object.fromEntries(Object.entries(root[key]||{}).sort())); if (a!==b) { console.error("package.json and package-lock.json do not match ("+key+")."); console.error("Copy both files from the same project revision. Do not edit package.json without regenerating the lockfile."); process.exit(1);} }'; then
    exit 1
  fi
  local avail_kb
  avail_kb="$(df -Pk "$ROOT" | awk 'NR==2 {print $4}')"
  if [[ -n "${avail_kb}" && "${avail_kb}" -lt 1500000 ]]; then
    echo "Need about 1.5 GB free on $(df -P "$ROOT" | awk 'NR==2 {print $6}') to stage and compress the install zip. Free now: $((avail_kb / 1024)) MB." >&2
    exit 1
  fi
  echo "Build tools: PHP $(php -r 'echo PHP_VERSION;'), Node $(node -v), $(composer -V | head -n1)"
}

print_ubuntu_tool_help() {
  echo "" >&2
  echo "Ubuntu packages for this build:" >&2
  echo "  sudo apt install -y php8.3-cli php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-mysql composer" >&2
  echo "Ubuntu 22.04 does not ship PHP 8.3 in the default apt repos. Use a PHP 8.3 build and make \`php -v\` print 8.3." >&2
  echo "Ubuntu's apt nodejs package is too old for this Vite build. Install Node.js 20.19+ or 22.12+ from https://nodejs.org/ or nvm, then open a new terminal." >&2
}

require_build_tools

if [[ ! -f vendor/autoload.php ]]; then
  echo "Running composer install --no-dev…"
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
fi

# Always rebuild frontend assets for the merchant zip. Skipping when
# public/build/manifest.json already exists shipped stale Host / extension
# navigation bundles after source-only merges.
if [[ "${WENTO_SKIP_FRONTEND_BUILD:-0}" == "1" ]]; then
  echo "Skipping frontend build (WENTO_SKIP_FRONTEND_BUILD=1)."
else
  if [[ ! -d node_modules ]] || [[ package-lock.json -nt node_modules ]]; then
    echo "Running npm ci…"
    npm ci --no-fund --no-audit
  else
    echo "Reusing node_modules (package-lock.json unchanged)."
  fi
  echo "Running npm run build…"
  npm run build
fi

if [[ ! -f vendor/autoload.php ]]; then
  echo "vendor/autoload.php missing" >&2
  exit 1
fi
if [[ ! -f public/build/manifest.json ]]; then
  echo "public/build/manifest.json missing" >&2
  exit 1
fi
php "$PKG/verify-admin-build.php" "$ROOT/public/build/manifest.json"

rm -rf "$STAGING" "$WRAPPER"
mkdir -p "$STAGING" "$WRAPPER" "$DIST_DIR"

echo "Staging shop tree…"
# tar is reliable across environments; rsync can hang under some sandboxes.
tar -C "$ROOT" \
  --exclude='node_modules' \
  --exclude='./node_modules' \
  --exclude='.git' \
  --exclude='.cursor' \
  --exclude='.idea' \
  --exclude='.vscode' \
  --exclude='.phpunit.cache' \
  --exclude='package' \
  --exclude='public/hot' \
  --exclude='public/storage' \
  --exclude='storage/logs' \
  --exclude='storage/pail' \
  --exclude='tests' \
  --exclude='docs' \
  --exclude='agent-skills' \
  --exclude='api-collections' \
  --exclude='bin' \
  --exclude='extension-src' \
  --exclude='tools' \
  --exclude='extensions' \
  --exclude='headless' \
  --exclude='mobile' \
  --exclude='docker' \
  --exclude='plugin-packs' \
  --exclude='plugins' \
  --exclude='dist' \
  --exclude='.env' \
  --exclude='.env.backup' \
  --exclude='.env.production' \
  --exclude='auth.json' \
  --exclude='php.path' \
  --exclude='composer.phar' \
  --exclude='php.d' \
  --exclude='.php-uploads.ini' \
  --exclude='helper.port' \
  --exclude='_ide_helper.php' \
  --exclude='Homestead.json' \
  --exclude='Homestead.yaml' \
  --exclude='.phpunit.result.cache' \
  --exclude='public/fonts-manifest.dev.json' \
  --exclude='public/themes' \
  --exclude='setup/install-status.json' \
  --exclude='setup/install-job.json' \
  --exclude='setup/cli-worker.log' \
  --exclude='setup/install-worker.lock' \
  --exclude='setup/prepare-worker.lock' \
  --exclude='storage/framework/cache/data' \
  --exclude='storage/framework/sessions' \
  --exclude='storage/framework/views' \
  --exclude='INSTALL.bat' \
  --exclude='INSTALL.sh' \
  --exclude='start-installer.bat' \
  --exclude='start-installer.sh' \
  --exclude='AGENTS.md' \
  --exclude='phpunit.xml' \
  --exclude='docker-compose.yml' \
  --exclude='.dockerignore' \
  --exclude='.editorconfig' \
  --exclude='.npmrc' \
  --exclude='vendor' \
  --exclude='storefront' \
  --exclude='resources/js' \
  --exclude='resources/css' \
  --exclude='database/factories' \
  --exclude='database/migrations' \
  --exclude='database/schema/sqlite-schema.sql' \
  --exclude='scripts' \
  --exclude='.github' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='vite.config.js' \
  --exclude='README.md' \
  --exclude='serve-shop.sh' \
  -cf - . | tar -C "$STAGING" -xf -
rm -f \
  "$STAGING/storage/app/installed.lock" \
  "$STAGING/storage/app/installing.lock" \
  "$STAGING/storage/app/tidy-installer" \
  "$STAGING/setup/status.json" \
  "$STAGING/storage/app/install-answers.json" \
  "$STAGING/storage/framework/setup-status.json" \
  "$STAGING/storage/framework/install-status.json" \
  "$STAGING/storage/framework/install-job.json" \
  "$STAGING"/storage/*.key \
  "$STAGING"/storage/*.json \
  "$STAGING"/bootstrap/cache/*.php

# Excludes drop these directories. A fresh install still needs them writable.
mkdir -p \
  "$STAGING/storage/logs" \
  "$STAGING/storage/framework/cache/data" \
  "$STAGING/storage/framework/sessions" \
  "$STAGING/storage/framework/views" \
  "$STAGING/bootstrap/cache"
for dir in \
  storage/logs \
  storage/framework \
  storage/framework/cache \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  bootstrap/cache
do
  if [[ ! -f "$STAGING/$dir/.gitignore" ]]; then
    printf '*\n!.gitignore\n' > "$STAGING/$dir/.gitignore"
  fi
done
find "$STAGING" -type f \( -name '*.log' -o -name '.DS_Store' -o -name 'Thumbs.db' \) -delete

echo "Installing production PHP packages…"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --working-dir="$STAGING"
php "$PKG/export-ui-catalog.php" "$ROOT" "$STAGING/resources/i18n/ui-source.json"

if [[ ! -f "$STAGING/vendor/autoload.php" ]]; then
  echo "staging missing vendor/autoload.php" >&2
  exit 1
fi
if [[ ! -f "$STAGING/public/build/manifest.json" ]]; then
  echo "staging missing public/build/manifest.json" >&2
  exit 1
fi
# Official release ships no extensions. Merchants install them later from the shop.
rm -rf "$STAGING/extensions"
mkdir -p "$STAGING/extensions"
printf '*\n!.gitignore\n!README.md\n' > "$STAGING/extensions/.gitignore"
if [[ -f "$ROOT/extensions/README.md" ]]; then
  cp "$ROOT/extensions/README.md" "$STAGING/extensions/README.md"
fi
if find "$STAGING/extensions" -name app.json -print -quit | grep -q .; then
  echo "release zip must not include extension packages" >&2
  exit 1
fi

# Pre-publish Classic so install can skip a ~60MB theme copy on first demo run.
THEME_SRC="$STAGING/themes/classic"
THEME_DST="$STAGING/public/themes/classic"
if [[ -d "$THEME_SRC/assets" && -f "$THEME_SRC/theme.json" ]]; then
  echo "Pre-publishing Classic theme assets into public/themes…"
  rm -rf "$THEME_DST"
  mkdir -p "$THEME_DST"
  cp -a "$THEME_SRC/assets" "$THEME_DST/"
  cp -a "$THEME_SRC/theme.json" "$THEME_DST/"
  [[ -d "$THEME_SRC/dist" ]] && cp -a "$THEME_SRC/dist" "$THEME_DST/"
  for f in preview.svg preview.png preview.webp settings_schema.json; do
    [[ -f "$THEME_SRC/$f" ]] && cp -a "$THEME_SRC/$f" "$THEME_DST/"
  done
fi

# Merchant zip must use RewriteBase / — a folder-specific value from the
# build machine would 404 /admin/login on every other install path.
echo "Writing path-agnostic Apache .htaccess for the merchant zip…"
cp "$PKG/preinstall.htaccess" "$STAGING/.htaccess"
mkdir -p "$STAGING/app/Domain/Installer"
cp "$PKG/preinstall.htaccess" "$STAGING/app/Domain/Installer/preinstall.htaccess"
if [[ -f "$STAGING/public/.htaccess" ]]; then
  php -r '$f=$argv[1]; $b=file_get_contents($f); $b=preg_replace("/^\\s*RewriteBase\\s+\\S+/mi","RewriteBase /",$b,1); file_put_contents($f,$b);' \
    "$STAGING/public/.htaccess"
fi

INNER="$WRAPPER/shop.zip"
echo "Compressing inner shop.zip…"
php "$PKG/zip-tree.php" pack "$STAGING" "$INNER"

for name in index.php checks.php install-guide.php system-check.php installer.css unpack.js preinstall.htaccess logo.jpeg Install.vbs install.sh server.php .htaccess; do
  cp "$PKG/$name" "$WRAPPER/$name"
done
cp "$ROOT/scripts/install/helper.php" "$WRAPPER/helper.php"
chmod +x "$WRAPPER/install.sh" 2>/dev/null || true

echo "Compressing outer $(basename "$DIST")…"
rm -f "$DIST"
php "$PKG/zip-tree.php" pack "$WRAPPER" "$DIST"

echo "Verifying…"
php -d memory_limit=512M "$PKG/zip-tree.php" verify "$DIST"

ls -lh "$DIST"
echo "Send this file to the customer: $DIST"
