PHP-CS-FIXER=vendor/bin/php-cs-fixer
PHPSTAN=vendor/bin/phpstan
PHPSTAN_ARGS=analyse -c phpstan.dist.neon --memory-limit=1G

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
	$(DOCKER_PHP) make $*

# We need both vendor/autoload.php and composer.lock being up to date
.PHONY: prerequisites
prerequisites: vendor/autoload.php composer.lock
