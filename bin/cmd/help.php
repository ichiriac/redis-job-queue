<?php
/**
 * This file is distributed under the MIT Open Source
 * License. See README.MD for details.
 * @author Ioan CHIRIAC
 */

echo <<<CLI
Sample :

  ./rjq.php --config=/etc/rjq/config.json

Usage :

  --config   Specify the configuration file that contains server configuration
  --status   Display the job manager status
  --start    Starts the job manager (on daemon mode)
  --stop     Stops the job manager
  --restart  Restarts the job manager

CLI;
