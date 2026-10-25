<?php
/** Synthetic populated read-only baseline; the database-runner removes the isolated database afterwards. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }

require __DIR__ . '/onyx-scenarios.php';
require __DIR__ . '/admin-query-baseline.php';
require __DIR__ . '/admin-query-explain.php';
