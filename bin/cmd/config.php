<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */
define('CONFIG_FILE', $arg);
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
// setting default timezone (avoid warnings)
if ( ini_get('date.timezone') == '' ) {
    date_default_timezone_set('Asia/Yekaterinburg');
}

return $config;