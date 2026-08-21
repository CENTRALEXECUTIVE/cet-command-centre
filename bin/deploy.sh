#!/usr/bin/env bash
#
# CET Command Centre — one-command manual deploy. Run on the staging box:
#   ~/cet-staging/bin/deploy.sh
#
set -euo pipefail
BRANCH="${CET_DEPLOY_BRANCH:-claude/cet-command-centre-guide-r1azew}"
cd "$(dirname "$0")/.."
git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"
php artisan migrate --force
php artisan optimize:clear
php artisan cet:opcache-clear || true
echo "Deployed $(git rev-parse --short HEAD)."
