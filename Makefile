# bentley-skeleton — the full command surface.
# Every command in CLAUDE.md and docs/cookbook/*.md is one of these targets.

SHELL          := /bin/bash
.DEFAULT_GOAL  := help
COMPOSE        := docker compose
APP            := $(COMPOSE) exec -T app
APP_TTY        := $(COMPOSE) exec app
PHP            := php
COMPOSER       := composer
# PHPMD 2.15 is the newest stable release and is not PHP 8.5 clean — it emits hundreds of
# deprecations from its own code and pdepend's. Silencing them here keeps `make arch`
# readable; it does not affect what PHPMD reports about OUR code. Revisit when phpmd 3 ships.
PHPMD          := $(PHP) -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpmd

# Run backend tooling in the container when the stack is up, on the host otherwise.
# This is what lets `make stan` work both in CI and on a laptop with no stack running.
IN_APP := $(shell $(COMPOSE) ps --status=running --services 2>/dev/null | grep -qx app && echo yes || echo no)
ifeq ($(IN_APP),yes)
  RUN_BACKEND = $(APP)
  RUN_BACKEND_COVERAGE = $(COMPOSE) exec -T -e XDEBUG_MODE=coverage app $(PHP)
else
  RUN_BACKEND = cd backend &&
  RUN_BACKEND_COVERAGE = cd backend && XDEBUG_MODE=coverage $(PHP)
endif

# Some targets must run on the HOST even when the stack is up, because they write to the
# repository working tree. The container only has backend/ mounted at /app — it cannot see
# docs/ — and CI runs these the same way, on the runner rather than in the image.
HOST_BACKEND := cd backend &&

.PHONY: help up down restart sh logs ps migrate migrate-down fixtures db-reset \
        test test-db test-unit test-integration test-functional coverage \
        lint fix stan arch proof docs docs-check e2e front-lint front-test front-build \
        adr endpoint service \
        check ci new-project keys hooks

help: ## Show this help
	@echo "bentley-skeleton — available targets:"
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-18s\033[0m %s\n",$$1,$$2}'

## ---------------------------------------------------------------- stack

up: ## Build if needed and start the dev stack
	# --renew-anon-volumes matters after an image rebuild: /app/vendor is an anonymous
	# volume, and Docker keeps the OLD one when recreating a container. Without this you
	# silently run yesterday's dependencies against today's code, and the failure looks
	# like a missing class rather than a stale volume.
	$(COMPOSE) up -d --build --wait --renew-anon-volumes
	@echo "API      http://localhost:8080"
	@echo "SPA      http://localhost:5173"
	@echo "Mailpit  http://localhost:8025"

down: ## Stop the stack and remove volumes
	$(COMPOSE) down --remove-orphans --volumes

restart: down up ## Recreate the stack from scratch

sh: ## Shell into the app container
	$(APP_TTY) sh

logs: ## Tail all service logs
	$(COMPOSE) logs -f --tail=100

ps: ## Show service status
	$(COMPOSE) ps

## ---------------------------------------------------------------- database

migrate: ## Apply pending migrations
	$(RUN_BACKEND) $(PHP) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

migrate-down: ## Roll the last migration back (CI proves every migration is reversible)
	$(RUN_BACKEND) $(PHP) bin/console doctrine:migrations:migrate prev --no-interaction

fixtures: ## Load the demo dataset (admin, user, groups, roles, sample ACEs)
	$(RUN_BACKEND) $(PHP) bin/console doctrine:fixtures:load --no-interaction

db-reset: ## Drop, recreate and migrate the database, then load fixtures
	$(RUN_BACKEND) $(PHP) bin/console doctrine:database:drop --force --if-exists
	$(RUN_BACKEND) $(PHP) bin/console doctrine:database:create
	$(MAKE) migrate fixtures

## ---------------------------------------------------------------- tests

# Idempotent, and a dependency of every target that runs tests.
#
# CI created the test database and the Makefile did not, so `make test` worked only on a
# volume that already had one. After `make down` (which removes volumes) or on a fresh clone,
# the whole suite failed with 31 connection errors naming a database nobody had been told to
# create.
test-db: ## Create and migrate the test database (idempotent)
	$(RUN_BACKEND) $(PHP) bin/console doctrine:database:create --if-not-exists --env=test
	$(RUN_BACKEND) $(PHP) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

test: test-db ## Run the whole backend test suite
	$(RUN_BACKEND) $(PHP) vendor/bin/phpunit

test-unit: test-db ## Unit tests only
	$(RUN_BACKEND) $(PHP) vendor/bin/phpunit --testsuite=unit

test-integration: test-db ## Integration tests only (needs Postgres + Redis)
	$(RUN_BACKEND) $(PHP) vendor/bin/phpunit --testsuite=integration

test-functional: test-db ## Functional tests only
	$(RUN_BACKEND) $(PHP) vendor/bin/phpunit --testsuite=functional

# XDEBUG_MODE goes through the runner, not in front of the command: `docker compose exec`
# execs the binary directly with no shell, so a leading VAR=value is read as the program name
# and fails with "executable file not found".
coverage: test-db ## Run tests with coverage and enforce the floors in bin/coverage-gate
	$(RUN_BACKEND_COVERAGE) vendor/bin/phpunit --coverage-text --coverage-clover=var/coverage/clover.xml
	$(RUN_BACKEND) $(PHP) bin/coverage-gate var/coverage/clover.xml

## ---------------------------------------------------------------- quality

lint: ## Check formatting and config validity without changing anything
	$(RUN_BACKEND) $(COMPOSER) validate --strict
	$(RUN_BACKEND) $(PHP) vendor/bin/php-cs-fixer check --diff
	$(RUN_BACKEND) $(PHP) vendor/bin/rector process --dry-run
	@# --parse-tags: config/services.yaml uses !tagged_iterator, and without this the
	@# linter reports the container's own DSL as a syntax error.
	$(RUN_BACKEND) $(PHP) bin/console lint:yaml config --parse-tags
	$(RUN_BACKEND) $(PHP) bin/console lint:container
	$(RUN_BACKEND) $(PHP) bin/console doctrine:schema:validate --skip-sync

# Rector FIRST, cs-fixer LAST. Rector rewrites structure and emits its own formatting —
# `\DateTimeImmutable` where the file already imports the short name, for example. Running
# cs-fixer first means Rector gets the last word and leaves the tree failing `make lint`,
# which is how this target used to behave.
fix: ## Apply every safe automatic fix (Rector for structure, then cs-fixer for formatting)
	@# Rector needs more than one pass to settle: narrowing a return type in pass 1 is what
	@# lets pass 2 narrow the caller. Running it once leaves changes that `make lint` then
	@# reports, so `make fix && make lint` would fail on a tree you just fixed. Loop until it
	@# stops changing files, capped so a rule that oscillates fails loudly instead of hanging.
	$(RUN_BACKEND) sh -c 'for i in 1 2 3 4 5; do \
	  $(PHP) vendor/bin/rector process --no-diffs | grep -q "Rector is done" && exit 0; \
	done; echo "Rector still changing files after 5 passes — a rule is likely oscillating."; exit 1'
	$(RUN_BACKEND) $(PHP) vendor/bin/php-cs-fixer fix

stan: ## PHPStan at max level, including the custom architecture rules
	$(RUN_BACKEND) $(PHP) vendor/bin/phpstan analyse --memory-limit=1G

arch: ## Enforce the architecture contract (deptrac + phpat + PHPMD + arch tests)
	@echo "--> layering contract"
	$(RUN_BACKEND) $(PHP) vendor/bin/deptrac analyse --config-file=deptrac.yaml
	@echo "--> bounded-context contract"
	$(RUN_BACKEND) $(PHP) vendor/bin/deptrac analyse --config-file=deptrac-context.yaml
	@echo "--> size and complexity limits"
	./bin/phpmd-check
	@echo "--> architecture tests"
	$(RUN_BACKEND) $(PHP) vendor/bin/phpunit --testsuite=architecture

proof: ## Prove the architecture rules actually fail on deliberate violations
	./bin/strictness-proof

docs: ## Regenerate the generated inventories (docs/SERVICES.md, ENDPOINTS.md, PERMISSIONS.md, adr/README.md)
	$(HOST_BACKEND) $(PHP) bin/console app:docs:generate

# Verifying is a separate target from writing, and it asks the generator rather than git.
# `git diff -- docs/` cannot tell a stale inventory from an uncommitted edit to a hand-written
# page, so editing a cookbook file used to fail this gate with a message blaming the
# generator — which had just reported itself up to date.
docs-check: ## Fail if any generated inventory is stale, naming the file
	$(HOST_BACKEND) $(PHP) bin/console app:docs:generate --check

## ---------------------------------------------------------------- frontend

front-lint: ## ESLint + vue-tsc on the SPA
	npm --prefix frontend run lint
	npm --prefix frontend run typecheck

front-test: ## Vitest unit tests
	npm --prefix frontend run test

front-build: ## Production SPA build
	npm --prefix frontend run build

e2e: ## Playwright end-to-end suite against the running stack
	# Clear the rate-limit counters first. The login limiter is 5 attempts per 15 minutes per
	# (IP + email), which is correct behaviour and exactly what a repeated local e2e run trips:
	# the tests then fail with 429 rather than the thing they were asserting. CI starts a fresh
	# stack so Redis is already empty; locally it is not.
	-$(COMPOSE) exec -T redis redis-cli FLUSHDB > /dev/null
	npm --prefix frontend run e2e

## ---------------------------------------------------------------- aggregate

check: lint stan arch test front-lint front-test ## Everything CI runs, except e2e

ci: check docs-check coverage e2e ## Literally everything

## ---------------------------------------------------------------- project setup

adr: ## Record an architecture decision: make adr TITLE="Use X instead of Y"
	$(RUN_BACKEND) $(PHP) bin/console make:adr $(if $(TITLE),"$(TITLE)",)

# Both makers prompt for anything you leave out, so `make endpoint` alone still works.
# Passing the variables makes them runnable without a terminal — which is what a scripted
# or AI-driven session needs.
# ROUTE, not PATH: PATH is the shell's, and setting it on the make command line would
# replace it for every recipe in this file.
endpoint: ## Generate an endpoint slice: make endpoint CONTEXT=Account NAME=ListNotes METHOD=GET ROUTE=/api/v1/notes PERMISSION=note.read WHY="..."
	$(RUN_BACKEND) $(PHP) bin/console make:api-endpoint $(CONTEXT) $(NAME) \
		$(if $(METHOD),--method="$(METHOD)",) $(if $(ROUTE),--path="$(ROUTE)",) \
		$(if $(PERMISSION),--permission="$(PERMISSION)",) $(if $(WHY),--responsibility="$(WHY)",)

service: ## Generate a single-topic service: make service CONTEXT=Account NAME=RotateToken WHY="..."
	$(RUN_BACKEND) $(PHP) bin/console make:service $(CONTEXT) $(NAME) \
		$(if $(WHY),--responsibility="$(WHY)",)

keys: ## Generate the JWT keypair (idempotent; never commit the result)
	$(RUN_BACKEND) $(PHP) bin/console lexik:jwt:generate-keypair --skip-if-exists

hooks: ## Install the git hooks (cs-fixer/phpstan/eslint on staged files, ADR reminder)
	git config core.hooksPath .githooks
	@echo "git hooks active: .githooks"

new-project: ## Reseed this template as a new project: make new-project NAME=acme-api
	@test -n "$(NAME)" || { echo "usage: make new-project NAME=your-project"; exit 1; }
	./bin/new-project "$(NAME)"
