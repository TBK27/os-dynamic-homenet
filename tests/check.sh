#!/bin/sh
# Syntax-check every PHP/XML file and run the unit tests. Needs php (and optionally xmllint).
set -e
cd "$(dirname "$0")/.."

fail=0
for f in $(find security tests -name '*.php' -o -name '*.inc'); do
    php -l "$f" >/dev/null || fail=1
done

if command -v xmllint >/dev/null 2>&1; then
    for f in $(find security -name '*.xml'); do
        xmllint --noout "$f" || fail=1
    done
else
    echo "xmllint not found, skipping XML well-formedness check"
fi

[ "$fail" -eq 0 ] || { echo "syntax check failed"; exit 1; }
echo "syntax OK"
php tests/run_tests.php
