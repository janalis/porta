# Agents (MCP)

The bundled `mcp` plugin runs a [Model Context Protocol](https://modelcontextprotocol.io)
server inside Porta. An agent such as Claude Code then works with **the IDE you
have open**, not just the files on disk. It can:

- see what you are looking at;
- read buffers with their unsaved edits;
- read what the language servers found;
- open a file at a line for you;
- make an edit you can undo.

It can also call every tool the other plugins offer.

It is off by default. Turn it on under **Settings → Plugins → mcp**.

## Connecting an agent

The agent launches `porta mcp`, which connects to the Porta open on the
project:

```sh
claude mcp add porta -- porta mcp
```

That needs `porta` on your PATH: **Settings → Command line** puts it there. Or
give the full path, which `porta mcp --help` prints ready to paste. Other
clients take the same command, run from the project directory or anywhere
below it.

The agent usually starts before the IDE, and outlives it. So `porta mcp` does
not give up when no IDE is open: it waits, and connects when one opens on the
project. Until then the agent sees a server with no tools, and a call is
answered with what to open. When the IDE quits, a call it was running is
answered with an error. When it comes back, the agent is told its tools
changed. `--wait=<seconds>` makes it give up after that long without an IDE.

The IDE listens on a unix socket, `.porta/mcp.sock`, that only your user can
open. When the project path is too long for a socket, the socket goes in the
temp directory under a random name, and `.porta/mcp.sock.path` says where. The
socket is removed when Porta quits or the plugin is disabled.

## What it can do

| Tool | Does |
| --- | --- |
| `context` | The project root, the active file and caret, the files selected in the tree, and every open tab with whether it has unsaved edits |
| `read_buffer` | The text of an open file as it is in the editor — unsaved edits included. Without a path, the active file |
| `open_file` | Open a file and bring it forward, optionally at a line and column |
| `diagnostics` | What the language servers and analysers report for the open files |
| `notify` | Show you a short message |
| `replace_buffer` | Replace a file's whole text in the editor — opening it first if needed |
| `insert_text` | Insert text at the caret |
| `save_buffer` | Save an open file to disk, as Ctrl+S does — off until you turn on `allowSave` |
| `search` | Find text across the project like Find in Files: regex, case, whole word, a file mask, a set of paths |
| `definition` | Where the symbol at a position is declared, asked of the language server |
| `references` | Every place that symbol is used, its declaration included |
| `hover` | What the language server says about it: signature and documentation |
| `symbols` | The project's classes, functions and other symbols whose name matches a query |

The four code tools ask the language server the IDE already runs, about **any file
of the project** — open or not, with nothing shown and no tab moved — so an
agent gets the precise answer a text search for a name is not. A column
counts UTF-16 code units, as the protocol does. A server that does not answer
within the `lspTimeout` setting (15 seconds) is given up on.

Lines and columns are 1-based, as compilers and `grep` print them. Paths are
project-relative or absolute, and must be inside the project.

**Edits are not saved unless you say so.** Each one is a single undoable step
in the editor, so you review it, keep it with Ctrl+S, or take it back with
Ctrl+Z. Turn the `allowEdits` setting off and the two edit tools are not
offered at all. Turn `allowSave` on and the agent may save too — as Ctrl+S
does, so local history keeps the version it replaced. A file that changed on
disk while it held edits is never overwritten: the agent is told to ask you.

`search` reads the files **on disk**, as Find in Files does, with its excludes
(`vendor`, `node_modules`, `.git`…) and its 500-match cap; an unsaved edit is
what `read_buffer` is for.

### Running tasks and tests

With `allowRuns` on — it is off until you turn it on — the agent can run what
the Run menu offers, and the project's PHPUnit tests:

| Tool | Does |
| --- | --- |
| `run_configurations` | The Run menu's tasks right now: the active PHP file, composer scripts, Makefile targets |
| `run` | Run one of them by its label |
| `run_tests` | Run `vendor/bin/phpunit` — all tests, a file or directory, and/or a `--filter` |
| `run_output` | What a run printed so far, whether it is still running, its exit code |

Each run is a tab in the terminal panel, so you can watch it — but starting
one neither opens the panel nor takes your focus. A call waits for the run to
end, up to `runTimeout` (300 seconds), and answers with the exit code and the
end of the output; a longer run goes on, and `run_output` reads it later.
Running a label again replaces the run in progress, as the Run menu does.

### From other plugins

Any plugin can offer tools; their names start with the plugin's. The bundled
ones:

| Tool | Plugin | Does |
| --- | --- | --- |
| `git_status` | git | The branch and every changed or untracked file |
| `git_diff` | git | Unstaged changes, or staged ones, optionally for one file |
| `review_threads` | review | The branch's review: every conversation, with its path, line and comments |
| `review_reply` | review | Answer a thread |
| `review_resolve` | review | Resolve a thread, or reopen one |
| `review_comment` | review | Start a conversation on a line |

The list follows the IDE live: enable or disable a plugin, or change one of
the `allow…` settings, and connected agents are told to fetch it again.

### What a client may run without asking

Every tool says what calling it does, as the protocol's annotations. The read
tools are read-only. The edit tools, `open_file` and `notify` change the IDE and
nothing on disk. `save_buffer`, `run` and `run_tests` change the machine. The
review's writes publish to the forge. A client that approves by annotation can
let an agent read freely and still ask before it saves or runs anything.

A client on protocol `2025-06-18` or later gets a JSON answer twice: once as
text, and once as `structuredContent`, the value itself. A list is wrapped as
`{"items": [...]}`.

### Slow calls and cancelled ones

A client that sends a progress token with a call hears from Porta every two
seconds until it answers, so a slow forge or test run does not look like a
dead server. A call the client cancels is not answered; what it started — a
git process, a test run — is not stopped by that, since the IDE cannot tell
whose it was.

A plugin author offers a tool with `registerTool()`; see
[Plugins](plugins.md#offering-a-tool-to-agents).
