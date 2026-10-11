#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then

	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
			# Upstream bails out here when the console exits 255, treating it as unrecoverable.
			# On MySQL *every* connection failure exits 255 (SQLSTATE 2002 surfaces as an uncaught
			# exception, which Application::run reports as 255), so the 60 attempts below never
			# happen and the container dies on its first try instead of waiting. On PostgreSQL the
			# same failure exits 1, which is why upstream never saw it. See
			# https://github.com/dunglas/symfony-docker/issues/650 -- still open.
			# Decide from the error text instead: keep waiting while the server is merely not up
			# yet, bail immediately on anything else (bad credentials, unknown database, bad DSN).
			if ! echo "$DATABASE_ERROR" | grep -qE '2002|2003|Connection refused|getaddrinfo|server has gone away'; then
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		if find ./migrations -iname '*.php' -print -quit | grep --quiet .; then
			php bin/console doctrine:migrations:migrate --no-interaction
		fi
	fi

	# The prod image ships the CSS built by the Dockerfile; elsewhere build it once so pages render.
	# Run `make tailwind-watch` to rebuild it on every template change.
	if [ "$APP_ENV" != 'prod' ] && [ ! -f var/tailwind/app.built.css ]; then
		php bin/console tailwind:build
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
