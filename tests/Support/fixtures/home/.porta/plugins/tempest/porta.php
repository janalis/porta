<?php

declare(strict_types=1);

/**
 * Test fixture: the bundled `tempest` highlighting plugin, installed as a
 * *global* plugin so it loads for every booted Ide in the suite.
 *
 * Highlighting lives in an opt-in bundled plugin now, so without this the whole
 * suite would render uncoloured and the render goldens plus every
 * colour-asserting test would be pinning a configuration nobody runs.
 *
 * Global plugins are opt-out — loaded unless named in `disabledPlugins` — so
 * this needs no per-project `.porta.json`. That matters: writing config or
 * plugin files into each TempProject perturbs the git fixtures (working-tree
 * assertions) and the tree/tab fixtures (an extra file in the project root).
 *
 * It delegates to the real bundled file rather than copying it, so the suite
 * exercises the shipped plugin.
 */
return require \dirname(__DIR__, levels: 7).'/plugins/tempest/porta.php';
