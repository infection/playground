PHP-CS-FIXER=./vendor/bin/php-cs-fixer
PHPSTAN=vendor/bin/phpstan
PHPSTAN_ARGS=analyse src tests -c phpstan.dist.neon

#style checks / static analysis
.PHONY: analyze cs-fix cs-check phpstan validate
analyze: cs-check phpstan validate tests

cs-fix: $(PHP-CS-FIXER)
	$(PHP-CS-FIXER) fix -v --allow-risky=yes

cs-check: $(PHP-CS-FIXER)
	$(PHP-CS-FIXER) fix -v --dry-run --stop-on-violation --allow-risky=yes

phpstan: prerequisites
	$(PHPSTAN) $(PHPSTAN_ARGS)

phpstan-update-baseline: prerequisites ## Runs PHPStan and update baseline
	$(PHPSTAN) $(PHPSTAN_ARGS) --generate-baseline

validate:
	composer validate --strict

tests: prerequisites
	vendor/bin/phpunit

app-reinstall: prerequisites
	bin/console doctrine:database:drop --force --if-exists
	bin/console doctrine:database:create
	bin/console doctrine:schema:update --force

# We need both vendor/autoload.php and composer.lock being up to date
.PHONY: prerequisites
prerequisites: vendor/autoload.php composer.lock