<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */

// worker class :
class JobWorker {
    public $busy = false;
    public $last_job = 0;
    public $task;
    private $parent;
    private $process;
    private $std_in;
    private $std_out;
    private $std_err;
    private $event;
    // initialize a worker
    public function __construct( JobManager $parent ) {
        $this->parent = $parent;
        $this->parent->parent->stats['counters']['workers'] ++;
    }
    // close the current process
    public function __destruct() {
        $this->parent->parent->stats['counters']['workers'] --;
        $this->kill();
    }
    // gets the current process : if not started create it
    public function getProcess() {
        if ( !$this->process ) {
            $descriptorspec = array(
               0 => array("pipe", "r"),
               1 => array("pipe", "w"),
               2 => array("pipe", "w")
            );
            $cmd =
                PHPBIN
                .' -f '
                . realpath(__DIR__. '/../bin/')
                . '/worker.php'
            ;
            $this->process = proc_open(
                $cmd,
                $descriptorspec,
                $pipes,
                '/tmp',
                array(
                    'prefix'    => $this->parent->prefix,
                    'bootstrap' => $this->parent->script
                )
            );
            if ( $this->process === false ) {
                $error = error_get_last();
                $this->parent->log(
                    'Error : unable to start a process - '
                    . $error['message']
                );
            } else {
                $this->std_out  = $pipes[0];
                $this->std_in   = $pipes[1];
                $this->std_err  = $pipes[2];
                stream_set_blocking($this->std_err, 0);
                $this->parent->log(
                    stream_get_contents($this->std_err)
                );
                if ( function_exists('event_new') ) {
                    $this->event = event_new();
                    event_set(
                        $this->event,
                        $this->std_in,
                        EV_READ | EV_PERSIST,
                        array($this,'onRead'),
                        array($this->event, $this->parent->events)
                    );
                    event_base_set($this->event, $this->parent->events);
                    event_add($this->event);
                }
            }
        }
        return $this->process;
    }
    // alternative way to dispatch reading events
    public function dispatch() {
        if ( $this->std_in && $this->busy ) {
            $this->onRead();
            if ( !$this->alive() ) {
                $this->parent->log('The worker process is dead');
                $this->kill();
                return;
            }
        }
    }
    // receives a message from the process
    public function onRead() {
        $cmd = stream_get_contents($this->std_in);
        $err = stream_get_contents($this->std_err);
        if ( !empty($err) ) {
            $this->parent->log('ERROR : ' . $err);
        }
        if ( empty($cmd) ) return;
        $this->parent->log('child > ' . $cmd);
        switch( $cmd ) {
            case 'done':
                $redis = $this->parent->getRedis(true);
                $key = 'rjq.' .  $this->task;
                $redis->hmset(
                    $key,
                    array(
                        'state' => 'done',
                        'time' => time(),
                        'duration' => time() - $this->last_job,
                    )
                )->read();
                $this->busy = false;
                $this->last_job = time();
                break;
            case 'error':
                $redis = $this->parent->getRedis(true);
                $key = 'rjq.' .  $this->task;
                $redis->hmset(
                    $key,
                    array(
                        'state' => 'error',
                        'time' => time()
                    )
                )->read();
                $this->busy = false;
                $this->last_job = time();
                break;
            default:
                $this->parent->log('bad worker response : ' . $cmd );
        }
    }
    // gets the current process id
    public function getPid() {
        $status = proc_get_status( $this->getProcess() );
        return isset($status['pid']) ? $status['pid'] : null;
    }
    // send some data to process
    public function send( $data ) {
        $process = $this->getProcess();
        if ( $process ) {
            if ( fwrite($this->std_out, '$' . strlen($data) . "\n") === false) {
                return false;
            }
            if ( fwrite($this->std_out, $data . "\n") === false) {
                return false;
            }
            return true;
        } else {
            return false;
        }
    }
    // process the specified task id
    public function process($task) {
        $redis = $this->parent->getRedis(true);
        $this->task = $task;
        $key = 'rjq.' .  $this->task;
        // put the task into a pending state
        if ( !$redis->hsetnx(
            $this->parent->prefix . '.pending',
            $task,
            time()
        )->read() ) {
            // already pending
            $this->parent->log(
                'Error job ' . $this->task . ' is already pending (zombi?)'
            );
            return false;
        }
        // sends the commands to the process
        try {
            if ( !$this->send( $this->task ) ) return false;
            if ( !$this->send( $redis->hget( $key, 'args' )->read() ) ) return false;
        } catch( \Exception $ex ) {
            $this->parent->log(
                'Error during at the job ' . $this->task . ' start : '
                . $ex->getMessage()
            );
            return false;
        }
        // flag the working state
        $this->busy = true;
        $this->last_job = time();
        try {
            $redis->hmset(
                $key,
                array(
                    'state' => 'progress',
                    'time' => $this->last_job,
                    'srv' => $this->parent->parent->host,
                    'pid', $this->getPid(),
                )
            )->read();
        } catch(\Exception $ex) {
            $this->parent->log(
                'Warning : could not flag the job ' . $this->task . ' state : '
                . $ex->getMessage()
            );
         }
        return true;
    }
    // kill the current process
    public function kill() {
        if ( $this->process ) {
            if ( $this->busy ) {
                $this->parent->requeue(
                    $this->task
                );
                $this->busy = false;
            }
            proc_close($this->process);
            if ( $this->event ) {
                event_del($this->event);
                event_free($this->event);
            }
            $this->process = null;
        }
    }
    // check if the process is alive or not
    public function alive() {
        if ( $this->process ) {
            $status = proc_get_status( $this->process );
            return isset($status['running']) ? $status['running'] : false;
        } else {
            return false;
        }
    }
}