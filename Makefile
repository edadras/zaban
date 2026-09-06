# ---------------------------------------------------------------------------
# Zaban — developer tasks.
#
#   make up            start the stack (first run also installs and migrates)
#   make help          list everything
#
# Every target runs inside the containers, so the only things you need on the
# host are Docker and Make. If you would rather run PHP on the host, the same
# artisan commands work from backend/ with a .env pointed at localhost.
# ---------------------------------------------------------------------------

SHELL := /bin/bash
.DEFAULT_GOAL := help

COMPOSE     ?= docker compose
APP         := $(COMPOSE) exec -T app
APP_TTY     := $(COMPOSE) exec app
ARTISAN     := $(APP) php artisan
ROOT        := $(shell pwd)

# Build the image as the current user so files written into the bind mount stay
# editable on the host.
export UID  ?= $(shell id -u)
export GID  ?= $(shell id -g)

.PHONY: help
help: ## List available targets
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
		| sort \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

# --------------------------------------------------------------- lifecycle

.PHONY: up
up: backend/.env ## Start the stack in the background and wait for it to be healthy
	@# Waits on the long-running services only. Their depends_on pulls up mysql,
	@# redis, minio and the one-shot minio-init; naming minio-init here would ask
	@# --wait to wait for a container that is meant to exit.
	$(COMPOSE) up -d --wait nginx worker scheduler
	@echo
	@echo "  API      http://localhost:$${HTTP_PORT:-8080}"
	@echo "  health   http://localhost:$${HTTP_PORT:-8080}/up"
	@echo "  MinIO    http://localhost:$${MINIO_CONSOLE_PORT:-9001}"
	@echo
	@echo "  Next:  make migrate    (schema)"
	@echo "         make import     (curriculum from docs/data)"

.PHONY: down
down: ## Stop the stack, keeping volumes
	$(COMPOSE) down --remove-orphans

.PHONY: destroy
destroy: ## Stop the stack AND delete the database, redis and MinIO volumes
	$(COMPOSE) down --remove-orphans --volumes

.PHONY: build
build: ## Rebuild the application image
	$(COMPOSE) build --pull

.PHONY: restart
restart: ## Restart every service
	$(COMPOSE) restart

.PHONY: ps
ps: ## Show container status and health
	$(COMPOSE) ps

.PHONY: realtime
realtime: ## Start the optional Reverb websocket container (needs laravel/reverb installed)
	$(COMPOSE) --profile realtime up -d reverb

# ------------------------------------------------------------------ shells

.PHONY: shell
shell: ## Interactive shell in the app container
	$(APP_TTY) bash

.PHONY: root-shell
root-shell: ## Root shell in the app container (installing system packages)
	$(COMPOSE) exec -u root app bash

.PHONY: tinker
tinker: ## Laravel REPL
	$(APP_TTY) php artisan tinker

.PHONY: mysql
mysql: ## MySQL client on the application database
	$(COMPOSE) exec mysql sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"'

.PHONY: redis-cli
redis-cli: ## Redis client
	$(COMPOSE) exec redis redis-cli

# ---------------------------------------------------------------- database

.PHONY: migrate
migrate: ## Run pending migrations
	$(ARTISAN) migrate

.PHONY: migrate-fresh
migrate-fresh: ## Drop everything and re-migrate (destroys all data)
	$(ARTISAN) migrate:fresh

.PHONY: migrate-status
migrate-status: ## Show which migrations have run
	$(ARTISAN) migrate:status

.PHONY: seed
seed: ## Run database seeders
	$(ARTISAN) db:seed

.PHONY: fresh
fresh: ## migrate:fresh + seed (destroys all data)
	$(ARTISAN) migrate:fresh --seed

# ----------------------------------------------------------------- content
#
# The content pipeline in order. Stages 1-2 (the Python extractors) run against
# sources/*.pdf and need `git lfs pull` first; their output is committed under
# docs/data, so a fresh clone can skip straight to `make import`.

.PHONY: extract
extract: ## Re-run the Python extractors over sources/*.pdf into docs/data
	$(APP) python3 /srv/zaban/tools/extract_content.py
	$(APP) python3 /srv/zaban/tools/extract_images.py

.PHONY: import
import: ## Import the extracted curriculum into the database (idempotent)
	$(ARTISAN) content:import

.PHONY: import-fresh
import-fresh: ## Delete previously imported curriculum, then import
	$(ARTISAN) content:import --fresh

.PHONY: activities
activities: ## Derive lesson blocks and gradable exercises from imported content
	$(ARTISAN) content:build-activities

.PHONY: exams
exams: ## Build exam tasks from the authored production prompts
	$(ARTISAN) content:build-exams

.PHONY: publish
publish: ## Release the imported curriculum to learners (import resets it to draft)
	$(ARTISAN) content:publish --everything

.PHONY: audit
audit: ## Prove nothing from the source books was lost on import
	$(ARTISAN) content:audit

.PHONY: readiness
readiness: ## Report whether stored content can actually drive the adaptive engines
	$(ARTISAN) content:readiness

.PHONY: content
content: import activities exams publish audit readiness ## Full content pipeline: import, derive, publish, audit, report

# ------------------------------------------------------------------ queues

.PHONY: queue-restart
queue-restart: ## Tell every queue worker to finish its job and restart (after a deploy)
	$(ARTISAN) queue:restart
	@echo "Workers will pick up the new code as each finishes its current job."

.PHONY: queue-status
queue-status: ## Show the six worker pools and their state
	$(COMPOSE) exec worker supervisorctl -c /srv/zaban/docker/worker/supervisord.conf status

.PHONY: queue-failed
queue-failed: ## List failed jobs
	$(ARTISAN) queue:failed

.PHONY: queue-retry
queue-retry: ## Retry every failed job
	$(ARTISAN) queue:retry all

.PHONY: queue-depth
queue-depth: ## Pending job count per queue
	@# The Redis key is <REDIS_PREFIX>queues:<name>, and REDIS_PREFIX defaults to
	@# slug(APP_NAME)-database- (config/database.php), so the key is discovered
	@# rather than guessed.
	@$(COMPOSE) exec -T redis sh -c 'for q in default content media speech ai-high ai-low; do \
		k=$$(redis-cli --scan --pattern "*queues:$$q" | head -n1); \
		printf "%-10s %s\n" "$$q" "$$([ -n "$$k" ] && redis-cli LLEN "$$k" || echo 0)"; done'

# -------------------------------------------------------------------- logs

.PHONY: logs
logs: ## Tail logs from every container
	$(COMPOSE) logs -f --tail=100

.PHONY: logs-app
logs-app: ## Tail the application log (pail: formatted Laravel output)
	$(APP_TTY) php artisan pail --timeout=0

.PHONY: logs-worker
logs-worker: ## Tail the queue worker container
	$(COMPOSE) logs -f --tail=100 worker

.PHONY: logs-nginx
logs-nginx: ## Tail nginx access and error logs
	$(COMPOSE) logs -f --tail=100 nginx

# ------------------------------------------------------------------- tests

.PHONY: test
test: ## Run the test suite
	$(APP) php artisan test

.PHONY: test-filter
test-filter: ## Run tests matching FILTER=… (make test-filter FILTER=Placement)
	$(APP) php artisan test --filter="$(FILTER)"

.PHONY: lint
lint: ## Check code style (Pint, dry run)
	$(APP) ./vendor/bin/pint --test

.PHONY: format
format: ## Fix code style (Pint)
	$(APP) ./vendor/bin/pint

.PHONY: check
check: lint test ## Style + tests, what CI runs

# ------------------------------------------------------------- maintenance

.PHONY: install
install: ## Install composer dependencies
	$(APP) composer install

.PHONY: key
key: ## Generate APP_KEY
	$(ARTISAN) key:generate

.PHONY: cache-clear
cache-clear: ## Clear config, route, view and application caches
	$(ARTISAN) optimize:clear

.PHONY: optimize
optimize: ## Cache config, routes and views (what production does on deploy)
	$(ARTISAN) optimize

.PHONY: routes
routes: ## List registered API routes
	$(ARTISAN) route:list --path=api

.PHONY: about
about: ## Laravel environment summary (drivers, versions, cached state)
	$(ARTISAN) about

# A .env is required before anything can boot; create it once, quietly.
backend/.env:
	@cp backend/.env.example backend/.env
	@echo "Created backend/.env from .env.example — add your ANTHROPIC_API_KEY before using AI features."
