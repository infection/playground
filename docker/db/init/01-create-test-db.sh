#!/bin/sh
# Creates the test database alongside MYSQL_DATABASE and grants the app user rights on it.
#
# The mysql image grants MYSQL_USER rights on MYSQL_DATABASE only, so `doctrine:database:create
# --env=test` fails with "1044 Access denied ... to database 'app_test'". Symfony and
# dunglas/symfony-docker both document only the PostgreSQL path, where the app user is a
# superuser; docker-entrypoint-initdb.d is the mysql image's supported way to add a schema.
#
# The grant escapes the underscore (so it is not a wildcard) and ends in % so it also covers the
# app_test1, app_test2... databases that doctrine's dbname_suffix + TEST_TOKEN create in parallel
# test runs.
set -e

DB="${MYSQL_DATABASE:-app}_test"

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<EOSQL
CREATE DATABASE IF NOT EXISTS \`${DB}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE:-app}\_test%\`.* TO '${MYSQL_USER:-app}'@'%';
FLUSH PRIVILEGES;
EOSQL

echo "init: created ${DB} and granted ${MYSQL_USER:-app} rights on ${MYSQL_DATABASE:-app}_test%"
