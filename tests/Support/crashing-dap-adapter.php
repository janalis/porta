<?php

declare(strict_types=1);

// A debug adapter that dies immediately — exercises the stdout-EOF path.
fwrite(STDERR, "crashing on purpose\n");
exit(1);
