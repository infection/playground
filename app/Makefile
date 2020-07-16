PHP-CS-FIXER=./php-cs-fixer-v2.phar
PHP-CS-FIXER_URL="https://cs.sensiolabs.org/download/php-cs-fixer-v2.phar"
PHPSTAN=vendor/bin/phpstan

#Non phony targets for phars etc.
vendor: composer.json composer.lock
	composer install

./php-cs-fixer-v2.phar:
	wget $(PHP-CS-FIXER_URL)
	chmod a+x ./php-cs-fixer-v2.phar

#style checks / static analysis
.PHONY: analyze cs-fix cs-check phpstan validate
analyze: cs-check phpstan validate

cs-fix: $(PHP-CS-FIXER)
	$(PHP-CS-FIXER) fix -v --allow-risky=yes

cs-check: $(PHP-CS-FIXER)
	$(PHP-CS-FIXER) fix -v --dry-run --stop-on-violation --allow-risky=yes

phpstan: vendor
	$(PHPSTAN) analyse src tests -c phpstan.neon --no-interaction

validate:
	composer validate --strict

app-reinstall: vendor
	bin/console doctrine:database:drop --force --if-exists
	bin/console doctrine:database:create
	bin/console doctrine:schema:update --force
