#!/usr/bin/env bash
# Installs WordPress core and the core PHPUnit test library.
# Usage: install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host[:port]> <wp-version>
#   wp-version: 6.5 | latest | an exact release such as 6.8.3
set -euo pipefail

DB_NAME=${1:?database name required}
DB_USER=${2:?database user required}
DB_PASS=${3-}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

TMP=${TMPDIR:-/tmp}
TMP=${TMP%/}
WP_TESTS_DIR=${WP_TESTS_DIR:-$TMP/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-$TMP/wordpress}

if [[ "$WP_VERSION" == "latest" ]]; then
  WP_VERSION=$(curl -fsSL https://api.wordpress.org/core/version-check/1.7/ \
    | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["offers"][0]["current"];')
fi
# wordpress-develop keeps one branch per release line: 6.5.3 -> 6.5
WP_BRANCH=$(echo "$WP_VERSION" | cut -d. -f1,2)
echo "WordPress $WP_VERSION (test library branch $WP_BRANCH)"

# 1. WordPress core (the built release package)
rm -rf "$WP_CORE_DIR" "$TMP/wp-extract"
mkdir -p "$WP_CORE_DIR" "$TMP/wp-extract"
curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.zip" -o "$TMP/wordpress.zip"
unzip -q "$TMP/wordpress.zip" -d "$TMP/wp-extract"
cp -R "$TMP/wp-extract/wordpress/." "$WP_CORE_DIR/"
rm -rf "$TMP/wp-extract" "$TMP/wordpress.zip"

# 2. Core test library from wordpress-develop
rm -rf "$WP_TESTS_DIR" "$TMP/wordpress-develop"
git clone --quiet --depth 1 --branch "$WP_BRANCH" --filter=blob:none --sparse \
  https://github.com/WordPress/wordpress-develop.git "$TMP/wordpress-develop"
git -C "$TMP/wordpress-develop" sparse-checkout set tests/phpunit/includes tests/phpunit/data
mkdir -p "$WP_TESTS_DIR"
cp -R "$TMP/wordpress-develop/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
cp -R "$TMP/wordpress-develop/tests/phpunit/data" "$WP_TESTS_DIR/data"
cp "$TMP/wordpress-develop/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
rm -rf "$TMP/wordpress-develop"

# 3. Test configuration (portable in-place edit)
CFG="$WP_TESTS_DIR/wp-tests-config.php"
set_define() {
  sed -E "s#^define\( '$1',.*#define( '$1', '$2' );#" "$CFG" > "$CFG.tmp" && mv "$CFG.tmp" "$CFG"
}
set_define ABSPATH "$WP_CORE_DIR/"
set_define DB_NAME "$DB_NAME"
set_define DB_USER "$DB_USER"
set_define DB_PASSWORD "$DB_PASS"
set_define DB_HOST "$DB_HOST"
set_define DB_CHARSET utf8mb4

# 4. Test database, utf8mb4 so Devanagari round-trips
HOST=${DB_HOST%%:*}
PORT=${DB_HOST##*:}
[[ "$PORT" == "$DB_HOST" ]] && PORT=3306
MYSQL_PWD="$DB_PASS" mysql --user="$DB_USER" --host="$HOST" --port="$PORT" --protocol=tcp \
  -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"

echo "Installed: core $WP_CORE_DIR, test library $WP_TESTS_DIR"
