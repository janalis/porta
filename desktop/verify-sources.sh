#!/bin/sh
# Holds what static-php-cli downloaded to the digests committed in php-sources.lock.
# verify-sources.sh <downloads folder> <lock file>; PORTA_SOURCES_RECORD=<file> writes them instead.
set -eu

downloads=$1
lock=$2
export LC_ALL=C

digest() {
    if [ -d "$1" ]; then
        (cd "$1" && find . -type f ! -path './.git/*' | sort | while IFS= read -r file; do shasum -a 256 "$file"; done) | shasum -a 256 | cut -d' ' -f1
    else
        shasum -a 256 "$1" | cut -d' ' -f1
    fi
}

actual=$(mktemp)
for entry in "$downloads"/*; do
    printf '%s  %s\n' "$(digest "$entry")" "$(basename "$entry")" >> "$actual"
done
sort -k2 "$actual" -o "$actual"

if [ -n "${PORTA_SOURCES_RECORD:-}" ]; then
    cp "$actual" "$PORTA_SOURCES_RECORD"
    echo "verify-sources: recorded $(wc -l < "$actual") sources"
    exit 0
fi

unknown=$(grep -vxFf "$lock" "$actual" || true)
if [ -n "$unknown" ]; then
    echo 'verify-sources: not what php-sources.lock pinned:' >&2
    printf '%s\n' "$unknown" >&2
    exit 1
fi
echo "verify-sources: $(wc -l < "$actual") sources match the lock"
