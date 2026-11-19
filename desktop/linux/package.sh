#!/bin/sh
# Builds the Linux packages: package.sh --php <static php> --phar <porta.phar> --version <x.y.z> --out <dir>
set -eu

while [ $# -gt 0 ]; do
    case $1 in
        --php) php=$2; shift 2 ;;
        --phar) phar=$2; shift 2 ;;
        --version) version=$2; shift 2 ;;
        --out) out=$2; shift 2 ;;
        *) echo "package: unknown argument $1" >&2; exit 2 ;;
    esac
done
: "${php:?--php is required}" "${phar:?--phar is required}" "${version:?--version is required}" "${out:?--out is required}"

here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
build=$(mktemp -d)
stage=$build/stage
meson setup "$build/meson" "$here" --buildtype=release --prefix=/usr
meson compile -C "$build/meson"

mkdir -p "$stage/lib/porta-desktop/runtime" "$stage/share/applications" "$stage/share/icons/hicolor/512x512/apps" "$out"
cp "$build/meson/porta-desktop" "$stage/lib/porta-desktop/"
cp "$php" "$stage/lib/porta-desktop/runtime/php"
cp "$phar" "$stage/lib/porta-desktop/runtime/porta.phar"
cp "$here/../runtime/porta-cli" "$stage/lib/porta-desktop/runtime/porta-cli"
chmod 755 "$stage/lib/porta-desktop/runtime/php" "$stage/lib/porta-desktop/runtime/porta-cli"
cp "$here/data/io.github.janalis.porta.desktop" "$stage/share/applications/"
cp "$here/../assets/icon-512.png" "$stage/share/icons/hicolor/512x512/apps/io.github.janalis.porta.png"
cp "$here/data/install.sh" "$stage/install.sh"

case $(uname -m) in
    x86_64) arch=amd64 ;;
    aarch64) arch=arm64 ;;
    *) arch=$(uname -m) ;;
esac
# Which release asset each package is: `porta --self-update` installs the same kind again.
marker=$stage/lib/porta-desktop/runtime/desktop-package
printf 'porta-desktop-linux-%s.tar.gz\n' "$(uname -m)" > "$marker"
tar -czf "$out/porta-desktop-linux-$(uname -m).tar.gz" -C "$stage" .
rm "$marker"
# nfpm expands the environment in some fields only, and never in a file's source.
for format in deb rpm; do
    printf 'porta-desktop-linux-%s.%s\n' "$(uname -m)" "$format" > "$build/marker-$format"
    sed -e "s|\${PORTA_ARCH}|$arch|g" -e "s|\${PORTA_VERSION}|$version|g" -e "s|\${PORTA_STAGE}|$stage|g" -e "s|\${PORTA_MARKER}|$build/marker-$format|g" "$here/data/nfpm.yaml" > "$build/nfpm-$format.yaml"
    # Version-free names: releases/latest/download/<name> then always means the newest.
    nfpm package --config "$build/nfpm-$format.yaml" --packager "$format" --target "$out/porta-desktop-linux-$(uname -m).$format"
done
