<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */

if ( file_exists($arg) ) {
    $config = file_get_contents($arg);
} else {
    echo 'ERROR : Unable to read the configuration : ' . $arg . "\n";
    exit(2);
}
$config = json_decode($config, true);
if ( empty($config) ) {
    echo 'ERROR : JSON syntax error in : ' . $arg . "\n";
    exit(3);
}
// define some defaults values
if ( empty($config['pid']) ) {
    $config['pid'] = '/var/run/rjq.pid';
}
// setting default timezone (avoid warnings)
if ( ini_get('date.timezone') == '' ) {
    date_default_timezone_set('Europe/Paris');
}

return $config;