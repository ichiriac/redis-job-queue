#!/usr/bin/env php
<?php
require_once( __DIR__ . '/../src/RedisClient.php' );
require_once( __DIR__ . '/../src/RedisQueue.php' );

// init
$queue = new RedisQueue(
    new RedisClient('tcp://127.0.0.1:6379', 0, null)
);
define('LIMIT', empty($argv[1]) ? 10 : $argv[1]);
define('RAND', empty($argv[2]) ? 1 : $argv[2]);
// add jobs
echo 'Loads some workers ('.LIMIT.')...' . "\n";
$start = microtime(true);
$jobs = array();
for( $i = 0; $i < LIMIT; $i++) {
    $jobs[] = $queue->doSleep(
        rand(1, RAND), 'John' . $i
    );
}

echo '...done in ' . round( microtime(true) - $start, 3) . 'sec (wait them) :' . "\n";
$start = microtime(true);
$started = array();
while(!empty($jobs)) {
    foreach($jobs as $i => $job) {
        /*if ( !isset($started[$i]) && $queue->getJobStatus($job) == RedisQueue::STATE_PROGRESS ) {
            echo 'Starting ' . $job . ' at ' .  round( microtime(true) - $start, 3) . 'sec' . "\n";
            $started[$i] = $job;
        } else*/
        if ( $queue->getJobStatus($job) == RedisQueue::STATE_DONE ) {
            //echo 'Job ' . $job . ' finished' . "\n";
            unset($jobs[$i]);
        } /* */
        usleep(1000);
    }
}
echo '...done in ' . round( microtime(true) - $start, 3) . 'sec' . "\n";
