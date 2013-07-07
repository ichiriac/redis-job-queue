<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */

echo <<<CLI
Sample :

  ./rjq.php --config=./rjq.conj

Usage :

  --config  Specify the configuration file that contains server configuration
  --status  Display the job manager status
  --start   Starts the job manager (if it's on daemon mode)
  --stop    Stops the job manager

CLI;
