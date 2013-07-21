<?php
echo '-- stress tests --' . "\n";
do {
    $load = rand(10, 2000);
    $complexity = rand(1, 20);
    echo 'Run ' . $load . ' load with ' . $complexity . ' max complexity' . "\n";
    $start = microtime(true);
    exec('php ' . __DIR__ . '/loader.php ' . $load . ' ' . $complexity);
    echo '--> duration ' . round(microtime(true) - $start, 1) . 'sec' . "\n";
    exec('rjq --status', $out);
    echo implode("\n", $out) . "\n";
} while(true);