# RJQ (Redis Job Queue) for PHP

RJQ is a job queue manager based on Redis and PHP. It's really small, fault tolerant, and quite 
simple to install and use.

## Quick start (install it as a daemon)

```bash
  mkdir /etc/rjq
  git checkout https://github.com/ichiriac/redis-job-queue.git /etc/rjq
  cp /etc/rjq/bin/rjq_init /etc/init.d/rjq
  ln -s /etc/init.d/rjq /usr/local/bin/rjq
  sudo update-rc.d rjq defaults
  rjq --start
```

You can find more information on the [Installation wiki page](https://github.com/ichiriac/redis-job-queue/wiki/Install)

## Launch a job with a script

Write your job into a file `/var/my-app/workers/sleep.php` :
```php
<?php
// This script is defines your job
function doSleep( $duration, $name ) {
    sleep($duration);
    echo 'Hello ' . $name;
}
```

Define the job on RJQ configuration `/etc/rjq/config.json` :
```json
{
   ...
    ,"jobs": [
        {
            "name": "doSleep",
            "file": "/var/my-app/workers/sleep.php",
            "workers": 8
        }
    ]
   ...
}
```

You can find more information on the [Configuration wiki page](https://github.com/ichiriac/redis-job-queue/wiki/Configuring-RJQ)

**NOTE :** You need to restart RJQ after each configuration modification :

```bash
  $ rjq --restart
```

Call this job from your application scripts (a controller action for example)
```php
<?php
require_once( '/etc/rjq/src/RedisClient.php' );
require_once( '/etc/rjq/src/RedisQueue.php' );
// init the RJQ manager
$queue = new RedisQueue(
    new RedisClient('tcp://127.0.0.1:6379', 0, null)
);
// call your job
$job = $queue->doSleep( 10, 'John Doe');
// gets the task status
echo 'Job ' . $job . ' is : ' . $queue->getJobStatus($job);
```

And `voilà` :)

## MIT License

Copyright (C) 2012 - Ioan CHIRIAC

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
