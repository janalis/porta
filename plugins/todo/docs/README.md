# TODO

Lists every `TODO`, `FIXME`, `HACK`, `XXX` and `BUG` comment in the project in
a panel on the bottom dock. Enabled by default.

**Show TODOs** (`Alt+8`, or the View menu) scans the project and opens the
list; `Enter` on a row opens the file at that line, and `F5` scans again —
the rest of the panel's keys are the
[results panels'](../../../docs/keybindings.md#results-panels). The scan is
project search's own engine, so it skips what Find in Files skips.

Disabling the plugin takes `Alt+8` and the View-menu entry with it.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| TODO tags to scan for | `TODO,FIXME,HACK,XXX,BUG` | Comma-separated, matched case-sensitively; read by the next scan |
