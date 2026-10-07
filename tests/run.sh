#!/bin/sh
# Runs the PHP test suite inside a throwaway Nextcloud container.
#
#   docker run --rm -v "$PWD":/app:ro nextcloud:33-apache sh /app/tests/run.sh
#
# The container installs Nextcloud on SQLite in its own file system, so nothing
# outside the container is touched.
set -eu
NC=/usr/src/nextcloud
PHPUNIT_VERSION="${PHPUNIT_VERSION:-11.5.46}"

mkdir -p "$NC/apps/ocr_search" "$NC/data"
cd /app
tar -cf - --exclude=./node_modules --exclude=./.git --exclude=./server --exclude=./build . | tar -xf - -C "$NC/apps/ocr_search"
chown -R www-data:www-data "$NC/config" "$NC/data" "$NC/apps"
curl -fsSL -o /usr/local/bin/phpunit "https://phar.phpunit.de/phpunit-${PHPUNIT_VERSION}.phar"
chmod +x /usr/local/bin/phpunit

run() { su www-data -s /bin/sh -c "cd $NC && $*"; }
run "php occ maintenance:install --database sqlite --admin-user admin --admin-pass 'Test-admin-9351' --data-dir $NC/data" >/dev/null
run "php occ app:enable ocr_search"
run "php occ ocr_search:status"
run "find apps/ocr_search/appinfo apps/ocr_search/lib apps/ocr_search/templates apps/ocr_search/tests -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors' || true
run "php /usr/local/bin/phpunit -c apps/ocr_search/phpunit.xml $*"
