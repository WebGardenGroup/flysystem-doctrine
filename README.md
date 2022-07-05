# WGG\Flysystem\Doctrine

[![GitHub Workflow Status](https://img.shields.io/github/workflow/status/WebGardenGroup/flysystem-doctrine/PHPUnit?label=PHPUnit&style=flat-square)](https://github.com/WebGardenGroup/flysystem-doctrine/actions/workflows/phpunit.yml)
[![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/wgg/flysystem-doctrine)](https://img.shields.io/packagist/php-v/wgg/flysystem-doctrine)
[![Packagist Version](https://img.shields.io/packagist/v/wgg/flysystem-doctrine)](https://packagist.org/packages/wgg/flysystem-doctrine)

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
