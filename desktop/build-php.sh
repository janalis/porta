#!/bin/sh
# Builds the static PHP the desktop packages ship: build-php.sh <output file>
# No `event` extension: it takes away proc_open's PTY, which Porta's terminal needs.
set -eu

output=$1
version=8.6.0
extensions=ctype,dom,filter,gd,iconv,mbstring,openssl,pcntl,phar,posix,simplexml,sodium,tokenizer,xml,zlib
here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
work=${PORTA_SPC_DIR:-$(mktemp -d)}
mkdir -p "$work"
cd "$work"

# The builder is pinned and checked before it runs: it writes the PHP every package ships.
spc_version=2.8.5
case $(uname -s)-$(uname -m) in
    Darwin-arm64) asset=spc-macos-aarch64 sum=acf2f25d56d0cbf8e65aa82e5054fef555f7be7c5c38046c6e0819f266d83225 ;;
    Darwin-x86_64) asset=spc-macos-x86_64 sum=e8b798048f62ca4960764196543b60ae703f7174aa418824cf542aeec1d2cd6a ;;
    Linux-x86_64) asset=spc-linux-x86_64 sum=523ba4279c54c7a377156c0dd3a36adf92ee64b01e9a7f5e9e2ec084b8e458e5 ;;
    Linux-aarch64) asset=spc-linux-aarch64 sum=675a3840dcdc4ed041fe20eaa54310ce019a9984c1c03951df9ec66df5795213 ;;
    *) echo "build-php: unsupported platform" >&2; exit 2 ;;
esac
curl -fsSL -o spc.tar.gz "https://github.com/crazywhalecc/static-php-cli/releases/download/$spc_version/$asset.tar.gz"
printf '%s  spc.tar.gz\n' "$sum" | shasum -a 256 -c -
tar -xzf spc.tar.gz spc
chmod +x spc
./spc doctor --auto-fix
# Every source at its pinned version (php-sources.pins): left to itself, static-php-cli fetches
# each project's latest, and the lock refuses it the day upstream releases.
duplicates=$(awk '!/^#/ && NF { n = split($2, part, "/"); print part[n] }' "$here/php-sources.pins" | sort | uniq -d)
[ -z "$duplicates" ] || { echo "build-php: two pinned sources share a file name: $duplicates" >&2; exit 2; }
set --
while read -r name url; do
    case $name in '' | '#'*) continue ;; esac
    set -- "$@" "--custom-url=$name:$url"
done < "$here/php-sources.pins"
./spc download --with-php="$version" --for-extensions="$extensions" "$@"
"$here/verify-sources.sh" downloads "$here/php-sources.lock"
# No phpmicro patches: they serve the micro SAPI, never built here, and static-php-cli 2.8.5's
# fail to apply to PHP 8.6 (its 8.6 set is only on the unreleased v3 branch).
SPC_MICRO_PATCHES= ./spc build "$extensions" --build-cli
cp buildroot/bin/php "$output"
"$output" -n "$here/runtime/check-php.php"
