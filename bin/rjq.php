#!/usr/bin/env php
<?php
    $args = array();
    parse_str(implode('&', array_slice($argv, 1)), $args);
    // shows the help
    if ( isset($args['--help']) ) {
        echo <<<CLI
Redis Job Queue - by Ioan Chiriac (Under MIT 2013)
This script handles job queues and launches workers
Url : https://github.com/ichiriac/redis-job-queue

Sample :

  ./rjq.php --config=./rjq.conj

Usage :

  --config  Specify the configuration file that contains server configuration
  --status  Display the job manager status
  --start   Starts the job manager (if it's on daemon mode)
  --stop    Stops the job manager

CLI;
        exit(0);
    }
    // read the configuration
    if ( !isset($args['--config']) ) {
        $args['--config'] = 'rjq.conf';
    }
    if ( file_exists($args['--config']) ) {
        $config = file_get_contents($args['--config']);
    } else {
        echo 'ERROR : Unable to read the configuration : ' . $args['--config'] . "\n";
        exit(1);
    }
    $config = json_decode($config, true);
    if ( empty($config) ) {
        echo 'ERROR : JSON syntax error in : ' . $args['--config'] . "\n";
        exit(1);
    }

    // define some defaults values
    if ( empty($config['stats']) ) {
        $config['stats'] = '/var/log/rjq.stats';
    }

    // retrieve the PID
    if ( empty($config['pid']) ) {
        $config['pid'] = '/var/run/rjq.pid';
    }
    $pid = null;
    if ( file_exists($config['pid']) ) {
        $pid = file_get_contents($config['pid']);
    }

    /*
     * Convert seconds to human readable text.
     *
     */
    function secs_to_h($secs)
    {
        $units = array(
            "week"   => 7*24*3600,
            "day"    =>   24*3600,
            "hour"   =>      3600,
            "minute" =>        60,
            "second" =>         1,
        );
        // specifically handle zero
        if ( $secs == 0 ) return "0 seconds";
        $s = "";
        foreach ( $units as $name => $divisor ) {
                if ( $quot = intval($secs / $divisor) ) {
                        $s .= "$quot $name";
                        $s .= (abs($quot) > 1 ? "s" : "") . ", ";
                        $secs -= $quot * $divisor;
                }
        }
        return substr($s, 0, -2);
    }

    // setting default timezone (avoid warnings)
    if ( ini_get('date.timezone') == '' ) {
        date_default_timezone_set('Europe/Paris');
    }

    // the status command
    if ( isset($args['--status']) ) {
        if ( empty($pid) ) {
            echo 'The RJQ service is NOT running' . "\n";
        } else {
            if ( posix_kill($pid, 0) ) { // only check the process
                echo 'The RJQ service is running ...' . "\n";
            } else {
                echo 'WARNING : The RJQ process seems to be crashed (use --stop)' . "\n";
            }
            // showing statistics
            if (
                !empty($config['stats'])
                && file_exists($config['stats'])
            ) {
                $stats = json_decode(file_get_contents(
                    $config['stats']
                ), true);
                if ( !empty($stats) ) {
                    echo 'Started at ' . date('Y-m-d H:i:s', $stats['start']);
                    echo ' - ' . secs_to_h( time() - $stats['start']) . "\n\n";
                    echo 'Memory   : ' . round($stats['memory'] / 1024 / 1024, 2) . 'MB' . "\n";
                    echo 'Workers  : ' . $stats['counters']['workers'] . "\n";
                    echo 'Queue    : ' . $stats['counters']['queue'] . "\n";
                    echo 'Jobs     : '
                        . round($stats['counters']['done'] / 1000, 1) . 'M done, '
                        . $stats['counters']['fail'] . ' fails '
                        . "\n";
                }
            }
        }
        exit(0);
    }

    // restart as daemon
    if ( isset($args['--restart']) ) {
        $args['--stop'] = true;
        $args['--start'] = true;
    }
    // stops the daemon
    if ( isset($args['--stop']) ) {
        if ( !empty($pid) ) {
            echo 'Stopping ...';
            if ( !posix_kill($pid, SIGTERM) ) {
                echo "\n" . 'WARNING : Process crashed (PID:' . $pid . ')' ;
                unlink( $config['pid'] );
            }
            for( $i = 0; $i < 20; $i++) {
                if ( !file_exists($config['pid']) ) {
                    break;
                }
                echo '.';
                sleep(1);
            }
            echo "\n";
            if ( file_exists($config['pid']) ) {
                echo 'ERROR : Unable to stop the process (PID:' . $pid . ')' ."\n";
                exit(1);
            }
            $pid = 0;
        } else {
            echo 'RJQ is not actually running' . "\n";
        }
        if ( !isset($args['--restart']) ) {
            exit(0);
        }
    }

    // run as daemon
    if ( isset($args['--start']) ) {
        if ( !empty($pid) && posix_kill($pid, 0) ) {
            echo 'RJQ is actually running (PID:' . $pid .')' . "\n";
            exit(1);
        }
        $child = pcntl_fork();
        if ($child == -1) {
            echo 'ERROR : Unable to start RJQ' . "\n";
            exit(1);
        } elseif( $child ) {
            echo 'RJQ is started (PID:' . $child . ')' . "\n";
            exit(0);
        }
    } else {
        echo 'ERROR : Expecting a command (see --help)' . "\n";
        exit(1);
    }

    // init the process
    if (posix_setsid() === -1) {
        echo 'ERROR : could not setsid' . "\n";
        exit(1);
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
        , 'a'
    );
    chdir('/');
    umask(0);
    // lock the child process
    if ( !empty($pid) && posix_kill($pid, 0) ) {
        echo 'RJQ is actually running (PID:' . $pid .')' . "\n";
        exit(1);
    }
    file_put_contents($config['pid'], posix_getpid());

    // script body
    class rjq {
        static public $conf;
        static public $run = true;
        static public $stats = array(
            'start' => null,
            'memory' => null,
            'counters' => array(
                'workers' => null,
                'queue' => null,
                'done' => null,
                'fail' => null
            )
        );
        // outputs some log
        static public function log( $data ) {
            if (!empty(self::$conf['log']) ) {
                $f = fopen( self::$conf['log'], 'a+');
                fputs($f, date('Y-m-d H:i:s') . "\t" . $data . "\n");
                fclose($f);
            }
        }
        // starts the job
        static public function start( $conf) {
            self::$conf = $conf;
            self::log('Starting RJQ (PID:' . posix_getpid() . ')');
            self::$stats['start'] = time();
            // the main loop
            while(self::$run) {
                self::$stats['memory'] = memory_get_usage(true);
                usleep(100 * 1000); // wait 100 ms
                pcntl_signal_dispatch();
            }
        }
    }
    // bootstrap the script
    $handler = function($sig) {
        rjq::$run = false;
        rjq::log('Received a signal : ' . $sig);
    };
    register_shutdown_function(function() {
        rjq::log('Shutdown requested');
        unlink( rjq::$conf['pid'] );
    });
    pcntl_signal(SIGTSTP, SIG_IGN);
    pcntl_signal(SIGTTOU, SIG_IGN);
    pcntl_signal(SIGTTIN, SIG_IGN);
    pcntl_signal(SIGHUP, SIG_IGN);
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
    rjq::start( $config );
    exit(0);
