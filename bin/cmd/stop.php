<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */

if ( !empty($pid) ) {
    echo 'Stopping ...';
    if ( !posix_kill($pid, SIGTERM) ) {
        echo "\n" . 'WARNING : Process crashed (PID:' . $pid . ')' ;
        unlink( $config['pid'] );
    }
    for( $i = 0; $i < 20; $i++) {
        if ( !file_exists($config['pid']) ) {
            break;
        }
        echo '.';
        sleep(1);
    }
    echo "\n";
    if ( file_exists($config['pid']) ) {
        echo 'ERROR : Unable to stop the process (PID:' . $pid . ')' ."\n";
        return false;
    }
    $pid = 0;
} else {
    echo 'RJQ is not actually running' . "\n";
}
return true;