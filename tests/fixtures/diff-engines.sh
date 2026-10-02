#!/usr/bin/env bash
# Prueba diferencial: compara la salida de los DOS motores entre sí, no contra
# las expectativas escritas a mano.
#
# Es la comprobación más fuerte del proyecto: aunque un caso estuviera mal
# esperado, esto detectaría que PHP y JS no coinciden.
set -e
cd "$(dirname "$0")/../.."

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

php  tests/fixtures/run-php.php --dump > "$tmp/php.json"
node tests/fixtures/run-js.mjs  --dump > "$tmp/js.json"

# Sin finales de línea de Windows: PHP y Node no siempre coinciden en eso.
tr -d '\r' < "$tmp/php.json" > "$tmp/php.txt"
tr -d '\r' < "$tmp/js.json"  > "$tmp/js.txt"

if diff -u "$tmp/php.txt" "$tmp/js.txt" > "$tmp/diff.txt"; then
    echo "DIFERENCIAL — los dos motores producen resultados idénticos ($(grep -c '' "$tmp/php.txt") casos)"
else
    echo "DIFERENCIAL — ¡LOS MOTORES DIVERGEN!"
    head -40 "$tmp/diff.txt"
    exit 1
fi
