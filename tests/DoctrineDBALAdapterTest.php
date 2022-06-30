<?php

namespace WGG\Flysystem\Doctrine\Tests;

use function dirname;
use Doctrine\DBAL\DriverManager;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;
use WGG\Flysystem\Doctrine\DoctrineDBALAdapter;

/**
 * @covers \WGG\Flysystem\Doctrine\DoctrineDBALAdapter
 */
class DoctrineDBALAdapterTest extends FilesystemAdapterTestCase
{
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $connection = DriverManager::getConnection([
            'url' => 'sqlite:///:memory:',
        ]);

        $connection->executeStatement((string) file_get_contents(dirname(__DIR__).'/schema/sqlite.sql'));

        return new DoctrineDBALAdapter($connection);
    }
}
