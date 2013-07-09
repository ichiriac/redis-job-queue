<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */

// Handles a queue and dispatch if on a list of workers
class JobManager {
    // list of current workers
    public $workers = array();
    // instance of redis
    public $parent;
    // the queue prefix
    public $prefix;
    // max number of running childs
    public $limit;
    // the script file to be run
    public $script;
    // initialize a job manager
    public function __construct(RedisJobQueue $parent, $prefix, $file, $limit = 4) {
        $this->parent = $parent;
        $this->prefix = $prefix;
        $this->limit = $limit;
        $this->script = $file;
    }
    // stops all childs that not work
    public function clean() {
        foreach($this->workers as &$w) {
            if ( !$w->busy ) unset($w);
        }
        return empty($this->workers);
    }
    // helper : gets the redis instance
    public function getRedis() {
        return $this->parent->getRedis();
    }
    // helper : log some output data
    public function log( $data ) {
        $this->parent->log( '[' . $this->prefix . '] : ' . $data);
    }
    // check and select a free worker
    public function getWorker() {
        $worker = null;
        foreach($this->workers as &$w) {
            if ( !$w->busy ) {
                if ( !$worker ) {
                    $worker = $w;
                } else {
                    if ( time() - $w->last_job > 60 ) {
                        // inactive from 1 minute
                        unset($w);
                    }
                }
            } else {
                if ( !$w->alive() ) {
                    // zombie
                    unset($w);
                } elseif ( time() - $w->last_job > 600 ) {
                    // run timeout
                    unset($w);
                }
            }
        }
        if ( !$worker ) {
            if ( $this->limit >= count($this->workers) )  {
                $worker = new JobWorker( $this );
                $this->workers[] = $worker;
            }
        }
        return $worker;
    }
    // check if a new work is available and dispatch it to a worker
    public function dispatch() {
        $worker = $this->getWorker();
        if ( $worker ) {
            $job = $this->getJob();
            if ( $job ) {
                try {
                    if ( !$worker->process($job) ) {
                        throw new \Exception(
                            'the worker is not starting (see logs)'
                        );
                    }
                } catch( \Exception $ex) {
                    $this->parent->stats['counters']['errors'] ++;
                    $this->log(
                        'Worker error #'.$job.' : ' . $ex->getMessage()
                    );
                    $this->requeue( $job );
                }
            }
        }
    }
    // puts the job in the queue
    public function requeue( $job ) {
        $this->parent['stats']['fail'] ++;
        $redis = $this->getRedis();
        if ( $redis ) {
            $this->log(
                'CRITICAL ERROR - The job #'
                . $job
                . ' was NOT REQUEUED (possible loss of data)'
            );
            return null;
        } else {
            $redis->lpush( $this->prefix . '.queue', $job )->read();
        }
    }
    // gets a new job
    public function getJob() {
        $redis = $this->getRedis();
        if ( $redis ) return null;
        return $redis->rpop( $this->prefix . '.queue' )->read();
    }
}