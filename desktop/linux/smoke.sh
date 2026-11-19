#!/bin/sh
# Starts the packaged application on a virtual display and drives one session:
# smoke.sh <folder holding porta-desktop and runtime/>. Run under xvfb-run and dbus-run-session.
set -eu

app=$1
work=$(mktemp -d)
mkdir -p "$work/home" "$work/shop"
echo '{}' > "$work/shop/composer.json"
export PORTA_HOME="$work/home" PORTA_CACHE_DIR="$work/cache" GDK_BACKEND=x11

"$app/porta-desktop" "$work/shop" &
pid=$!

await_window() {
    tries=0
    until xdotool search --onlyvisible --name "$1" > /dev/null 2>&1; do
        tries=$((tries + 1))
        [ "$tries" -lt 120 ] || { echo "smoke: no window named '$1'" >&2; kill "$pid" 2>/dev/null; exit 1; }
        sleep 0.5
    done
}

# No window manager here: focus the one window that is on screen, then type.
press() {
    window=$(xdotool search --onlyvisible --name "$1" | tail -n 1)
    xdotool windowfocus "$window"
    sleep 0.5
    xdotool key "$2"
}

await_window '^Porta$'
sleep 3
# The trust prompt: Enter answers no, and the project opens restricted.
press '^Porta$' Return
await_window '^shop — Porta$'
echo 'smoke: the IDE is up in its window'

# A second launch of the same project must not open a second window.
"$app/porta-desktop" "$work/shop"
sleep 2
[ "$(pgrep -c -P "$pid" php || true)" -le 1 ] || { echo 'smoke: a second Porta was started' >&2; exit 1; }

press '^shop — Porta$' ctrl+q
tries=0
while kill -0 "$pid" 2>/dev/null; do
    tries=$((tries + 1))
    [ "$tries" -lt 40 ] || { echo 'smoke: the application did not exit' >&2; kill "$pid"; exit 1; }
    sleep 0.5
done
echo 'smoke: the application closed with Porta'
