#!/bin/sh
# Assembles Porta.app: build-app.sh --php <static php> --phar <porta.phar> --version <x.y.z> --out <dir>
# Signs with $PORTA_SIGN_IDENTITY when set, ad hoc otherwise.
set -eu

while [ $# -gt 0 ]; do
    case $1 in
        --php) php=$2; shift 2 ;;
        --phar) phar=$2; shift 2 ;;
        --version) version=$2; shift 2 ;;
        --out) out=$2; shift 2 ;;
        *) echo "build-app: unknown argument $1" >&2; exit 2 ;;
    esac
done
: "${php:?--php is required}" "${phar:?--phar is required}" "${version:?--version is required}" "${out:?--out is required}"

here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
swift build --package-path "$here" -c release --product PortaDesktop
binary=$(swift build --package-path "$here" -c release --show-bin-path)/PortaDesktop

mkdir -p "$out"
app=$(CDPATH= cd -- "$out" && pwd -P)/Porta.app
case $app in
    */Porta.app) rm -rf "$app" ;;
    *) echo "build-app: refusing to replace $app" >&2; exit 2 ;;
esac
mkdir -p "$app/Contents/MacOS" "$app/Contents/Resources/runtime"
cp "$binary" "$app/Contents/MacOS/Porta"
sed "s/@VERSION@/$version/g" "$here/Resources/Info.plist" > "$app/Contents/Info.plist"

runtime=$app/Contents/Resources/runtime
cp "$php" "$runtime/php"
cp "$phar" "$runtime/porta.phar"
cp "$here/../runtime/porta-cli" "$runtime/porta-cli"
chmod 755 "$runtime/php" "$runtime/porta-cli"

iconset=$(mktemp -d)/Porta.iconset
mkdir -p "$iconset"
for size in 16 32 128 256 512; do
    sips -z "$size" "$size" "$here/../assets/icon.png" --out "$iconset/icon_${size}x${size}.png" > /dev/null
    double=$((size * 2))
    [ "$double" -le 1024 ] && sips -z "$double" "$double" "$here/../assets/icon.png" --out "$iconset/icon_${size}x${size}@2x.png" > /dev/null
done
iconutil -c icns "$iconset" -o "$app/Contents/Resources/Porta.icns"

identity=${PORTA_SIGN_IDENTITY:--}
# Notarization insists on a secure timestamp; an ad hoc signature cannot have one.
stamp=--timestamp
[ "$identity" != - ] || stamp=--timestamp=none
for code in "$runtime/php" "$app/Contents/MacOS/Porta"; do
    codesign --force --options runtime "$stamp" --sign "$identity" "$code"
done
codesign --force --options runtime "$stamp" --sign "$identity" "$app"
codesign --verify --deep --strict "$app"
echo "$app"
