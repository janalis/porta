#!/bin/sh
# The built application, driven from outside as the system drives it:
# smoke.sh <Porta.app>. Needs no accessibility rights; what does is reported, not required.
set -eu

app=$1
work=$(mktemp -d)
mkdir -p "$work/home" "$work/shop"
echo '{}' > "$work/shop/composer.json"
printf '<?php\n' > "$work/shop/a.php"
php_pattern="$app/Contents/Resources/runtime/php"
host_pattern="$app/Contents/MacOS/Porta"

cleanup() {
    pkill -f "$host_pattern" 2>/dev/null || true
}
trap cleanup EXIT

fail() {
    echo "smoke: $1" >&2
    exit 1
}

# Porta's own processes under this application, however many.
portas() {
    pgrep -f "$php_pattern" | wc -l | tr -d ' '
}

await() { # await <tries of half a second> <what> <command…>
    tries=$1
    what=$2
    shift 2
    until "$@"; do
        tries=$((tries - 1))
        [ "$tries" -gt 0 ] || fail "$what"
        sleep 0.5
    done
}

"$app/Contents/Resources/runtime/porta-cli" --version

open --env PORTA_HOME="$work/home" --env PORTA_CACHE_DIR="$work/cache" --env PORTA_TRUSTED_FOLDERS="$work" -a "$app" "$work/shop"
await 240 'the IDE did not come up' sh -c "grep -q shop '$work/home/.porta/recent-projects.json' 2>/dev/null"
echo 'smoke: the IDE is up'

# A file of the project, opened with the application, goes to that project's window.
open -a "$app" "$work/shop/a.php"
sleep 3
[ "$(portas)" = 1 ] || fail "a file of the project started another Porta ($(portas) running)"
echo 'smoke: a file of the project went to its window'

# Accessibility-driven checks need rights a runner may not grant: run where they are, reported where not.
porta_process='first process whose bundle identifier is "io.github.janalis.porta"'
titles=$(osascript -e "tell application \"System Events\" to get name of every window of ($porta_process)" 2>&1 || true)
case "$titles" in
    *'shop — Porta'*)
        echo "smoke: the window is named after its project: $titles"
        # The file opened above has the keys: an edit puts the dot in the close button, saving takes it away.
        osascript -e "tell application \"System Events\" to tell ($porta_process)
            set frontmost to true
            delay 0.5
            keystroke \"x\"
        end tell"
        await 20 'an edit did not mark the window as unsaved' sh -c "[ \"\$(osascript -e 'tell application \"System Events\" to tell (first process whose bundle identifier is \"io.github.janalis.porta\") to get value of attribute \"AXEdited\" of (first button of window 1 whose subrole is \"AXCloseButton\")')\" = true ]"
        echo 'smoke: an edit puts the dot in the close button'
        osascript -e "tell application \"System Events\" to tell ($porta_process) to keystroke \"s\" using command down"
        await 20 'saving did not clear the dot' sh -c "[ \"\$(osascript -e 'tell application \"System Events\" to tell (first process whose bundle identifier is \"io.github.janalis.porta\") to get value of attribute \"AXEdited\" of (first button of window 1 whose subrole is \"AXCloseButton\")')\" = false ]"
        echo 'smoke: saving clears it'
        ;;
    *)
        echo "smoke: System Events cannot read the windows here, so the title and the unsaved dot go unchecked: ${titles:-none listed}"
        ;;
esac

pkill -f "$host_pattern"
await 20 'the application did not end' sh -c "! pgrep -f '$host_pattern' > /dev/null"
await 40 'its Porta did not end with it' sh -c "! pgrep -f '$php_pattern' > /dev/null"

# A Porta that stops on its own leaves its window, to reopen or read the log, and the application stays.
# A copy of the application whose porta.phar only exits with 3 stands in for one that fails.
stopping="$work/Stopping.app"
ditto "$app" "$stopping"
printf '<?php exit(3);\n' > "$stopping/Contents/Resources/runtime/porta.phar"
open --env PORTA_HOME="$work/home" -a "$stopping" "$work/shop"
await 60 'the application did not start' sh -c "pgrep -f '$stopping/Contents/MacOS/Porta' > /dev/null"
sleep 8
pgrep -f "$stopping/Contents/MacOS/Porta" > /dev/null || fail 'the application quit when Porta stopped'
pkill -f "$stopping/Contents/MacOS/Porta"
echo 'smoke: a Porta that stopped left the application up'
