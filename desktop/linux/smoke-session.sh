#!/bin/sh
# One session of the application, unattended:
#   smoke-session.sh wayland <folder holding porta-desktop>  on a real Wayland compositor
#   smoke-session.sh menu <folder holding porta-desktop>     on a desktop that shows application menus (X11)
# Run under dbus-run-session; the menu mode under xvfb-run too.
set -eu

mode=$1
app=$2
work=$(mktemp -d)
mkdir -p "$work/home" "$work/shop" "$work/config/gtk-4.0" "$work/run"
chmod 700 "$work/run"
echo '{}' > "$work/shop/composer.json"
printf '[Settings]\ngtk-shell-shows-menubar=true\n' > "$work/config/gtk-4.0/settings.ini"
export XDG_RUNTIME_DIR="$work/run" XDG_CONFIG_HOME="$work/config"
export PORTA_HOME="$work/home" PORTA_CACHE_DIR="$work/cache" PORTA_TRUSTED_FOLDERS="$work"
export G_MESSAGES_DEBUG=porta-desktop

if [ wayland = "$mode" ]; then
    weston --backend=headless --socket=porta-smoke --idle-time=0 > "$work/weston.log" 2>&1 &
    compositor=$!
    trap 'kill "$compositor" 2>/dev/null || true' EXIT
    tries=0
    until [ -S "$work/run/porta-smoke" ]; do
        tries=$((tries + 1))
        [ "$tries" -lt 40 ] || { cat "$work/weston.log" >&2; echo 'smoke: weston did not start' >&2; exit 1; }
        sleep 0.25
    done
    export WAYLAND_DISPLAY=porta-smoke GDK_BACKEND=wayland
else
    # No settings daemon on this display: GTK takes the setting from settings.ini.
    export GDK_BACKEND=x11
    # xvfb-run returns before its server always accepts clients: wait until it does.
    tries=0
    until xdotool getdisplaygeometry > /dev/null 2>&1; do
        tries=$((tries + 1))
        [ "$tries" -lt 40 ] || { echo 'smoke: the virtual display never came up' >&2; exit 1; }
        sleep 0.25
    done
fi

"$app/porta-desktop" "$work/shop" &
pid=$!

tries=0
until grep -q shop "$work/home/.porta/recent-projects.json" 2>/dev/null; do
    tries=$((tries + 1))
    [ "$tries" -lt 240 ] || { echo "smoke: the IDE did not come up ($mode)" >&2; kill "$pid"; exit 1; }
    sleep 0.5
done
echo "smoke: the IDE is up ($mode)"

if [ menu = "$mode" ]; then
php=$(pgrep -P "$pid" php | head -n 1)
tr '\0' '\n' < "/proc/$php/environ" | grep -qx 'PORTA_DESKTOP_MENU=system' || { echo 'smoke: Porta was not told the desktop shows its menus' >&2; kill "$pid"; exit 1; }

tries=0
until gdbus call --session --dest io.github.janalis.porta --object-path /io/github/janalis/porta --method org.gtk.Actions.List 2>/dev/null | grep -q 'porta-0-'; do
    tries=$((tries + 1))
    if [ "$tries" -ge 40 ]; then
        echo 'smoke: the menus never reached the desktop' >&2
        gdbus call --session --dest io.github.janalis.porta --object-path /io/github/janalis/porta --method org.gtk.Actions.List >&2 || true
        gdbus introspect --session --dest io.github.janalis.porta --object-path /io/github/janalis/porta --recurse 2>&1 | grep -E 'node|interface' | head -20 >&2 || true
        kill "$pid"
        exit 1
    fi
    sleep 0.5
done
echo 'smoke: the menus reached the desktop'
fi

kill "$pid"
tries=0
while kill -0 "$pid" 2>/dev/null; do
    tries=$((tries + 1))
    [ "$tries" -lt 40 ] || { echo 'smoke: the application did not end' >&2; kill -9 "$pid"; exit 1; }
    sleep 0.25
done
echo 'smoke: the application ended'
