# WGG\Flysystem\Doctrine

This is a Flysystem adapter for Doctrine DBAL.

## Installation

```bash
composer require wgg/flysystem-doctrine
```

## Bootstrap

``` php
<?php
use Doctrine\DBAL\DriverManager;
use League\Flysystem\Filesystem;
use WGG\Flysystem\Doctrine\DoctrineDBALAdapter;

$connectionParams = [
    'url' => 'mysql://user:secret@localhost/mydb',
];
$connection = DriverManager::getConnection($connectionParams);

$adapter = new DoctrineDBALAdapter($connection, 'flysystem_files');
$filesystem = new Filesystem($adapter);
```

## Database
At the beginning you have to create a table that will be used to store files.
SQL table schema can be found in the [`schema`](schema) folder
