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

Porta must be open on the project. The agent then launches `porta mcp`, which
connects to that IDE:

```sh
claude mcp add porta -- porta mcp
```

Other clients take the same command, `porta mcp`, run from the project
directory or anywhere below it. With no IDE open on the project it exits with
a message saying so.

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

Lines and columns are 1-based, as compilers and `grep` print them. Paths are
project-relative or absolute, and must be inside the project.

**Edits are never saved.** Each one is a single undoable step in the editor,
so you review it, keep it with Ctrl+S, or take it back with Ctrl+Z. Turn the
`allowEdits` setting off and the two edit tools are not offered at all.

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

The list follows the IDE live: enable or disable a plugin, or change
`allowEdits`, and connected agents are told to fetch it again.

A plugin author offers a tool with `registerTool()`; see
[Plugins](plugins.md#offering-a-tool-to-agents).
