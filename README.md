# crond
Distributed Cron Daemon with PHP

## Requirements
* PHP 7.0+
* aws/aws-sdk-php
* mtdowling/cron-expression
* psr/log

## Usage

- Set your cron config,
``` php
$crons = [
    'my_cron_id1' => [
        'expression' => '* * * * *',
        'cmd' => '/usr/bin/php /pathto/myproject/mycron.php',
        'locktime' => 0 //No need lock
    ],
    'my_cron_id2' => [
        'expression' => '*/10 * * * *',
        'cmd' => '/usr/bin/php /pathto/myproject/minutecron.php',
        'locktime' => 600 //Auto Lock for 10 minutes
    ],
    'my_cron_id2' => [
         'expression' => '* * * * *',
         'cmd' => '/usr/bin/php /pathto/myproject/infinitecron.php',
         'locktime' => -1 //Manual Lock
    ]
]
```
- Create your Locker class \Teknasyon\Crond\Locker\MemcachedLocker or \Teknasyon\Crond\Locker\RedisLocker
- Create \Teknasyon\Crond\Worker with cron config and Locker class

``` php
<?php

use Teknasyon\Crond\Locker\RedisLocker;
use Teknasyon\Crond\Worker;

try {
$worker = new Worker($cronConfig, $locker);
$worker->setLogger($myPsrLoggerInterfacedObj);
$worker->run();

} catch (\Exception $e) {
    //Error handling
}

// ...
```

