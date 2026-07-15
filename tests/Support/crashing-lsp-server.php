<?php

declare(strict_types=1);

// Fault server: writes to stderr, then dies without ever answering.
fwrite(STDERR, data: "boom: refusing to speak LSP\n");
exit(1);
