<?php
function doSleep( $duration, $name ) {
    sleep($duration);
    echo 'Hello ' . $name;
}
