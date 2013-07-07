redis-job-queue
===============

RJQ is a job queue manager &amp; worker based on Redis and PHP

# Install as a daemon

## Checkout :

```bash
  mkdir cd /etc/rjq
  cd /etc/rjq
  git checkout https://github.com/ichiriac/redis-job-queue.git ./
```

## Install it on your system :

```bash
  cp /etc/rjq/bin/rjq_init /etc/init.d/rjq
  ln -s /etc/init.d/rjq /usr/local/bin/rjq
  sudo update-rc.d rjq defaults
```

# How to ?

Start to work :

  `rjq --start`

Stop to work :

  `rjq --stop`

Restart (after any configuration change)

 `rjq --restart`

Get some stats :

  `rjq --status`

## MIT License

Copyright (C) <2012> <PHP Hacks Team : http://coderwall.com/team/php-hacks>

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
