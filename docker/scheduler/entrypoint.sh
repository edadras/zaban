#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Scheduler container.
#
# `schedule:work` is a foreground loop that invokes `schedule:run` once a minute.
# That is exactly what a cron entry would do, minus the cron daemon, which suits
# a container: the process is the schedule, so if it dies the orchestrator
# notices and restarts it, and its output goes to the container log.
#
# Exactly ONE of these may run per environment. Two schedulers means every daily
# job fires twice — see docs/DEPLOYMENT.md for the production arrangement.
#
# STATUS: routes/console.php currently registers only Laravel's stock `inspire`
# command and no scheduled tasks, so this container is running an empty schedule.
# It is part of the stack now so that the first scheduled task (review-due
# recalculation, streak rollover, AI spend rollup) has a home and is exercised in
# development before it matters in production.
# ---------------------------------------------------------------------------
set -euo pipefail

cd /srv/zaban/backend

exec php artisan schedule:work --no-interaction
