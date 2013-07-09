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
    // initialize a worker
    public function __construct( JobManager $parent ) {
        $this->parent = $parent;
        $this->parent['stats']['workers'] ++;
    }
    // close the current process
    public function __destruct() {
        $this->parent['stats']['workers'] --;
        $this->kill();
    }
    // gets the current process : if not started create it
    public function getProcess() {
        if ( !$this->process ) {
            $descriptorspec = array(
               0 => array("pipe", "r"),
               1 => array("pipe", "w"),
               2 => array("pipe", "a")
            );
            $this->process = proc_open(
                'php -f '
                . dirname($_SERVER['PHP_SELF'])
                . '/worker.php',
                $descriptorspec,
                $pipes,
                '/tmp',
                array(
                    'config'    => $this->parent->parent->conf,
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
            }
        }
        return $this->process;
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
        $redis = $this->parent->getRedis();
        if ( $redis ) return false;
        $this->task = $task;
        $key = 'rjq.' .  $this->task;
        // put the task into a pending state
        if ( !$redis->hsetnx(
            $this->parent->prefix . '.pending',
            $task,
            time()
        ) ) {
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
            }
            proc_close($this->process);
            $this->process = null;
        }
    }
    // check if the process is alive or not
    public function alive() {
        if ( $this->process ) {
            $status = proc_get_status( $this->get );
            return isset($status['running']) ? $status['running'] : false;
        } else {
            return false;
        }
    }
}