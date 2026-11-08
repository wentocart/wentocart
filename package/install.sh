#!/usr/bin/env bash
# Starts a local PHP server for this folder (port 8099).
# Looks for PHP in: ./php.path → PATH → common locations (any install prefix).
set -euo pipefail
cd "$(dirname "$0")"

resolve_php() {
  local line cand
  if [[ -f php.path ]]; then
    while IFS= read -r line || [[ -n "$line" ]]; do
      line="${line#"${line%%[![:space:]]*}"}"
      line="${line%"${line##*[![:space:]]}"}"
      [[ -z "$line" || "$line" == \#* ]] && continue
      cand="${line%\"}"
      cand="${cand#\"}"
      if [[ -x "$cand" ]]; then
        echo "$cand"
        return 0
      fi
    done < php.path
  fi

  if command -v php >/dev/null 2>&1; then
    command -v php
    return 0
  fi

  local c
  for c in \
    /usr/bin/php8.4 \
    /usr/bin/php8.3 \
    /usr/bin/php \
    /usr/local/bin/php \
    /opt/homebrew/bin/php \
    /opt/homebrew/opt/php@8.3/bin/php \
    "$HOME/.phpenv/shims/php" \
    /opt/lampp/bin/php \
    /Applications/XAMPP/xamppfiles/bin/php
  do
    if [[ -x "$c" ]]; then
      echo "$c"
      return 0
    fi
  done

  return 1
}

PHP_BIN="$(resolve_php || true)"
if [[ -z "${PHP_BIN}" ]]; then
  echo "PHP 8.3 or newer was not found."
  echo ""
  echo "1) Install PHP 8.3 or newer and add it to PATH, then run this installer again."
  echo "2) Or create a file named php.path in this folder and put the full path to the PHP program on the first line."
  echo "3) Or point your web server at this folder and open that site address. This helper is not required."
  echo ""
  exit 1
fi

# Prefer PHP 8.3+ with required extensions when the finder is present (outer zip / git).
FIND_PHP=""
if [[ -f helper.php ]]; then
  FIND_PHP="helper.php"
elif [[ -f scripts/install/helper.php ]]; then
  FIND_PHP="scripts/install/helper.php"
fi
if [[ -n "${FIND_PHP}" ]]; then
  BEST="$("${PHP_BIN}" "${FIND_PHP}" php 2>/dev/null || true)"
  if [[ -n "${BEST}" && -x "${BEST}" ]]; then
    PHP_BIN="${BEST}"
  fi
fi

PREFERRED=""
if [[ -f helper.port ]]; then
  PREFERRED="$(tr -d '[:space:]' < helper.port || true)"
fi
if [[ -z "${PREFERRED}" && -f .env ]]; then
  raw="$(grep -E '^APP_URL=' .env | head -n1 | cut -d= -f2- | tr -d '"')"
  PREFERRED="$(printf '%s' "${raw}" | sed -n 's#.*://[^/:]*:\([0-9][0-9]*\).*#\1#p')"
fi
FINDER=""
if [[ -f helper.php ]]; then
  FINDER="helper.php"
elif [[ -f scripts/install/helper.php ]]; then
  FINDER="scripts/install/helper.php"
fi
PORT=""
if [[ -n "${FINDER}" ]]; then
  PORT="$("${PHP_BIN}" "${FINDER}" port ${PREFERRED} || true)"
fi
if [[ -z "${PORT}" ]]; then
  PORT="${PREFERRED:-8099}"
fi

echo "Using ${PHP_BIN} ($("${PHP_BIN}" -r 'echo PHP_VERSION;'))"
echo "Opening http://127.0.0.1:${PORT}/  (keep this terminal open)"
(sleep 1; command -v xdg-open >/dev/null && xdg-open "http://127.0.0.1:${PORT}/"; command -v open >/dev/null && open "http://127.0.0.1:${PORT}/") &
ROOT_DIR="$(pwd)"
exec "${PHP_BIN}" -S 127.0.0.1:"${PORT}" -t "${ROOT_DIR}" "${ROOT_DIR}/server.php"
