<?php

declare(strict_types=1);

// The process aamio runs for each call of this add-on, named in aamio-addon.json
// under process.php: one request on standard input, one answer on standard
// output, as contract 2 of aamio's add-ons says. It needs nothing of the core and
// loads only its own classes.
require __DIR__ . '/autoload.php';

exit(\Aamio\Sound\Process::main());
