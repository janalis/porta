#!/bin/sh
# Holds what static-php-cli downloaded to the digests committed in php-sources.lock.
#
#   verify-sources.sh <downloads folder> <lock file>
#
# The sources are pinned in php-sources.pins. To renew the lock after changing what is
# built, or a pin, on each platform:
#   PORTA_SOURCES_RECORD=<file> desktop/build-php.sh <output>
# then merge the files into php-sources.lock and review the difference.
set -eu

downloads=$1
lock=$2
export LC_ALL=C

refuse() {
    echo "verify-sources: $1" >&2
    exit 1
}

# Every entry counts: its kind, whether it is executable, and its content or link target.
describe() {
    root=$(CDPATH= cd -- "$1" && pwd -P)
    list=$(mktemp)
    (cd "$root" && find . ! -path './.git' ! -path './.git/*' ! -type d | sort) > "$list"
    while IFS= read -r file; do
        path=$root/${file#./}
        if [ -L "$path" ]; then
            target=$(readlink "$path")
            case $target in
                /*) refuse "$file links outside its source: $target" ;;
            esac
            parent=$(dirname "$path")/$(dirname "$target")
            if [ -d "$parent" ]; then
                case $(CDPATH= cd -- "$parent" && pwd -P)/ in
                    "$root"/*) ;;
                    *) refuse "$file links outside its source: $target" ;;
                esac
            fi
            printf 'link %s %s\n' "$file" "$target"
        elif [ -f "$path" ]; then
            mode=-
            [ ! -x "$path" ] || mode=x
            printf 'file %s %s %s\n' "$mode" "$file" "$(shasum -a 256 "$path" | cut -d' ' -f1)"
        else
            refuse "$file is neither a file, a folder nor a link"
        fi
    done < "$list"
    rm -f "$list"
}

digest() {
    if [ -d "$1" ] && [ ! -L "$1" ]; then
        description=$(mktemp)
        describe "$1" > "$description"
        shasum -a 256 "$description" | cut -d' ' -f1
        rm -f "$description"
    elif [ -f "$1" ] && [ ! -L "$1" ]; then
        shasum -a 256 "$1" | cut -d' ' -f1
    else
        refuse "$1 is neither a file nor a folder"
    fi
}

actual=$(mktemp)
for entry in "$downloads"/*; do
    sum=$(digest "$entry")
    printf '%s  %s\n' "$sum" "$(basename "$entry")" >> "$actual"
done
sort -k2 "$actual" -o "$actual"

if [ -n "${PORTA_SOURCES_RECORD:-}" ]; then
    [ -z "${PORTA_RELEASE:-}" ] || refuse 'a release verifies its sources, it never records them'
    cp "$actual" "$PORTA_SOURCES_RECORD"
    echo "verify-sources: recorded $(wc -l < "$actual" | tr -d ' ') sources"
    exit 0
fi

[ -s "$lock" ] || refuse "$lock is empty: nothing to verify against"
unknown=$(grep -vxFf "$lock" "$actual" || true)
if [ -n "$unknown" ]; then
    echo 'verify-sources: not what php-sources.lock pinned:' >&2
    printf '%s\n' "$unknown" >&2
    exit 1
fi
echo "verify-sources: $(wc -l < "$actual" | tr -d ' ') sources match the lock"
