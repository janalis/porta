# Troubleshooting

## The app appears frozen (no input, screen stuck)

Porta runs everything — UI, LSP, git, terminals — on one event loop. A frozen
app means either the loop is blocked in a syscall, or the terminal itself
stopped delivering/displaying. Evidence lands in **`.porta/freeze.log`** in
the project root:

- `loop stalled for X.XXs (recovered)` — something blocked the loop and let
  go. Repeated lines correlated with disk/network activity usually point at a
  slow filesystem (network mounts) or a heavyweight synchronous operation.
- `loop blocked ≥ Ns, backtrace: …` — the loop is blocked *right now*; the
  backtrace names the exact call. A hard hang writes one entry every N
  seconds until the process dies, so even a kill -9 leaves the culprit named.

A freeze with a **clean** freeze.log means the loop is alive but input is
being swallowed upstream — see the known vendor issues below.

### Fixed in Porta

- **Language-server / debug-adapter pipe deadlock** (the most common cause of
  a permanent full freeze while an LSP server was active): writes to the
  child's stdin are now queued and non-blocking (`Support/NonBlockingWriter`).
  A server that stops draining its pipe can no longer stall the loop; if its
  backlog passes 8 MiB the session is dropped like a crashed server.
- **Latched synchronized output / lost mouse modes**: every ~30 s the exact
  mouse-reporting modes are re-asserted and any latched `?2026` synchronized
  update is released (`Ui/TerminalStateRefresher`), so an interrupted frame
  write or an external stop/resume self-heals within half a minute.
- **Blocking terminal output** (the cause a captured freeze.log backtrace
  proved: the loop parked in `fwrite(STDOUT)` under terminal back-pressure —
  stalled SSH, `Ctrl+S` flow control, a paused pane): frame writes now ride a
  non-blocking queue (`Ui/NonBlockingTerminal` over `Support/NonBlockingWriter`).
  A stalled terminal can no longer park the loop; frames are never dropped —
  past an 8 MiB backlog Porta falls back to one synchronous flush (the old
  behavior, reached only after minutes of a fully wedged terminal, and
  freeze.log still documents it). Teardown escape codes are flushed
  synchronously on exit so the terminal is always restored. Frame generation
  self-paces to the terminal's drain rate — a new frame is only rendered once
  the previous one has fully left the queue — so fast scrolling can never
  build a backlog of stale frames.

### Known issues in the underlying symfony/tui component (not fixable here)

- **Bracketed-paste / OSC input traps**: an unterminated paste
  (`\x1b[200~` with no closing `\x1b[201~`, e.g. from a mangled remote
  paste) or a truncated OSC/DCS terminal reply leaves the input parser
  buffering **all** keyboard and mouse bytes with no timeout. The loop stays
  alive (freeze.log stays clean) but the app is deaf. A stray `Ctrl+G` (BEL
  terminates OSC) sometimes unsticks it; otherwise restart.

## Language server misbehaving?

Run `LSP: Show Server Log` from the command palette. Porta captures each
server's stderr and `window/logMessage` notifications (newest first, prefixed
with the server name) — startup crashes, missing binaries and indexing
failures all show up there.
