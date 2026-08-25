#!/usr/bin/env bash
#
# CET Command Centre — auto-deploy. Set up once as a cPanel cron every 5 min:
#   */5 * * * * /home/u2beq0g0k7mj/cet-staging/bin/auto-deploy.sh >> /home/u2beq0g0k7mj/cet-staging/storage/logs/auto-deploy.log 2>&1
# Then every push goes live on its own — old code can never linger.
#
set -euo pipefail
BRANCH="${CET_DEPLOY_BRANCH:-claude/cet-command-centre-guide-r1azew}"
cd "$(dirname "$0")/.."

git fetch --quiet origin "$BRANCH"
LOCAL="$(git rev-parse HEAD)"
REMOTE="$(git rev-parse "origin/$BRANCH")"
[ "$LOCAL" = "$REMOTE" ] && exit 0   # up to date

echo "$(date '+%F %T') deploying ${LOCAL:0:8} -> ${REMOTE:0:8}"
git reset --hard "origin/$BRANCH"
php artisan migrate --force
php artisan optimize:clear
php artisan cet:opcache-clear || true

# Restart PHP so no worker keeps serving old compiled code.
( killall lsphp 2>/dev/null ) || ( pkill -f lsphp 2>/dev/null ) || true
( /usr/local/lsws/bin/lswsctrl restart 2>/dev/null ) || true
( kill -USR2 "$(pgrep -o php-fpm 2>/dev/null)" 2>/dev/null ) || true

echo "$(date '+%F %T') deploy complete — PHP restarted"
