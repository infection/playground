SHELL = bash

PHP-CS-FIXER=vendor/bin/php-cs-fixer
PHPSTAN=vendor/bin/phpstan
PHPSTAN_ARGS=analyse -c phpstan.dist.neon --memory-limit=1G
INFECTION=php -d memory_limit=1G vendor/bin/infection
INFECTION_THREADS ?= 4

# Compare changed lines against the PR base branch, or master for local runs.
BASE_REF ?= origin/master

# Extra arguments for a one-off run: make infection ARGS="--filter=src/Html"
ARGS ?=

# `docker compose exec` allocates a TTY, which fails where there is none (CI).
CI ?=
DOCKER_PHP=docker compose exec $(if $(CI),-T,) php

#style checks / static analysis
.PHONY: analyze cs-fix cs-check phpstan validate tests schema-validate
analyze: cs-check phpstan validate schema-validate tests

cs-fix: prerequisites
	$(PHP-CS-FIXER) fix -v --allow-risky=yes

cs-check: prerequisites
	$(PHP-CS-FIXER) fix -v --dry-run --stop-on-violation --allow-risky=yes

phpstan: prerequisites
	$(PHPSTAN) $(PHPSTAN_ARGS)

phpstan-update-baseline: prerequisites ## Runs PHPStan and update baseline
	$(PHPSTAN) $(PHPSTAN_ARGS) --generate-baseline

validate:
	composer validate --strict

schema-validate: prerequisites
	bin/console doctrine:schema:validate

tests: prerequisites
	vendor/bin/phpunit

# Runs mutation tests against src/
.PHONY: infection infection-diff db-test-prepare
infection: db-test-prepare
	$(INFECTION) --no-interaction --threads=$(INFECTION_THREADS) --with-uncovered $(ARGS)

# Runs the CI mutation check on lines changed from $(BASE_REF) (a ref or a commit SHA)
infection-diff: db-test-prepare
	$(INFECTION) --no-interaction --threads=$(INFECTION_THREADS) --with-uncovered --git-diff-lines --git-diff-base=$(BASE_REF) --ignore-msi-with-no-mutations --max-timeouts=0 --min-msi=100 $(ARGS)

# Infection runs the tests of each thread with TEST_TOKEN=1..N, which config/packages/doctrine.yaml
# appends to the test database name: every thread needs its own app_test<N> database.
db-test-prepare: prerequisites
	@pids=(); for token in '' $$(seq 1 $(INFECTION_THREADS)); do \
		( TEST_TOKEN=$$token bin/console doctrine:database:create --if-not-exists --env=test && \
		  TEST_TOKEN=$$token bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test ) & \
		pids+=($$!); \
	done; \
	result=0; for pid in "$${pids[@]}"; do wait "$$pid" || result=1; done; exit "$$result"

# Recreates the dev and test databases from the migrations
.PHONY: app-reinstall
app-reinstall: prerequisites
	bin/console doctrine:database:drop --force --if-exists
	bin/console doctrine:database:create
	bin/console doctrine:migrations:migrate --no-interaction
	bin/console doctrine:database:drop --force --if-exists --env=test
	bin/console doctrine:database:create --env=test
	bin/console doctrine:migrations:migrate --no-interaction --env=test

# Rebuilds var/tailwind/app.built.css on every change of the templates and assets
.PHONY: tailwind-watch
tailwind-watch: prerequisites
	bin/console tailwind:build --watch

# The same targets, run from the host in the php container: `make docker-analyze`
.PHONY: docker-%
docker-%:
	$(DOCKER_PHP) make $* INFECTION_THREADS=$(INFECTION_THREADS) BASE_REF='$(BASE_REF)' ARGS='$(ARGS)'

# We need both vendor/autoload.php and composer.lock being up to date
.PHONY: prerequisites
prerequisites: vendor/autoload.php composer.lock
