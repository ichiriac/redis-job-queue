<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */

// Dispatch jobs on redis instance
class RedisQueue {
    /**
     * @var RedisClient The REDIS connector
     */
    protected $redis;
    // define a list of states
    const STATE_UNDEF       = 'undef';
    const STATE_WAIT        = 'new';
    const STATE_PROGRESS    = 'progress';
    const STATE_DONE        = 'done';
    const STATE_ERROR       = 'error';
    /**
     * Initialize the job dispatcher
     */
    public function __construct( RedisClient $client ) {
        $this->redis = $client;
    }
    /**
     * Process the specified task, with specified arguments
     * @param string The job name
     * @param array List of parameters
     * @return string
     */
    public function __call( $task, $args ) {
        $job = md5(uniqid(php_uname('n'), true));
        $this->redis
            ->hmset(
                'rjq.' . $job,
                array(
                    'state' => 'new',
                    'time' => time(),
                    'args' => json_encode($args)
                )
            )
            ->lpush(strtolower($task) . '.queue', $job)
            ->read()
        ;
        return $job;
    }
    /**
     * Gets the status for the specified job
     */
    public function getJobStatus( $job ) {
        try {
            $state = $this->redis->hget( 'rjq.' . $job, 'state')->read();
            if ( empty($state) ) $state = self::STATE_UNDEF;
            return $state;
        } catch(\Exception $ex) {
            return self::STATE_UNDEF;
        }
    }
}
