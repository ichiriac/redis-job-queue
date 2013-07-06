#!/bin/sh
### BEGIN INIT INFO
# Provides: rjq
# Required-Start: $local_fs
# Required-Stop: $local_fs
# Default-Start: 2 3 4 5
# Default-Stop: 0 1 6
# Short-Description: Start Redis Job Queue daemon at boot time
# Description: This script handles job queues and launches workers
### END INIT INFO

# CONFIGURATION
PATH="/etc/rjq"
CONFIG="${PATH}/rjq.conf"

# PROPAGATE THE REQUEST
/usr/bin/php ${PATH}/bin/rjq.php --config=$CONFIG $@