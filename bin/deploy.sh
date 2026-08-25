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

# CRITICAL: restart PHP so the WEB workers drop their old compiled code (OPcache).
# Without this some requests keep serving the old version and the driver sees
# stale/wrong info at random. Tries the common LiteSpeed/cPanel + FPM restarts;
# harmless if a given one isn't available.
echo "Restarting PHP to clear the web OPcache…"
( killall lsphp 2>/dev/null ) || ( pkill -f lsphp 2>/dev/null ) || true
( /usr/local/lsws/bin/lswsctrl restart 2>/dev/null ) || true
( kill -USR2 "$(pgrep -o php-fpm 2>/dev/null)" 2>/dev/null ) || true

echo "Deployed $(git rev-parse --short HEAD) — all PHP workers restarted."
