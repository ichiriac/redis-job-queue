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
    // events
    public $events;
    // initialize a job manager
    public function __construct(RedisJobQueue $parent, $prefix, $file, $limit = 4) {
        $this->parent = $parent;
        $this->prefix = $prefix;
        $this->limit = $limit;
        $script = realpath($file);
        if ( !$script ) {
            $script = realpath(dirname(realpath(CONFIG_FILE)) . '/' . $file);
        }
        $this->script = $script ? $script : $file;
        if ( !function_exists('event_base_new') ) {
            $this->log('Warning : You should install libevent as a PHP module');
        } else {
            $this->events = event_base_new();
        }
    }
    // destruction
    public function __destruct() {
        if ( $this->events ) {
            event_base_free($this->events);
        }
    }
    // stops all childs that not work
    public function clean() {
        foreach($this->workers as $i => $w) {
            if ( !$w->busy ) {
                unset($this->workers[$i]);
            } elseif ( time() - $w->last_job > 600 ) {
                $this->log('WARNING : kill timeout worker');
                // run timeout
                unset($this->workers[$i]);
            }
        }
        return empty($this->workers);
    }
    // helper : gets the redis instance
    public function getRedis( $required = false ) {
        $redis = $this->parent->getRedis();
        if ( $required && !$redis ) {
            // wait that redis comme back
            while( !$redis ) {
                $redis = $this->parent->getRedis();
            }
        }
        return $redis;
    }
    // helper : log some output data
    public function log( $data ) {
        $this->parent->log( '[' . $this->prefix . '] : ' . $data);
    }
    // check and select a free worker
    public function getWorker() {
        $worker = null;
        foreach($this->workers as $i => $w) {
            if ( !$w->busy ) {
                if ( !$worker ) {
                    $worker = $w;
                } else {
                    if ( time() - $w->last_job > 60 ) {
                        VERBOSE && $this->log('close inactive worker');
                        // inactive from 1 minute
                        unset($this->workers[$i]);
                    }
                }
            } else {
                if ( !$w->alive() ) {
                    // zombie
                    unset($w);
                } elseif ( time() - $w->last_job > 600 ) {
                    $this->log('WARNING : kill timeout worker');
                    // run timeout
                    unset($this->workers[$i]);
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
                VERBOSE && $this->log('Work on #' . $job['id']);
                try {
                    if ( !$worker->process($job) ) {
                        throw new \Exception(
                            'the worker is not starting (see logs)'
                        );
                    }
                } catch( \Exception $ex) {
                    $this->parent->stats['counters']['errors'] ++;
                    $this->log(
                        'Worker error #'.$job['id'].' : ' . $ex->getMessage()
                    );
                    $this->requeue( $job['id'] );
                }
                return true;
            }
        }
        if ( $this->events ) {
            event_base_loop(
                $this->events,
                EVLOOP_NONBLOCK
            );
        } else {
            foreach($this->workers as $w) $w->dispatch();
        }
        return false;
    }
    // puts the job in the queue
    public function requeue( $job ) {
        $this->parent->stats['counters']['fail'] ++;
        $redis = $this->getRedis();
        if ( !$redis ) {
            $this->log(
                'CRITICAL ERROR - The job #'
                . $job
                . ' was NOT REQUEUED (possible loss of data)'
            );
            return null;
        } else {
            try {
                $redis
                    ->hdel(
                        $this->prefix . '.pending',
                        $job
                    )
                    ->hincrby(
                        'rjq.' . $job
                        , 'try'
                        , 1
                    )
                    ->hmset(
                        'rjq.' . $job,
                        array(
                            'state' => 'error',
                            'time' => time()
                        )
                    )->lpush(
                        $this->prefix . '.queue', $job
                    )->read()
                ;
            } catch(\Exception $pushEx) {
                $this->log(
                    'Error : possible loss of task #' . $job
                );
                if ( VERBOSE ) {
                    $this->log(
                        $pushEx->__toString()
                    );
                }
            }
        }
        $this->parent->stats['counters']['fail'] ++;
    }
    // gets a new job
    public function getJob() {
        $redis = $this->getRedis(true);
        $job = $redis->rpop( $this->prefix . '.queue' )->read();
        if ( !empty($job) ) {
            try {
                $meta = array();
                $props = $redis->hgetall('rjq.' . $job)->read();
                for($i = count($props); $i > 0; $i -= 2) {
                    $meta[$props[$i - 2]] = $props[$i - 1];
                }
                if ($meta['state'] == 'error') {
                    if ( time() < $meta['time'] + $meta['try'] ) {
                        $redis->lpush( $this->prefix . '.queue', $job )->read();
                        return null;
                    }
                }
                if ( $meta['state'] == 'error' || $meta['state'] == 'new') {
                    return $meta;
                } else {
                    $this->log(
                        'Notice : Job #' . $job
                        . ' state is irrelevant : '
                        . $meta['state']
                    );
                    return null;
                }
            } catch(\Exception $ex) {
                $this->requeue($job);
                throw $ex;
            }
        } else return null;
    }
}