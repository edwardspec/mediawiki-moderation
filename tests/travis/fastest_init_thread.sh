#!/bin/bash -xe
###############################################################################
# Clone MediaWiki database: one database per each parallel thread of "phpunit".
# (for parallel testing via Fastest)
###############################################################################
# The following environment variables are provided by GitHub Actions:
# $DBNAME - typically "traviswiki"
# $DBTYPE - either "mysql" or "postgres"
###############################################################################

ORIGINAL_DB_NAME="${DBNAME}"

# Suffix of cloned DB must be same as in ModerationSettings.php
CLONED_DB_NAME="${ORIGINAL_DB_NAME}_thread${ENV_TEST_CHANNEL}"

# Clone the database (including the initial data, if any).
if [ "$DBTYPE" = "mysql" ]; then
	mysql -e "CREATE DATABASE ${CLONED_DB_NAME}"
	mysqldump "${ORIGINAL_DB_NAME}" | mysql -D "${CLONED_DB_NAME}"
else if [ "$DBTYPE" = "postgres" ]; then
	echo "CREATE DATABASE ${CLONED_DB_NAME} TEMPLATE ${ORIGINAL_DB_NAME};" | psql -h 127.0.0.1 -U root "${ORIGINAL_DB_NAME}"
fi; fi
