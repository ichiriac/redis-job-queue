<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */

// check if another instance is actually running
if ( !empty($pid) && posix_kill($pid, 0) ) {
    echo 'ERROR(10) : RJQ is actually running (PID:' . $pid .')' . "\n";
    exit(10);
}

// starts the daemon as a fork
$child = pcntl_fork();
if ($child == -1) {
    echo 'ERROR(11) : Unable to start RJQ' . "\n";
    exit(11);
} elseif( $child ) {
    echo 'RJQ is started (PID:' . $child . ')' . "\n";
    exit(0);
}

// init the process
if (posix_setsid() === -1) {
    echo 'ERROR(12) : could not setsid' . "\n";
    exit(12);
}

// in/out/error file descriptors
fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);
$stdIn = fopen('/dev/null', 'r');
$stdOut = fopen('/dev/null', 'w');
$stdErr = fopen(
    empty($config['log']) ?
        '/dev/null': $config['log']
    , 'a+'
);
chdir('/');
umask(0);

// lock the child process
file_put_contents($config['pid'], posix_getpid());

// bootstrap the server
$instance = new RedisJobQueue(
    $config
);
// stop handler : clean way to stop the main loop
$closeHandler = function($sig) use($instance) {
    $instance->log('Received a signal : ' . $sig);
    $instance->run = false;
};
// intercept the script stop and clean the instance
register_shutdown_function(function() use($instance) {
    $instance->log('Shutdown requested');
    $instance->stop();
    unlink( $instance->$conf['pid'] );
});
// signals manager
pcntl_signal(SIGTSTP, SIG_IGN);
pcntl_signal(SIGTTOU, SIG_IGN);
pcntl_signal(SIGTTIN, SIG_IGN);
pcntl_signal(SIGHUP, SIG_IGN);
pcntl_signal(SIGTERM, $closeHandler);
pcntl_signal(SIGINT, $closeHandler);
// launch the instance
$instance->start();