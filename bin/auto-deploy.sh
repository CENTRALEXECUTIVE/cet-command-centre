#!/usr/bin/env bash
#
# CET Command Centre — auto-deploy.
#
# Pulls the deploy branch and applies it (migrate + clear caches + reset the web
# OPcache) ONLY when there are new commits. Safe to run every few minutes: it
# does nothing when already up to date.
#
# ONE-TIME SETUP (cPanel → Cron Jobs), every 5 minutes:
#   */5 * * * * /home/u2beq0g0k7mj/cet-staging/bin/auto-deploy.sh >> /home/u2beq0g0k7mj/cet-staging/storage/logs/auto-deploy.log 2>&1
#
# After that, every push to the branch goes live on its own within ~5 minutes —
# no manual deploy needed.
#
set -euo pipefail

BRANCH="${CET_DEPLOY_BRANCH:-claude/cet-command-centre-guide-r1azew}"
cd "$(dirname "$0")/.."

git fetch --quiet origin "$BRANCH"
LOCAL="$(git rev-parse HEAD)"
REMOTE="$(git rev-parse "origin/$BRANCH")"

if [ "$LOCAL" = "$REMOTE" ]; then
    exit 0   # already up to date — nothing to do
fi

echo "$(date '+%F %T') deploying ${LOCAL:0:8} -> ${REMOTE:0:8}"
git reset --hard "origin/$BRANCH"
php artisan migrate --force
php artisan optimize:clear
php artisan cet:opcache-clear || true
echo "$(date '+%F %T') deploy complete"
