<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */
if ( empty($pid) ) {
    echo 'The RJQ service is NOT running' . "\n";
} else {
    if ( posix_kill($pid, 0) ) { // only check the process
        echo 'The RJQ service is running ('.$pid.')...' . "\n";
    } else {
        echo 'WARNING : The RJQ process seems to be crashed (use --stop)' . "\n";
    }
    // showing statistics
    if (
        !empty($config['stats'])
        && file_exists($config['stats'])
    ) {
        $stats = json_decode(file_get_contents(
            $config['stats']
        ), true);
        if ( !empty($stats) ) {
            echo 'Started at ' . date('Y-m-d H:i:s', $stats['start']);
            echo ' - ' . sec2h( time() - $stats['start']) . "\n\n";
            echo 'Memory   : ' . round($stats['memory'] / 1024 / 1024, 2) . 'MB' . "\n";
            echo 'Workers  : ' . (int)$stats['counters']['workers'] . "\n";
            echo 'Queue    : ' . (int)$stats['counters']['queue'] . "\n";
            echo 'Jobs     : '
                . round($stats['counters']['done'] / 1000, 1) . 'M done, '
                . (int)$stats['counters']['fail'] . ' fails '
                . "\n";
        }
    }
}