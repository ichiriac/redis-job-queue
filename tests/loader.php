<?php
require_once( __DIR__ . '/../src/RedisClient.php' );
require_once( __DIR__ . '/../src/RedisQueue.php' );

// init
$queue = new RedisQueue(
    new RedisClient('tcp://127.0.0.1:6379', 0, null)
);
define('LIMIT', 1);
// add jobs
echo 'Loads some workers ('.LIMIT.')...' . "\n";
$start = microtime(true);
$jobs = array();
for( $i = 0; $i < LIMIT; $i++) {
    $jobs[] = $queue->doSleep(
        5, 'John' . $i
    );
}

echo '...done in ' . round( microtime(true) - $start, 3) . 'sec (wait them) :' . "\n";
$start = microtime(true);
while(!empty($jobs)) {
    foreach($jobs as $i => $job) {
        if ( $queue->getJobStatus($job) == RedisQueue::STATE_DONE ) {
            echo 'Job ' . $job . ' finished' . "\n";
            unset($jobs[$i]);
        }
    }
    sleep(1);
}
echo '...done in ' . round( microtime(true) - $start, 3) . 'sec' . "\n";
