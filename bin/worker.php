#!/usr/bin/env php
<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */
$log = fopen(__DIR__ . '/server.txt', 'a+');
$bootstrap = $_SERVER['bootstrap'];
$action = $_SERVER['prefix'];
fputs($log, 'Start ' . $action . ' at ' . date('Y-m-d H:i:s'). "\n");
fputs($log, '>>> ' . $bootstrap . "\n");
require $bootstrap;
while( true ) {
    $size = trim(fgets(STDIN));
    if ( empty($size) ) {
        echo 'wait' . "\n";
        ob_flush();
        usleep(1000 * 10);
        continue;
    }
    if ( $size[0] !== '$' ) {
        fputs(STDERR, 'Bad protocol size : ' . $size);
        exit(1);
    }
    $job = fread(STDIN, substr($size, 1));
    fputs($log, 'job statement : ' . $job . "<<\n");
    $argSize = trim(fgets(STDIN));
    if ( $job === 'stop' ) {
        fputs($log, 'required to stop ' . "\n");
        die();
    }
    if ( empty($argSize) || $argSize[0] !== '$' ) {
        fputs(STDERR, 'Bad protocol size : ' . $argSize . "\n" );
        exit(1);
    }
    $args = fread(STDIN, substr($argSize, 1));
    fputs($log, 'args >' . $args . "<<\n");
    ob_start();
    try {
        call_user_func_array($action, json_decode($args));
        ob_end_clean();
        fputs($log, 'job is done ' . "\n");
        echo 'done' . "\n";
    } catch(\Exception $ex) {
        ob_end_clean();
        fputs($log, 'job is fails ' . "\n");
        fputs($log,  $ex->__toString());
        echo 'fail' . "\n";
    }
    ob_flush();
}
exit(0);