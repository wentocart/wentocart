#!/usr/bin/env bash
# Customer CLI uses whatever `php` is on PATH. No stack scanning.
#   ./INSTALL.sh doctor
#   ./INSTALL.sh --cli
#   ./INSTALL.sh --cli --prefix=WBL --admin-email=you@store.test
set -euo pipefail
cd "$(dirname "$0")"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP 8.3+ is not on PATH."
  echo "Use the PHP from your stack (XAMPP, WAMP, apt, Homebrew), or: docker compose up --build"
  echo "See docs/INSTALL.md"
  exit 1
fi

if ! php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
  echo "This php is $(php -r 'echo PHP_VERSION;') at $(command -v php)."
  echo "Need 8.3+. Run the 8.3 binary from your stack, or: docker compose up --build"
  exit 1
fi

cmd="${1:-}"
if [[ "${cmd}" == "doctor" || "${cmd}" == "--doctor" ]]; then
  exec php artisan wento:doctor
fi
if [[ "${cmd}" == "help" || "${cmd}" == "--help" || "${cmd}" == "-h" || "${cmd}" == "" ]]; then
  exec php artisan wento:install --help
fi

if [[ ! -f vendor/autoload.php ]]; then
  echo "vendor/ is missing. Developers: composer install"
  echo "Or skip local PHP: docker compose up --build"
  exit 1
fi

if [[ "${cmd}" == "--cli" || "${cmd}" == "cli" ]]; then
  shift
fi

exec php artisan wento:install "$@"
