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
        'lock' => 0 //No need lock
    ],
    'my_cron_id2' => [
        'expression' => '*/10 * * * *',
        'cmd' => '/usr/bin/php /pathto/myproject/minutecron.php',
        'lock' => 1
    ],
    'my_cron_id2' => [
         'expression' => '* * * * *',
         'cmd' => '/usr/bin/php /pathto/myproject/infinitecron.php' // Like lock:1
    ]
]
```
- Create your Locker class \Teknasyon\Crond\Locker\MemcachedLocker or \Teknasyon\Crond\Locker\RedisLocker
- Create \Teknasyon\Crond\Daemon with cron config and Locker class

``` php
<?php

use Teknasyon\Crond\Locker\RedisLocker;
use Teknasyon\Crond\Daemon;

try {
$crond = new Daemon($cronConfig, $locker);
$crond->setLogger($myPsrLoggerInterfacedObj);
$crond->start();

} catch (\Exception $e) {
    //Error handling
}

// ...
```

