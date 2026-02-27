#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="/var/www/html"
ONLY_DEPS=0
SKIP_DEPS=0

for arg in "$@"; do
  case "$arg" in
    --only-deps)
      ONLY_DEPS=1
      ;;
    --skip-deps)
      SKIP_DEPS=1
      ;;
    --help|-h)
      cat <<'USAGE'
Usage: bash scripts/yandex-reviews/install-playwright.sh [--only-deps] [--skip-deps]

Options:
  --only-deps  Install only OS dependencies (playwright install-deps chromium)
  --skip-deps  Skip OS dependencies installation
USAGE
      exit 0
      ;;
  esac
done

if [[ ! -f "${PROJECT_ROOT}/package.json" ]]; then
  PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fi

cd "${PROJECT_ROOT}"

if [[ -z "${PLAYWRIGHT_BROWSERS_PATH:-}" || "${PLAYWRIGHT_BROWSERS_PATH}" == "0" ]]; then
  export PLAYWRIGHT_BROWSERS_PATH="/var/www/html/storage/ms-playwright"
fi

mkdir -p "${PLAYWRIGHT_BROWSERS_PATH}"

if [[ "${ONLY_DEPS}" -ne 1 ]]; then
  if [[ -f package-lock.json ]]; then
    npm ci
  else
    npm install
  fi

  npx playwright install chromium
fi

if [[ "${SKIP_DEPS}" -eq 1 ]]; then
  echo "Skipped OS dependencies installation (--skip-deps)."
  exit 0
fi

if [[ "$(id -u)" -ne 0 ]]; then
  cat <<'NEXT'
OS dependencies installation requires root.
Run:
  ./vendor/bin/sail exec -u root laravel.test bash -lc "cd /var/www/html && bash scripts/yandex-reviews/install-playwright.sh --only-deps"
NEXT
  exit 0
fi

YARN_SOURCE_FILE="/etc/apt/sources.list.d/yarn.list"
YARN_SOURCE_DISABLED_FILE="${YARN_SOURCE_FILE}.disabled-by-playwright"

restore_yarn_source() {
  if [[ -f "${YARN_SOURCE_DISABLED_FILE}" ]]; then
    mv "${YARN_SOURCE_DISABLED_FILE}" "${YARN_SOURCE_FILE}"
  fi
}

if [[ -f "${YARN_SOURCE_FILE}" ]]; then
  mv "${YARN_SOURCE_FILE}" "${YARN_SOURCE_DISABLED_FILE}"
  trap restore_yarn_source EXIT
fi

npx playwright install-deps chromium
restore_yarn_source
trap - EXIT

echo "Playwright setup completed."
