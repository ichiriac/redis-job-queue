<?php

/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */


// require dependencies
require_once __DIR__ . '/RedisClient.php';
require_once __DIR__ . '/JobManager.php';
require_once __DIR__ . '/JobWorker.php';

/**
 * Main script body
 */
class RedisJobQueue {
    // the current hostname
    public $host;
    // configuration
    public $conf;
    // running flag on main loop
    public $run = true;
    // the current process id
    public $pid;
    // statistics
    public $stats = array(
        'init' => null,
        'start' => null,
        'memory' => null,
        'counters' => array(
            'workers' => 0,
            'queue' => 0,
            'done' => 0,
            'fail' => 0,
            'errors' => 0
        )
    );
    // instance of redis connection
    private $redis;
    // list of job dispatchers
    private $jobs = array();
    // gets the last flush timestamp
    private $last_flush = null;
    // initialize the job manager
    public function __construct( array $conf ) {
        $this->conf = $conf;
        $this->host = php_uname('n');
        $this->pid = posix_getpid();
        $this->stats['init'] = time();
    }
    /**
     * Gets the redis connection
     * @return RedisClient
     */
    static public function getRedis() {
        if ( !$this->redis ) {
            try {
                $this->redis = new RedisClient(
                    $this->conf['server']['dsn'],
                    $this->conf['server']['db'],
                    $this->conf['server']['pwd']
                );
            } catch(\Exception $ex) {
                $this->log('Redis error : ' . $ex->getMessage());
                $this->stats['counters']['errors'] ++;
                // wait 1sec before retry
                $this->wait(1000);
                return null;
            }
        }
        return $this->redis;
    }
    // outputs some log
    public function log( $data ) {
        if (!empty($this->conf['log']) ) {
            $f = fopen( $this->conf['log'], 'a+');
            fputs($f, date('Y-m-d H:i:s') . "\t" . $data . "\n");
            fclose($f);
        }
    }
    // make a pause and wait
    public function wait($msec = 0) {
        pcntl_signal_dispatch();
        if ( !empty($msec) ) {
            usleep($msec * 1000);
        }
        if ( time() > $this->last_flush + 10) {
            // flushing and do extra jobs every 10 seconds
            $this->last_flush = time();
            // write stats as a file
            if ( !empty($this->conf['stats']) ) {
                $this->stats['memory'] = memory_get_usage(true);
                file_put_contents(
                    $this->conf['stats'], json_encode(
                        $this->stats
                    )
                );
            }
            // send stats to redis
            $redis = $this->getRedis();
            if ( $redis ) {
                try {
                    $redis->hmset(
                        'rjq.stats.' . $this->host,
                        array(
                            'status' => 'run',
                            'memory' => $this->stats['memory'],
                            'nb.workers' => $this->stats['counters']['workers'],
                            'nb.done' => $this->stats['counters']['done'],
                            'nb.fail' => $this->stats['counters']['fail'],
                            'nb.errors' => $this->stats['counters']['errors']
                        )
                    );
                } catch(\Exception $ex) {
                    $this->log(
                        'Fail to flush stats on redis :' . $ex->getMessage()
                    );
                }
            }
        }
    }
    // starts the job
    public function start( $conf) {
        $this->log('Starting RJQ (PID:' . $this->pid . ')');
        $this->stats['start'] = time();
        // the main loop
        while($this->run) {
            foreach($this->jobs as $job) {
                try {
                    $job->dispatch();
                } catch(\Exception $ex) {
                    $this->log(
                        'Job manager error : ' . $ex->getMessage()
                    );
                    $this->stats['counters']['errors'] ++;
                }
            }
            // wait 10 ms
            self::wait(10);
        }
        $this->log('LOOP END : Wait to close each job queue');
        // wait each child to be stopped
        while(!empty($this->jobs)) {
            foreach($this->jobs as &$job) {
                if ( $job->clean() ) {
                    $this->log('The queue "'.$job->prefix.'" is closed');
                    unset($job);
                }
            }
        }
    }
    // force to stop all workers in progress
    public function stop() {
        if ( !empty($this->jobs) ) {
            $this->log('Forcing to stop job queue :');
            foreach($this->jobs as $job) {
                if ( !empty($job->workers) ) {
                    $busy = 0;
                    foreach($job->workers as &$w) {
                        if ( $w->busy ) {
                            $busy ++;
                        }
                        unset($w);
                    }
                    if ( $busy > 0 ) {
                        $this->log(
                            'The queue "'.$job->prefix.'" is stopped with '
                            . $busy . ' pending workers'
                        );
                    } else {
                        $this->log('The queue "'.$job->prefix.'" is OK');
                    }
                } else {
                    $this->log('The queue "'.$job->prefix.'" is OK');
                }
                unset($job);
            }
        }
    }
}
