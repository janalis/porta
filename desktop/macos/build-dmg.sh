#!/bin/sh
# Packs Porta.app into the disk image users open: build-dmg.sh <Porta.app> <output .dmg>
# The window shows the application, an arrow, and the Applications folder to drop it on.
set -eu
here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
python3 -m dmgbuild -s "$here/dmg-settings.py" -D "app=$1" -D "background=$here/Resources/dmg-background.tiff" Porta "$2"
