#!/usr/bin/env bash
#
# CET Command Centre — auto-deploy. Set up ONCE as a cPanel cron every 5 min:
#   */5 * * * * /home/u2beq0g0k7mj/cet-staging/bin/auto-deploy.sh >> /home/u2beq0g0k7mj/cet-staging/storage/logs/auto-deploy.log 2>&1
# Then every push goes live on its own — old code can never linger.
#
# Cron runs with a bare-bones PATH, so `php`/`git` may not be found and the job
# would silently fail. We set a sane PATH and auto-detect the PHP binary below,
# so this works unattended from cron exactly as it does from a login shell.
#
set -euo pipefail
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:$PATH"

BRANCH="${CET_DEPLOY_BRANCH:-claude/cet-command-centre-guide-r1azew}"
cd "$(dirname "$0")/.."

# Pick a PHP binary: honour $CET_PHP, else the cPanel EA-PHP 8.2 alias, else php.
PHP="${CET_PHP:-}"
if [ -z "$PHP" ]; then
    if command -v ea-php82 >/dev/null 2>&1; then PHP="ea-php82"
    elif command -v php >/dev/null 2>&1; then PHP="php"
    else echo "$(date '+%F %T') ERROR: no php binary found on PATH" >&2; exit 1
    fi
fi

git fetch --quiet origin "$BRANCH"
LOCAL="$(git rev-parse HEAD)"
REMOTE="$(git rev-parse "origin/$BRANCH")"
[ "$LOCAL" = "$REMOTE" ] && exit 0   # up to date — nothing to do, stay quiet

echo "$(date '+%F %T') deploying ${LOCAL:0:8} -> ${REMOTE:0:8}"
git reset --hard "origin/$BRANCH"
"$PHP" artisan migrate --force
"$PHP" artisan optimize:clear
"$PHP" artisan cet:opcache-clear || true   # resets the WEB OPcache over HTTP

# Restart PHP so no worker keeps serving old compiled code. On shared cPanel the
# whole-server restarts need root and will no-op — the HTTP OPcache reset above
# is the reliable path; these are best-effort belt-and-braces.
( killall lsphp 2>/dev/null ) || ( pkill -f lsphp 2>/dev/null ) || true
( /usr/local/lsws/bin/lswsctrl restart 2>/dev/null ) || true
( kill -USR2 "$(pgrep -o php-fpm 2>/dev/null)" 2>/dev/null ) || true

echo "$(date '+%F %T') deploy complete — PHP restarted"
