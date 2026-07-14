# Running code & the integrated terminal

## Run configurations

`Ctrl+Shift+R` opens the Run picker, which auto-discovers, in order:

1. **The current PHP file** — "Run current file: `<name>`" (when a `.php`
   buffer is active)
2. **Composer scripts** — every entry under `scripts` in `composer.json`
3. **Makefile targets** — every column-0 `target:` in the project Makefile

`Shift+F10` repeats the last run. Output streams live into the terminal
panel; its title shows `… — exited (<code>)` when the process ends. One
process runs at a time — starting a new run stops the previous one.

## Integrated terminal

`` Ctrl+` `` or `Alt+F12` toggles a bottom terminal panel running a real
interactive shell (`$SHELL -i`, falling back to `/bin/sh`) on a
pseudo-terminal. Colors and progress bars render; keystrokes are forwarded
with proper terminal encoding. The shell keeps running while the panel is
hidden.

It is a line-oriented scrollback panel, not a full-screen VT host — running
`vim` or `htop` inside it is out of scope.

## Debugging

Full DAP debugging (breakpoints, stepping, frames, variables) is separate —
see [debugger.md](debugger.md).
