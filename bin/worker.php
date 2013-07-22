#!/usr/bin/env php
<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */
$bootstrap = $_SERVER['bootstrap'];
$action = $_SERVER['prefix'];
if ( !empty($_SERVER['verbose']) ) {
    $log = fopen(__DIR__ . '/server.txt', 'a+');
    define('VERBOSE', !empty($log) );
} else {
    define('VERBOSE', false);
}
VERBOSE && fputs($log, 'Start ' . $action . ' at ' . date('Y-m-d H:i:s'). "\n");
VERBOSE && fputs($log, '>>> ' . $bootstrap . "\n");

require $bootstrap;
stream_set_blocking(STDIN, 1);

// main worker loop
while( !feof(STDIN) ) {
    $size = trim(fgets(STDIN));
    if ( empty($size) ) {
        if ( VERBOSE ) {
            echo 'wait' . "\n";
        }
        usleep(5000);
        continue;
    }
    if ( $size[0] !== '$' ) {
        fputs(STDERR, 'Bad protocol size : ' . $size);
        exit(1);
    }
    $job = fread(STDIN, substr($size, 1));
    VERBOSE && fputs($log, 'job statement : ' . $job . "<<\n");
    $argSize = trim(fgets(STDIN));
    if ( $job === 'stop' ) {
        VERBOSE && fputs($log, 'required to stop ' . "\n");
        exit(0);
    }
    if ( empty($argSize) || $argSize[0] !== '$' ) {
        fputs(STDERR, 'Bad protocol size : ' . $argSize . "\n" );
        exit(1);
    }
    $args = fread(STDIN, substr($argSize, 1));
    VERBOSE && fputs($log, 'args >' . $args . "<<\n");
    ob_start();
    try {
        call_user_func_array($action, json_decode($args));
        ob_end_clean();
        VERBOSE && fputs($log, 'job is done ' . "\n");
        echo 'done' . "\n";
    } catch(\Exception $ex) {
        ob_end_clean();
        VERBOSE && fputs($log, 'job is fails ' . "\n");
        VERBOSE && fputs($log,  $ex->__toString());
        echo 'error' . "\n";
    }
}
exit(0);