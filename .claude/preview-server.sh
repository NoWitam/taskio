#!/usr/bin/env bash
# Started by .claude/launch.json (Claude preview). $1 = harness-assigned port.
set -e
cd /home/postgres/taskio
exec php artisan serve --port="${1:-8000}"
