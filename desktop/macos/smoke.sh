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
# A first launch shows what Porta may ask macOS for, once.
introduced=$(defaults read io.github.janalis.porta permissionsIntroduced 2>/dev/null || echo 0)

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
        sheet=$(osascript -e "tell application \"System Events\" to tell ($porta_process) to get exists sheet 1 of window 1")
        if [ "$introduced" = 0 ]; then
            [ "$sheet" = true ] || fail 'a first launch did not show the permissions sheet'
            osascript -e "tell application \"System Events\" to tell ($porta_process) to click button \"Not Now\" of sheet 1 of window 1"
            await 20 'Not Now did not close the permissions sheet' sh -c "[ \"\$(osascript -e 'tell application \"System Events\" to tell (first process whose bundle identifier is \"io.github.janalis.porta\") to get exists sheet 1 of window 1')\" = false ]"
            echo 'smoke: a first launch shows the permissions sheet, and Not Now closes it'
        fi
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

        # Porta → Permissions… shows the sheet again; Continue closes it.
        osascript -e "tell application \"System Events\" to tell ($porta_process) to click menu item \"Permissions…\" of menu 1 of menu bar item \"Porta\" of menu bar 1"
        await 20 'Permissions… showed no sheet' sh -c "[ \"\$(osascript -e 'tell application \"System Events\" to tell (first process whose bundle identifier is \"io.github.janalis.porta\") to get exists sheet 1 of window 1')\" = true ]"
        osascript -e "tell application \"System Events\" to tell ($porta_process) to click button \"Continue\" of sheet 1 of window 1"
        await 20 'Continue did not close the permissions sheet' sh -c "[ \"\$(osascript -e 'tell application \"System Events\" to tell (first process whose bundle identifier is \"io.github.janalis.porta\") to get exists sheet 1 of window 1')\" = false ]"
        echo 'smoke: Porta → Permissions… shows the sheet, and Continue closes it'

        # Window → Font… opens the system's font panel.
        osascript -e "tell application \"System Events\" to tell ($porta_process) to click menu item \"Font…\" of menu 1 of menu bar item \"Window\" of menu bar 1"
        await 20 'Font… opened no font panel' sh -c "osascript -e 'tell application \"System Events\" to tell (first process whose bundle identifier is \"io.github.janalis.porta\") to get name of every window' | grep -q Fonts"
        osascript -e "tell application \"System Events\" to tell ($porta_process) to click (first button of window \"Fonts\" whose subrole is \"AXCloseButton\")"
        echo 'smoke: Window → Font… opens the font panel'

        # A second project is a second window; Window → Merge All Windows makes them tabs of one.
        mkdir -p "$work/blog"
        echo '{}' > "$work/blog/composer.json"
        open -a "$app" "$work/blog"
        await 60 'the second project opened no window' sh -c "[ \"\$(osascript -e 'tell application \"System Events\" to tell (first process whose bundle identifier is \"io.github.janalis.porta\") to count windows')\" -ge 2 ]"
        osascript -e "tell application \"System Events\" to tell ($porta_process) to click menu item \"Merge All Windows\" of menu 1 of menu bar item \"Window\" of menu bar 1"
        await 20 'Merge All Windows left the projects apart' sh -c "[ \"\$(osascript -e 'tell application \"System Events\" to tell (first process whose bundle identifier is \"io.github.janalis.porta\") to count windows')\" = 1 ]"
        echo 'smoke: Merge All Windows makes two projects tabs of one window'
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
