#!/bin/sh
# Installs the tarball for this user alone: no root, nothing outside ~/.local.
set -eu
here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
data=${XDG_DATA_HOME:-$HOME/.local/share}
target=$data/porta-desktop

rm -rf "$target"
mkdir -p "$target" "$HOME/.local/bin" "$data/applications" "$data/icons/hicolor/512x512/apps"
cp -R "$here/lib/porta-desktop/." "$target/"
ln -sf "$target/porta-desktop" "$HOME/.local/bin/porta-desktop"
sed "s|^Exec=porta-desktop|Exec=$target/porta-desktop|" "$here/share/applications/io.github.janalis.porta.desktop" > "$data/applications/io.github.janalis.porta.desktop"
cp "$here/share/icons/hicolor/512x512/apps/io.github.janalis.porta.png" "$data/icons/hicolor/512x512/apps/"
command -v update-desktop-database > /dev/null && update-desktop-database "$data/applications" || true
echo "Porta is in your applications menu. To remove it: rm -rf '$target' and its .desktop file."
