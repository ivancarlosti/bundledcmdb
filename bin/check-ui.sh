#!/bin/sh
# bin/check-ui.sh — run the SPA gates locally (no GitHub Actions).
#
# Installs the SPA dependencies and runs the same checks the retired
# `.github/workflows/ui.yml` used to run: typecheck, lint, format, vitest and a
# production build into `public/app/` (what nginx serves at `/app/` and what the
# release ZIP ships).
#
#   ./bin/check-ui.sh              # npm ci + every gate
#   ./bin/check-ui.sh --no-install # reuse the existing ui/node_modules
#   ./bin/check-ui.sh --e2e        # also run Playwright (vite preview on :4173);
#                                  # browsers needed once:
#                                  #   npx --prefix ui playwright install chromium
#
# Node 24 is required (ui/package.json `engines`). Exit code 0 = all gates passed.

set -eu

cd "$(dirname "$0")/.."

usage() {
    cat <<'EOF'
usage: ./bin/check-ui.sh [--no-install] [--e2e]

  --no-install   reuse ui/node_modules instead of running `npm ci`
  --e2e          also run the Playwright suite against `vite preview`
  -h, --help     show this help

Gates: typecheck, lint, format (prettier --check), vitest, build into public/app/.
EOF
}

install=1
e2e=0
for arg in "$@"; do
    case "$arg" in
        --no-install) install=0 ;;
        --e2e) e2e=1 ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            echo "unknown option: $arg" >&2
            usage >&2
            exit 2
            ;;
    esac
done

step() {
    printf '\n==> %s\n' "$1"
}

if [ "$install" -eq 1 ]; then
    step 'npm ci (ui)'
    npm ci --prefix ui --no-audit --no-fund
fi

step 'typecheck'
npm run typecheck --prefix ui

step 'lint'
npm run lint --prefix ui

step 'format'
npm run format --prefix ui

step 'test (vitest)'
npm run test --prefix ui

step 'build (public/app)'
npm run build --prefix ui
test -f public/app/index.html

if [ "$e2e" -eq 1 ]; then
    step 'e2e (playwright)'
    npm run e2e --prefix ui
fi

printf '\nall SPA gates passed.\n'
