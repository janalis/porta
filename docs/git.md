# Git integration

Git support is silently disabled outside a git working tree.

## In the editor

- **Change markers** in the gutter: added / modified / deleted vs `HEAD`,
  refreshed on open and save (not live-remapped during unsaved edits).
- The **current branch** in the status bar.
- **Merge-conflict navigation and resolution** — see
  [editor.md](editor.md#merge-conflicts).

## Command palette operations

All async and non-blocking:

- **Git: Stage Current File** — `git add` the active file
- **Git: Commit…** — commit with a message prompt
- **Git: Undo Last Commit** — soft-reset the last commit
- **Git: Blame Current Line** — author/date/summary for the caret's line

## The Git view (`Ctrl+Alt+D`)

A full-screen, GitHub-Desktop-style view that replaces the tree|editor
area: a changes list + commit box on one side, a diff pane on the other.
Status re-reads on every action and polls every 2 s while open. Two tabs:
**Changes** (`1`) and **History** (`2`); `Tab` cycles panes; `Esc` closes.

### Changes

Each row is `[x] M path` — the checkbox mirrors the staged state.

| Key | Action |
| --- | --- |
| `Space` | Stage / unstage the selected file |
| `d` / `Delete` | Discard the file's changes (inline confirm) |
| Click the checkbox | Toggle staging with the mouse |

### Diff pane

Unified (dual gutters) or side-by-side.

| Key | Action |
| --- | --- |
| `v`, then `Space` | Select a line range, then stage just those lines |
| `Space` | Stage the hunk/selection |
| `c` | Cycle diff context (git default → ±10 lines → whole file) |
| `s` | Toggle unified / side-by-side (persisted) |
| `w` | Toggle hide-whitespace (`-w`, persisted) |
| `Ctrl+C` | Copy the selected code lines |

### Commit box

`Ctrl+Enter` commits the staged changes (the button enables only when
something is staged); `Alt+M` toggles **amend**, prefilling the last
commit's message.

### History

The last 200 commits. Selecting a commit lists its files and shows the
first file's patch; `Enter` switches between the commit list and the file
list; the diff pane is read-only here. `1` returns to Changes.

The view covers changes, staging (including line-level), commit/amend and
history browsing. History loads 200 commits at a time — moving past the last
loaded commit pulls in the next page.

## Remote & branches

`Git: Fetch`, `Git: Pull` and `Git: Push` live in the command palette and the
Git menu; inside the git view, `f` / `p` / `Shift+P` run them directly, and
`Ctrl+Shift+K` pushes from anywhere. Pull tries a fast-forward first: when the
branch has diverged, a chooser offers **Rebase** (local commits replayed on
top of the remote) or **Merge** (a merge commit) — Cancel leaves everything
untouched. If the integration hits conflicts it stops with git's first
message; resolve in the editor and commit as usual. Pushing a branch with no
upstream offers **Push with --set-upstream origin <branch>** instead of
failing — nothing is published without a confirmation (`Git: Push (Set
Upstream)` runs the same thing directly). Other failures (auth, rejected
push) flash git's first error line. A remote operation that hangs (for
example an ssh passphrase prompt without an agent) can be cancelled with
`Git: Cancel Remote Operation`.

`Git: Branches…` opens a picker over the editor: local branches first (newest
activity first, the current one checked), then remote branches no local
shadows, marked *remote* — picking one checks it out as a local tracking
branch. The leading "+ Create new branch…" row creates and switches in one
step; "− Delete branch…" picks among the other local branches and asks for
confirmation before running `git branch -d` — an unmerged branch is refused
with git's own message (use the shell for a force delete). Checkout failures
(uncommitted changes in the way) flash git's message — nothing is stashed
for you.
