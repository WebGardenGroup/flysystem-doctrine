<?php

namespace WGG\Flysystem\Doctrine;

use DateTimeImmutable;
use const DIRECTORY_SEPARATOR;
use function dirname;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use function is_resource;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type DatabaseRecord array{
 *      path: string,
 *      type: string,
 *      visibility: string,
 *      size: int,
 *      mimetype: string,
 *      timestamp: int
 * }
 * @phpstan-type DatabaseRecordWithContents array{
 *      path: string,
 *      type: string,
 *      visibility: string,
 *      size: int,
 *      mimetype: string,
 *      timestamp: int,
 *      contents: string|resource|null
 * }
 */
final class DoctrineDBALAdapter implements FilesystemAdapter
{
    private const TYPE_DIR = 'dir';
    private const TYPE_FILE = 'file';

    private PathPrefixer $prefixer;

    private ExtensionMimeTypeDetector $mimeTypeDetector;

    public function __construct(
        private Connection $connection,
        private string $table = 'flysystem_files',
        string $prefix = ''
    ) {
        $this->prefixer = new PathPrefixer($prefix, DIRECTORY_SEPARATOR);
        $this->mimeTypeDetector = new ExtensionMimeTypeDetector();
    }

    public function fileExists(string $path): bool
    {
        return $this->exists($this->prefixer->prefixPath($path), self::TYPE_FILE);
    }

    public function directoryExists(string $path): bool
    {
        return $this->exists($this->prefixer->prefixPath($path), self::TYPE_DIR);
    }

    private function exists(string $prefixedPath, string $type): bool
    {
        return (bool) $this->connection->executeQuery(<<<SQL
SELECT EXISTS (
    SELECT
        1
    FROM {$this->table}
    WHERE
        path = :path AND
        type = :type
)
SQL,
            [
                'path' => $prefixedPath,
                'type' => $type,
            ],
            [
                'path' => ParameterType::STRING,
                'type' => ParameterType::STRING,
            ]
        )->fetchOne();
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $resource = tmpfile();
        if (false === $resource) {
            throw UnableToWriteFile::atLocation($path, error_get_last()['message'] ?? 'Unknown error occurred');
        }
        fwrite($resource, $contents);
        rewind($resource);

        $this->writeStream($path, $resource, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            // Ensure directory is created for given file path.
            $this->createDirectory(dirname($path), $config);

            /*
             * UPSERT
             * UPDATE if file exists
             * INSERT if not
             */
            if ($this->fileExists($path)) {
                $path = $this->prefixer->prefixPath($path);
                $this->connection->update($this->table,
                    [
                        'contents' => $contents,
                        'timestamp' => $config->get('timestamp', time()),
                        'visibility' => $config->get(Config::OPTION_VISIBILITY, Visibility::PUBLIC),
                    ],
                    [
                        'path' => $path,
                    ],
                    [
                        'contents' => Types::BINARY,
                        'timestamp' => Types::INTEGER,
                    ]);
            } else {
                $path = $this->prefixer->prefixPath($path);
                /* @var int|string $timestamp */
                $this->connection->insert($this->table, [
                    'path' => $path,
                    'type' => self::TYPE_FILE,
                    'timestamp' => $config->get('timestamp', time()),
                    'level' => $this->directoryLevel($path),
                    'contents' => $contents,
                    'mimetype' => $this->mimeTypeDetector->detectMimeType($path, $contents),
                    'visibility' => $config->get(Config::OPTION_VISIBILITY, Visibility::PUBLIC),
                ], [
                    'path' => ParameterType::STRING,
                    'type' => ParameterType::STRING,
                    'timestamp' => Types::INTEGER,
                    'level' => ParameterType::INTEGER,
                    'contents' => Types::BINARY,
                    'mimetype' => ParameterType::STRING,
                    'visibility' => ParameterType::STRING,
                ]);
            }

            $this->connection->executeStatement(<<<SQL
UPDATE
    {$this->table}
SET
    size = LENGTH(contents)
WHERE
    path = :path
SQL,
                [
                    'path' => $path,
                ],
                [
                    'path' => ParameterType::STRING,
                ]
            );

            fclose($contents);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        try {
            $resource = $this->readStream($path);

            $content = stream_get_contents($resource);
            if (false === $content) {
                throw new RuntimeException('Unable to read file contents');
            }

            $wasClosed = fclose($resource);
            if (!$wasClosed) {
                throw new RuntimeException('Failed to close file contents stream.');
            }

            return $content;
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path): mixed
    {
        try {
            $file = $this->getFile($path);
            if (!isset($file)) {
                throw new RuntimeException('File does not exist');
            }

            // Depending on database and PDO driver, contents could be a resource
            // or a string. In any case, we need to respect Flysystem's interface
            // and return a resource.
            $contents = $file['contents'] ?? '';
            if (is_resource($contents)) {
                return $contents;
            }

            $resource = tmpfile();
            if (false === $resource) {
                $error = error_get_last();
                throw new RuntimeException(error_get_last()['message'] ?? 'Unknown error occurred');
            }
            fwrite($resource, $contents);
            rewind($resource);

            return $resource;
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        if (!$this->fileExists($path)) {
            return;
        }

        try {
            $this->connection
                ->delete(
                    $this->table,
                    [
                        'path' => $path,
                        'type' => self::TYPE_FILE,
                    ],
                );
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        if (!$this->directoryExists($path)) {
            return;
        }
        try {
            $path = $this->prefixer->prefixPath($path);

            $queryBuilder = $this->connection
                ->createQueryBuilder();

            $expressionBuilder = $this->connection->createExpressionBuilder();

            $queryBuilder
                ->delete($this->table)
                ->where(
                    $expressionBuilder->or(
                        $expressionBuilder->eq('path', $queryBuilder->createNamedParameter($path)),
                        $expressionBuilder->like('path', $queryBuilder->createNamedParameter($path.'/%'))
                    )
                )
                ->executeStatement();
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Skip creating if path is root directory (empty)
        if (empty($path) || '.' === $path) {
            return;
        }

        $path = $this->prefixer->prefixPath($path);
        $directoryTree = [];
        $parts = explode(DIRECTORY_SEPARATOR, $path);

        $previousElement = '';
        foreach ($parts as $element) {
            if (!empty($element)) {
                $directoryTree[] = $previousElement.$element;
            }

            $previousElement .= $element.DIRECTORY_SEPARATOR;
        }

        try {
            foreach ($directoryTree as $directory) {
                if (!$this->directoryExists($this->prefixer->stripPrefix($directory))) {
                    $this->connection->insert($this->table, [
                        'path' => $directory,
                        'type' => self::TYPE_DIR,
                        'level' => $this->directoryLevel($directory),
                        'timestamp' => $config->get('timestamp', time()),
                    ], [
                        'path' => ParameterType::STRING,
                        'type' => ParameterType::STRING,
                        'level' => ParameterType::INTEGER,
                        'timestamp' => ParameterType::INTEGER,
                    ]);
                }
            }
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::dueToFailure($path, $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if (Visibility::PUBLIC !== $visibility && Visibility::PRIVATE !== $visibility) {
            throw InvalidVisibilityProvided::withVisibility($visibility,
                implode(',', [Visibility::PUBLIC, Visibility::PRIVATE]));
        }

        try {
            $rowCount = $this->connection->update($this->table,
                [
                    'visibility' => $visibility,
                ],
                [
                    'path' => $this->prefixer->prefixPath($path),
                ]
            );

            if (0 === $rowCount) {
                throw new RuntimeException(sprintf('Visibility was not changed. Unable to find file or directory: %s',
                    $path));
            }
        } catch (Throwable $e) {
            throw UnableToSetVisibility::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            return $this->getFileMeta($path);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e);
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $fileAttributes = $this->getFileMeta($path);
            $mimeType = $this->mimeTypeDetector->detectMimeTypeFromFile($fileAttributes->path());

            if (null === $mimeType) {
                throw UnableToRetrieveMetadata::mimeType($path, error_get_last()['message'] ?? '');
            }

            return new FileAttributes($path, null, null, null, $mimeType);
        } catch (UnableToReadFile $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        try {
            return $this->getFileMeta($path);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            return $this->getFileMeta($path);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $path = $this->prefixer->prefixPath($path);

        try {
            $queryBuilder = $this->connection->createQueryBuilder()
                ->from($this->table)
                ->select('path, size, mimetype, timestamp, type, visibility');

            if (!empty($path)) {
                $queryBuilder
                    ->andWhere($queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('path', $queryBuilder->createNamedParameter($path)),
                        $queryBuilder->expr()->like('path', $queryBuilder->createNamedParameter($path.'/%'))
                    ));
                if ($deep) {
                    $queryBuilder->andWhere(
                        'level >= '.$queryBuilder->createNamedParameter($this->directoryLevel($path) + 1,
                            ParameterType::INTEGER),
                    );
                } else {
                    $queryBuilder->andWhere(
                        'level = '.$queryBuilder->createNamedParameter($this->directoryLevel($path) + 1,
                            ParameterType::INTEGER),
                    );
                }
            } else {
                if (!$deep) {
                    $queryBuilder->andWhere('level = 0');
                }
            }
            $queryBuilder->orderBy('path', 'ASC');

            $result = $queryBuilder->executeQuery();

            /** @var DatabaseRecord $record */
            foreach ($result->iterateAssociative() as $record) {
                yield $this->normalizeRecord($record);
            }
        } catch (Throwable) {
            return [];
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            // Copy the file
            $this->doCopy($source, $destination, $config);

            // Remove file at source location
            $this->delete($source);
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->doCopy($source, $destination, $config);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    private function doCopy(string $source, string $destination, Config $config): void
    {
        $record = $this->getFile($source);
        if (!isset($record)) {
            throw UnableToReadFile::fromLocation($source, 'File does not exist');
        }

        // Write a new file on given destination. NOTE: If the file already exists,
        // then this will overwrite it - in accordance with Flysystem's API description:
        // @see https://flysystem.thephpleague.com/docs/usage/filesystem-api/#moving-and-copying
        $contents = $record['contents'] ?? '';

        if (is_resource($contents)) {
            $this->writeStream($destination, $contents, $config);

            fclose($contents);
        } else {
            $this->write($destination, $contents, $config);
        }
    }

    /**
     * @phpstan-return ($withContents is true ? DatabaseRecordWithContents|null : DatabaseRecord|null)
     */
    private function getFile(string $path, bool $withContents = true): ?array
    {
        try {
            $queryBuilder = $this->connection
                ->createQueryBuilder()
                ->from($this->table)
                ->select('path, size, mimetype, timestamp, type, visibility');

            if ($withContents) {
                $queryBuilder->addSelect('contents');
            }
            $queryBuilder
                ->where('path = '.$queryBuilder->createNamedParameter($this->prefixer->prefixPath($path)))
                ->andWhere('type = '.$queryBuilder->createNamedParameter(self::TYPE_FILE));

            /** @var DatabaseRecord|false $result */
            $result = $queryBuilder->executeQuery()->fetchAssociative();

            return $result ?: null;
        } catch (Throwable $e) {
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    private function getFileMeta(string $path): FileAttributes
    {
        $record = $this->getFile($path, false);

        if (!isset($record)) {
            throw UnableToReadFile::fromLocation($path, 'No such file exists.');
        }

        /** @var FileAttributes $normalised */
        $normalised = $this->normalizeRecord($record);

        return $normalised;
    }

    /**
     * @param DatabaseRecord $record
     */
    private function normalizeRecord(array $record): StorageAttributes
    {
        /* @var DateTimeImmutable $timestamp */

        return match ($record['type']) {
            self::TYPE_FILE => new FileAttributes(
                $this->prefixer->stripPrefix($record['path']),
                (int) $record['size'],
                $record['visibility'],
                (int) $record['timestamp'],
                $record['mimetype']
            ),
            self::TYPE_DIR => new DirectoryAttributes(
                $this->prefixer->stripPrefix($record['path']),
                $record['visibility'],
                (int) $record['timestamp'],
            ),
            default => throw new LogicException(sprintf('Unable to create metadata of type %s. Allowed types: %s',
                $record['type'],
                implode(', ', [self::TYPE_FILE, self::TYPE_DIR])
            ))
        };
    }

    /**
     * Returns the directory level for given path.
     *
     * Note: this method does NOT apply path-prefixing on
     * given path!
     */
    private function directoryLevel(string $path): int
    {
        return substr_count($path, '/');
    }
}
