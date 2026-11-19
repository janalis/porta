#!/bin/sh
# Builds the static PHP the desktop packages ship: build-php.sh <output file>
# No `event` extension: it takes away proc_open's PTY, which Porta's terminal needs.
set -eu

output=$1
version=8.5
extensions=ctype,dom,filter,gd,iconv,mbstring,openssl,pcntl,phar,posix,simplexml,sodium,tokenizer,xml,zlib
here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
work=${PORTA_SPC_DIR:-$(mktemp -d)}
mkdir -p "$work"
cd "$work"

case $(uname -s)-$(uname -m) in
    Darwin-arm64) asset=spc-macos-aarch64 ;;
    Darwin-x86_64) asset=spc-macos-x86_64 ;;
    Linux-x86_64) asset=spc-linux-x86_64 ;;
    Linux-aarch64) asset=spc-linux-aarch64 ;;
    *) echo "build-php: unsupported platform" >&2; exit 2 ;;
esac
[ -x ./spc ] || { curl -fsSL -o spc "https://dl.static-php.dev/static-php-cli/spc-bin/nightly/$asset"; chmod +x spc; }
./spc doctor --auto-fix
./spc download --with-php="$version" --for-extensions="$extensions" --prefer-pre-built
./spc build "$extensions" --build-cli
cp buildroot/bin/php "$output"
"$output" -n "$here/runtime/check-php.php"
