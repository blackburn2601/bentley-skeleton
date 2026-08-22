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
else
  RUN_BACKEND = cd backend &&
endif

.PHONY: help up down restart sh logs ps migrate migrate-down fixtures db-reset \
        test test-unit test-integration test-functional coverage \
        lint fix stan arch proof docs e2e front-lint front-test front-build \
        adr endpoint service \
        check ci new-project keys hooks

help: ## Show this help
	@echo "bentley-skeleton — available targets:"
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-18s\033[0m %s\n",$$1,$$2}'

## ---------------------------------------------------------------- stack

up: ## Build if needed and start the dev stack
	$(COMPOSE) up -d --build --wait
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

test: ## Run the whole backend test suite
	$(RUN_BACKEND) $(PHP) vendor/bin/phpunit

test-unit: ## Unit tests only
	$(RUN_BACKEND) $(PHP) vendor/bin/phpunit --testsuite=unit

test-integration: ## Integration tests only (needs Postgres + Redis)
	$(RUN_BACKEND) $(PHP) vendor/bin/phpunit --testsuite=integration

test-functional: ## Functional tests only
	$(RUN_BACKEND) $(PHP) vendor/bin/phpunit --testsuite=functional

coverage: ## Run tests with the coverage gate (Acl+Account >= 90%, global >= 80%)
	$(RUN_BACKEND) XDEBUG_MODE=coverage $(PHP) vendor/bin/phpunit --coverage-text --coverage-clover=var/coverage/clover.xml

## ---------------------------------------------------------------- quality

lint: ## Check formatting and config validity without changing anything
	$(RUN_BACKEND) $(COMPOSER) validate --strict
	$(RUN_BACKEND) $(PHP) vendor/bin/php-cs-fixer check --diff
	$(RUN_BACKEND) $(PHP) bin/console lint:yaml config
	$(RUN_BACKEND) $(PHP) bin/console lint:container
	$(RUN_BACKEND) $(PHP) bin/console doctrine:schema:validate --skip-sync

fix: ## Apply every safe automatic fix (cs-fixer, then Rector)
	$(RUN_BACKEND) $(PHP) vendor/bin/php-cs-fixer fix
	$(RUN_BACKEND) $(PHP) vendor/bin/rector process

stan: ## PHPStan at max level, including the custom architecture rules
	$(RUN_BACKEND) $(PHP) vendor/bin/phpstan analyse --memory-limit=1G

arch: ## Enforce the architecture contract (deptrac + phpat + PHPMD + arch tests)
	@echo "--> layering contract"
	$(RUN_BACKEND) $(PHP) vendor/bin/deptrac analyse --config-file=deptrac.yaml
	@echo "--> bounded-context contract"
	$(RUN_BACKEND) $(PHP) vendor/bin/deptrac analyse --config-file=deptrac-context.yaml
	@echo "--> size and complexity limits"
	$(RUN_BACKEND) $(PHPMD) src ansi phpmd.xml --exclude 'src/Maker/skeleton'
	$(RUN_BACKEND) $(PHPMD) src/Api ansi phpmd-api.xml
	@echo "--> architecture tests"
	$(RUN_BACKEND) $(PHP) vendor/bin/phpunit --testsuite=architecture

proof: ## Prove the architecture rules actually fail on deliberate violations
	./bin/strictness-proof

docs: ## Regenerate the generated inventories and fail if they drifted
	$(RUN_BACKEND) $(PHP) bin/console app:docs:generate
	@git diff --exit-code -- docs/ \
	  || { echo ""; echo "ERROR: generated docs are stale. Commit the regenerated files above."; exit 1; }

## ---------------------------------------------------------------- frontend

front-lint: ## ESLint + vue-tsc on the SPA
	npm --prefix frontend run lint
	npm --prefix frontend run typecheck

front-test: ## Vitest unit tests
	npm --prefix frontend run test

front-build: ## Production SPA build
	npm --prefix frontend run build

e2e: ## Playwright end-to-end suite against the running stack
	npm --prefix frontend run e2e

## ---------------------------------------------------------------- aggregate

check: lint stan arch test front-lint front-test ## Everything CI runs, except e2e

ci: check docs e2e ## Literally everything

## ---------------------------------------------------------------- project setup

adr: ## Record an architecture decision: make adr TITLE="Use X instead of Y"
	$(RUN_BACKEND) $(PHP) bin/console make:adr $(if $(TITLE),"$(TITLE)",)

endpoint: ## Generate a conforming endpoint slice (see docs/cookbook/add-endpoint.md)
	$(RUN_BACKEND) $(PHP) bin/console make:api-endpoint

service: ## Generate a single-topic Application service and its test
	$(RUN_BACKEND) $(PHP) bin/console make:service

keys: ## Generate the JWT keypair (idempotent; never commit the result)
	$(RUN_BACKEND) $(PHP) bin/console lexik:jwt:generate-keypair --skip-if-exists

hooks: ## Install the git hooks (cs-fixer/phpstan/eslint on staged files, ADR reminder)
	git config core.hooksPath .githooks
	@echo "git hooks active: .githooks"

new-project: ## Reseed this template as a new project: make new-project NAME=acme-api
	@test -n "$(NAME)" || { echo "usage: make new-project NAME=your-project"; exit 1; }
	./bin/new-project "$(NAME)"
