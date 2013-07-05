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

  rjb.php --config=./rjb.conj

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
        $args['--config'] = 'rjb.conf';
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
        $config['stats'] = '/var/log/rjb.stats';
    }

    // retrieve the PID
    if ( empty($config['pid']) ) {
        $config['pid'] = '/var/run/rjb.pid';
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

    if ( ini_get('date.timezone') == '' ) {
        date_default_timezone_set('Europe/Paris');
    }

    // the status command
    if ( isset($args['--status']) ) {
        if ( empty($pid) ) {
            echo 'The RJB service is NOT running' . "\n";
        } else {
            if ( posix_kill($pid, 0) ) { // only check the process
                echo 'The RJB service is running ...' . "\n";
            } else {
                echo 'WARNING The RJB process seems to be crashed (use --stop)' . "\n";
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
            if ( !posix_kill($pid, SIGQUIT) ) {
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
            echo 'RJB is not actually running' . "\n";
        }
        if ( !isset($args['--restart']) ) {
            exit(0);
        }
    }

    // run as daemon
    if ( isset($args['--start']) ) {
        if ( !empty($pid) && posix_kill($pid, 0) ) {
            echo 'RJB is actually running (PID:' . $pid .')' . "\n";
            exit(1);
        }
        $child = pcntl_fork();
        if ($child == -1) {
            echo 'ERROR : Unable to start RJB' . "\n";
            exit(1);
        } elseif( $child ) {
            echo 'RJB is started (PID:' . $child . ')' . "\n";
            exit(0);
        }
    } else {
        echo 'ERROR : Expecting a command (see --help)' . "\n";
        exit(1);
    }

    // init the process
    posix_setsid();
    chdir('/');
    umask(0);
    if ( !empty($pid) && posix_kill($pid, 0) ) {
        echo 'RJB is actually running (PID:' . $pid .')' . "\n";
        exit(1);
    }
    file_put_contents($config['pid'], posix_getpid());

    // script body
    class rjb {
        public static $conf;
        public static $run = true;
        public static $stats = array(
            'start' => null,
            'memory' => null,
            'counters' => array(
                'workers' => null,
                'queue' => null,
                'done' => null,
                'fail' => null
            )
        );
        static function log( $data ) {
            if (!empty(self::$conf['log']) ) {
                $f = fopen( self::$conf['log'], 'a+');
                fputs($f, date('Y-m-d H:i:s') . "\t" . $data . "\n");
                fclose($f);
            }
        }
        // closing the process
        static function close() {
            self::$run = false;
        }
        // bootstrap
        static function init( $conf) {
            self::$conf = $conf;
            self::log('Starting RJB');
            register_shutdown_function(function() {
                rjb::log('Shutdown requested');
                unlink( rjb::$conf['pid'] );
            });
            pcntl_signal(SIGQUIT, array('rjb', 'close'));
            pcntl_signal(SIGINT, array('rjb', 'close'));
            self::$stats['start'] = time();
            // the main loop
            while(self::$run) {
                self::$stats['memory'] = memory_get_usage(true);
                usleep(100 * 1000); // wait 100 ms
            }
            exit(0);
        }
    }
    // bootstrap the script
    rjb::init( $config );

