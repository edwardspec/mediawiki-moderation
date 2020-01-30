#!/bin/bash -xe

# Clone MediaWiki database: one database per each parallel thread of "phpunit".
# (for parallel testing via Fastest)

ORIGINAL_DB_NAME=traviswiki

# Suffix of cloned DB must be same as in ModerationSettings.php
CLONED_DB_NAME="${ORIGINAL_DB_NAME}_thread${ENV_TEST_CHANNEL}"

# Clone the database (including the initial data, if any).
mysql -e "CREATE DATABASE ${CLONED_DB_NAME}"
mysqldump "${ORIGINAL_DB_NAME}" | mysql -D "${CLONED_DB_NAME}"

