<?php

declare(strict_types=1);

/*
 * The architecture check's pins — what `composer arch` compares the scanned
 * tree against, in EITHER direction: a new edge or cycle is added here in the
 * commit that makes it, and one that vanished is removed. Data, kept out of
 * {@see \Porta\Console\ArchitectureCheckCommand} so that an edge a fix needs
 * is a line here, not an edit to the checker.
 */
return [
    // The audited namespace import graph, sorted, as 'From>To'.
    'edges' => [
        // The assist plugin's own code (plugins/assist/src): its wire rides the
        // shared line framer and non-blocking writer (Support), it edits and
        // watches buffers through the plugin API (Plugin), and it shows the
        // others through core's agent presence (Event, Workbench).
        'AssistPlugin>Config', // `porta assist` enables the plugin in the copy: the default list, and home
        'AssistPlugin>Event',
        'AssistPlugin>Install', // `porta assist` opens this very Porta on the copy
        'AssistPlugin>Lsp', // a guest's language server: file URIs spelled as the language servers spell them
        'AssistPlugin>Onboarding', // …with the first-run card marked seen: the host set the project up
        'AssistPlugin>Overlay', // the toast severities its notices pick
        'AssistPlugin>Plugin',
        'AssistPlugin>Support',
        'AssistPlugin>Ui', // the ellipsis that marks a host's variable as holding more
        'AssistPlugin>Workbench',
        'Bootstrap>Config',
        'Bootstrap>Container',
        'Bootstrap>Editor',
        'Bootstrap>Help',
        'Bootstrap>Overlay',
        'Bootstrap>Plugin',
        'Bootstrap>Run',
        'Bootstrap>Support',
        'Bootstrap>Widget',
        'Bootstrap>Workbench',
        'Command>Config',
        'Command>Container',
        'Command>Debug',
        'Command>Editor',
        'Command>Layout',
        'Command>Lsp',
        'Command>Plugin',
        'Command>Run',
        'Command>Search',
        'Command>Settings',
        'Command>Tree',
        'Command>Widget',
        'Command>Workbench',
        // The composer plugin's own code (plugins/composer/src): the plugin
        // API, the toast severity it reports with, AsyncProcess for
        // `composer outdated`, and the dock's ToolRun.
        'Composer>Overlay',
        'Composer>Plugin',
        'Composer>Support',
        'Composer>Workbench',
        'Config>Container',
        'Config>Editor',
        'Config>Plugin',
        'Config>Support',
        'Console>Config', // PortaCommand asks whether to trust a project before the IDE boots (WorkspaceTrust)
        'Console>Container',
        'Console>Filesystem', // PortaCommand does not ask about trust for a remote project's cache: nothing runs there
        'Console>Install',
        'Console>Lsp', // lsp:probe drives the editor's own LspClient and ServerLocator, headless
        'Console>Plugin', // PluginCommands reads bundled manifests for `porta <name>`, and runs only a Plugin\Api\PluginCommand
        'Console>Support',
        'Container>Config',
        // The custos plugin's own code (plugins/custos/src): ServerNotFound, so
        // a php claim with no binary leaves custos out instead of launching a
        // broken process. Its porta.php does the rest of the wiring.
        'Custos>Plugin',
        'Debug>Container',
        'Debug>Editor',
        'Debug>Event',
        'Debug>Filesystem', // the debugger runs the project here: LocalOnly refuses a remote one
        // Inline debug values: the LSP says WHERE the variables are, the debug
        // adapter says what they hold. One side has to know the other, and it
        // is this one — the debugger knows WHEN the join means anything, and
        // the answer stops being true when it steps. No cycle: Lsp does not
        // reach back into Debug.
        'Debug>Lsp',
        'Debug>Support',
        'Debug>Terminal',
        'Debug>Ui',
        'Debug>Widget',
        'Debug>Workbench',
        'Editor>Config',
        'Editor>Container',
        'Editor>Event',
        'Editor>Filesystem',
        'Editor>Highlight',
        'Editor>History',
        'Editor>Lsp',
        'Editor>Search',
        'Editor>Support',
        'Editor>Ui',
        'Editor>Widget',
        'Editor>Workbench',
        'Event>Config',
        'Event>Editor',
        'Event>Ui',
        'Event>Workbench',
        'Event>Widget',
        'Filesystem>Container',
        'Filesystem>Editor', // the remote poll re-lists the directories holding an open buffer first
        'Filesystem>Event', // StatusCellsChanged: the ⇡N cell moves while nothing else on screen does
        'Filesystem>Search', // a walk of a remote tree refreshes what go-to-file reads
        'Filesystem>Support',
        'Filesystem>Ui', // the status cell's glyphs
        'Filesystem>Workbench', // a remote fetch or upload that fails says so
        'FtpPlugin>Config', // PortaHome: where passwords and caches are kept
        'FtpPlugin>Filesystem', // the transport core asks for, and the spec it serves
        'FtpPlugin>Plugin',
        'FtpPlugin>Support', // Files::ensureDir() for the password folder
        // The graph-viewer plugin's own classes (plugins/graph-viewer/src): the
        // architecture scanner reaches core's AsyncProcess and JsonFile. A
        // plugin depending on core is the allowed direction — core depending on
        // a plugin is what the missing `Editor>GraphViewer` edge shows it does
        // not do.
        // The graph viewer's own code (plugins/graph-viewer/src).
        // GraphViewer>ImageViewer is the first CROSS-PLUGIN edge: the graph pane
        // paints with the image viewer's rasterizer, which is why its manifest
        // declares `requires: ["image-viewer"]` — the dependency mechanism's
        // first real use.
        // The diff plugin (plugins/diff/src): the unified-diff model and the
        // diff pane, moved out of git so review draws its diffs with the same
        // pane. A pane reaching for core's toolkits, as the git view's did.
        'DiffPlugin>Editor', // the diff pane paints and maps columns with the editor's tab stops
        'DiffPlugin>Highlight',
        'DiffPlugin>Layout',
        'DiffPlugin>Support',
        'DiffPlugin>Ui',
        'DiffPlugin>Widget',
        // The git plugin owns the whole git feature now: the view and its five
        // widgets, the branch cell, the menu, the palette verbs, the settings.
        // `Porta\Git` no longer exists, and neither does any core edge INTO
        // git — which is what the seven vanished `*>Git` entries above record.
        // What is left is a plugin reaching for core's toolkits, the allowed
        // direction, and the same set `Help` and `GraphViewer` already reach for.
        'GitPlugin>Config', // an untrusted repository's own config may not name a program git runs (WorkspaceTrust)
        'GitPlugin>DiffPlugin', // its diffs and the diff pane; the manifest declares `requires: ["diff"]`
        'GitPlugin>Editor', // marking a conflicted file resolved is refused while ConflictMarkers finds one left
        'GitPlugin>Event',
        'GitPlugin>Input',
        'GitPlugin>Layout',
        'GitPlugin>Overlay',
        'GitPlugin>Support',
        'GitPlugin>Plugin',
        'GitPlugin>Ui',
        'GitPlugin>Widget',
        'GitPlugin>Workbench',
        'GraphViewer>Help', // graph-viewer's fence callbacks return core's Markdown render result
        'GraphViewer>ImageViewer',
        'GraphViewer>Support',
        'GraphViewer>Ui',
        'GraphViewer>Widget',
        'Help>Container',
        'Help>Editor',
        'Help>Event',
        'Help>Highlight',
        'Help>Input',
        'Help>Layout',
        'Help>Markdown',
        'Help>Support',
        'Help>Ui',
        'Help>Widget',
        'Help>Workbench',
        'Highlight>Container',
        'Highlight>Editor',
        'Highlight>Event',
        'Highlight>Support',
        'Highlight>Ui',
        'History>Config', // RecentProjectsStore is GLOBAL: PortaHome names the only place a cross-project list can live
        'History>Container',
        'History>Editor',
        'History>Event',
        'History>Support',
        'History>Ui',
        'History>Workbench',
        'I18n>Container',
        'I18n>Support', // AnsiText::stripCursorMarkers(), the one marker pattern built from the vendor prefix
        'I18n>Ui',
        'Input>Config',
        'Input>Container',
        'Input>Debug',
        'Input>Editor',
        'Input>Event',
        'ImageViewer>Support',
        'ImageViewer>Ui',
        'ImageViewer>Widget',
        'ImageViewer>Workbench', // StatusClickSpan: the zoom hints a status click runs
        'Input>Layout',
        'Input>Overlay',
        'Input>Support',
        'Input>Tree',
        'Input>Ui',
        'Input>Widget',
        'Input>Workbench',
        'Install>Support', // Files::ensureDir: the race-safe mkdir every writer of a directory shares
        'Layout>Config',
        'Layout>Container',
        'Layout>Input',
        'Layout>Support',
        'Layout>Ui',
        'Layout>Widget',
        'Layout>Workbench',
        'LocalHistory>Overlay',
        'LocalHistory>Plugin',
        'LocalHistory>Support',
        'LocalHistory>Workbench',
        'Lsp>Config',
        'Lsp>Container',
        'Lsp>Editor',
        'Lsp>Filesystem', // a workspace edit's create, move and delete go through the project's files
        'Lsp>Event',
        'Lsp>Support',
        'Lsp>Ui',
        'Lsp>Widget',
        'Lsp>Workbench',
        // The mcp plugin's own code (plugins/mcp/src): the socket's writer
        // (Support), the tool seam it serves (Plugin), the toast severity its
        // notify tool picks (Overlay), and the binary its bridge names (Install).
        'McpPlugin>Editor', // DiskText: a proposal is held as the buffer holds the file, and handed back in its own shape
        'McpPlugin>Event', // what an agent does is told to core as AgentActivity / AgentLeft
        'McpPlugin>Install', // `porta mcp --help` prints the running binary's full path to register
        'McpPlugin>Lsp', // Claude Code's IDE connection speaks in file:// URIs, spelled as the language servers are
        'McpPlugin>Overlay',
        'McpPlugin>Plugin',
        'McpPlugin>Search', // its own ProjectSearch per call: the shared one cancels the user's
        'McpPlugin>Support',
        'McpPlugin>Ui', // the agent ✦ its status cell leads with
        'McpPlugin>Workbench', // that status cell: a StatusCell, as every plugin segment returns
        'Onboarding>Config',
        'Onboarding>Container',
        'Onboarding>Highlight',
        'Onboarding>Plugin',
        'Onboarding>Support',
        'Overlay>Container',
        'Overlay>Editor',
        'Overlay>Layout',
        'Overlay>Onboarding',
        'Overlay>Search',
        'Overlay>Support',
        'Overlay>Ui',
        'Overlay>Widget',
        'Overlay>Workbench',
        // The phpunit plugin's own code (plugins/phpunit/src): the plugin API,
        // the toast severity its run reports with, and the dock's ToolRun.
        'PhpUnit>Overlay',
        'PhpUnit>Plugin',
        'PhpUnit>Support', // Files::read() for a test file not open in the editor
        'PhpUnit>Workbench',
        'Plugin>Config',
        'Plugin>Container',
        'Plugin>Debug',
        'Plugin>Editor',
        'Plugin>Event',
        'Plugin>Filesystem', // a RemoteProjectPlugin hands core the transport for its scheme
        'Plugin>Help', // committed Markdown fence renderers live in core's page renderer
        'Plugin>Highlight',
        'Plugin>Install', // OpenRemoteCommand opens this very Porta on the cache
        'Plugin>Lsp',
        'Plugin>Onboarding', // …with the first-run card marked seen: the server's project is set up there
        'Plugin>Overlay',
        'Plugin>Presence', // PluginParticipants: a plugin's people in core's roster
        'Plugin>Run', // Plugin\Api\Runs: the Run menu's tasks and background runs
        'Plugin>Search',
        'Plugin>Settings',
        'Plugin>Support',
        'Plugin>Tree',
        'Plugin>Ui', // flash()/notify() take a Ui\Markup\Markup, the value a styled message travels as
        'Plugin>Widget',
        'Plugin>Workbench',
        // Who else is at work on the project: the board other Porta instances
        // share (on this disk, or the server's through a transport of its own),
        // their carets shown as agents', and the roster's status cell.
        'Presence>Container',
        'Presence>Editor',
        'Presence>Event',
        'Presence>Filesystem',
        'Presence>Support',
        'Presence>Ui',
        'Presence>Workbench',
        // The review plugin's own code (plugins/review/src). ReviewPlugin>GitPlugin
        // is the second cross-plugin edge, declared by `requires: ["git"]`: it
        // borrows the git plugin's process runner and diff model, never its
        // live objects. The rest is a plugin reaching for core's toolkits.
        'ReviewPlugin>DiffPlugin', // its diffs; the manifest declares `requires: ["diff", "git"]`
        'ReviewPlugin>GitPlugin',
        'ReviewPlugin>Editor', // a file's code wraps with the editor's own formula, as the diff pane's does
        'ReviewPlugin>Input', // the view's pointer hit-tests its panes, as git's does
        'ReviewPlugin>Layout', // the view's column resizes through core's ResizableSidebar
        'ReviewPlugin>Overlay',
        'ReviewPlugin>Plugin',
        'ReviewPlugin>Support',
        'ReviewPlugin>Ui',
        'ReviewPlugin>Widget',
        'ReviewPlugin>Workbench',
        'Run>Event',
        'Run>Container',
        'Run>Editor', // a run saves the open buffers first: it runs what is on disk
        'Run>Filesystem', // runs and terminals run the project here: LocalOnly refuses a remote one
        'Run>Support',
        'Run>Terminal',
        'Run>Widget',
        'Run>Workbench',
        'Search>Config',
        'Search>Container',
        'Search>Editor',
        'Search>Event',
        'Search>Filesystem',
        'Search>Highlight',
        'Search>History',
        'Search>Lsp',
        'Search>Overlay',
        'Search>Support',
        'Search>Ui',
        'Search>Widget',
        'Search>Workbench',
        'Settings>Config',
        'Settings>Container',
        'Settings>Debug',
        'Settings>Editor',
        'Settings>Event',
        'Settings>Highlight',
        'Settings>Input',
        'Settings>Install',
        'Settings>Layout',
        'Settings>Lsp',
        // The two tooltip settings apply straight to the TooltipCenter — the
        // same live-apply pattern.
        'Settings>Overlay',
        'Settings>Plugin',
        'Settings>Presence', // the name and presence settings, applied live
        // The "Search vendor/ files" toggle applies straight to the shared
        // FileIndex — the SettingsController's live-apply pattern.
        'Settings>Search',
        'Settings>Support',
        'Settings>Ui',
        'Settings>Widget',
        'Settings>Workbench',
        'SshPlugin>Config', // PortaHome: where shared connections and caches are kept
        'SshPlugin>Filesystem', // the transport core asks for, and the spec it serves
        'SshPlugin>Plugin',
        'SshPlugin>Support', // Files::ensureDir() for the shared-connection folder
        'Support>Container',
        'Support>Ui',
        // The symfony-lsp plugin's core reaches: ServerNotFound, so a claimed
        // language with no binary is said rather than silent, and Argv::split
        // for the php-command setting. Its porta.php (outside the namespace
        // graph) does the rest of the PluginApi wiring.
        'SymfonyLsp>Plugin',
        'SymfonyLsp>Support',
        'Terminal>Support',
        'Terminal>Ui',
        'Todo>Plugin',
        'Todo>Search',
        'Todo>Workbench',
        'Tree>Container',
        'Tree>Editor',
        'Tree>Event',
        'Tree>Filesystem',
        'Tree>Search',
        'Tree>Support',
        'Tree>Widget',
        'Tree>Workbench',
        'Ui>Container',
        'Ui>Editor',
        'Ui>Highlight',
        'Ui>Input',
        'Ui>Overlay',
        'Ui>Support',
        'Widget>Event',
        'Widget>Config',
        'Widget>Container',
        'Widget>Debug',
        'Widget>Editor',
        'Widget>Filesystem', // the tree asks a remote directory to be listed when it shows it
        'Widget>Help',
        // The find bar recalls the PROJECT search's query history rather than
        // keeping a second one; one list of what you looked for in this
        // project, reachable from either find surface.
        'Widget>History',
        'Widget>Highlight',
        'Widget>Input',
        'Widget>Layout',
        // The editor widget picks its scope source: the offline PHP scan, or the
        // language server's symbols for every other language.
        'Widget>Lsp',
        'Widget>Onboarding',
        'Widget>Overlay',
        'Widget>Search',
        'Widget>Settings',
        'Widget>Support',
        'Widget>Terminal',
        'Widget>Tree',
        'Widget>Ui',
        'Widget>Workbench',
        'Workbench>Config',
        'Workbench>Container',
        'Workbench>Editor',
        'Workbench>Filesystem', // quitting asks twice while changes are still on their way to a server
        'Workbench>History', // ProjectSwitcher reads the recent-projects list
        'Workbench>Event',
        'Workbench>Input',
        'Workbench>Layout',
        'Workbench>Lsp',
        'Workbench>Overlay',
        'Workbench>Plugin',
        'Workbench>Run',
        'Workbench>Search',
        'Workbench>Support',
        'Workbench>Terminal',
        'Workbench>Tree',
        'Workbench>Ui',
        'Workbench>Widget',
        // The xdebug plugin's own code (plugins/xdebug/src): the plugin API, the
        // toast severity its install reports with, and AsyncProcess for curl
        // and unzip. Its porta.php reads Config\PortaHome, outside the graph.
        'Xdebug>Overlay',
        'Xdebug>Plugin',
        'Xdebug>Support',
    ],
    // Multi-member SCCs the audit measured and accepted, each a sorted member
    // list. The one left is degenerate: IdeContainerFactory reads Ide::VERSION
    // as a cache-key salt — a constant, not a collaboration.
    //
    // Two are gone rather than pinned. The seven-class ring around the old
    // view registry went with its two concrete controller fields, which a
    // string-keyed registry does not have. PluginApi/PluginManager went when
    // the manager started building the API and handing it runtime(), tools()
    // and dispatch() as closures: the drain at commit is the only edge left.
    'sccs' => [
        ['Porta\\Container\\IdeContainerFactory', 'Porta\\Ide'],
    ],
];
