<?php

declare(strict_types=1);

namespace DosieroFtp;

use Dosiero\File;
use Dosiero\Folder;
use Dosiero\Storage;
use Dosiero\StorageException;
use Dosiero\StorageInterface;
use Dosiero\Thumbnail;
use Dosiero\Utils;
use InvalidArgumentException;
use ZdenekGebauer\FtpClient\FtpClient;
use ZdenekGebauer\FtpClient\FtpException;
use ZdenekGebauer\FtpClient\FtpFileInfo;
use ZdenekGebauer\FtpClient\FtpOptions;

use function in_array;
use function is_array;
use function strlen;

class FtpStorage extends Storage implements StorageInterface
{

    public const OPTION_HOST = 'HOST';

    public const OPTION_LOGIN = 'LOGIN';

    public const OPTION_PASSWORD = 'PASSWORD';

    public const OPTION_SSL = 'SSL';

    public const OPTION_PORT = 'PORT';

    public const OPTION_TIMEOUT = 'TIMEOUT';

    public const OPTION_BASE_PATH = 'BASE_PATH';

    public const OPTION_CACHE_DIRECTORY = 'CACHE_DIRECTORY';

    private string $host = '';

    private string $login = 'anonymous';

    private string $password = '';

    private bool $ssl = false;

    private int $port = 21;

    private int $timeout = 90;

    private string $basePath = '/';

    private Cache $cache;

    protected ?FtpClient $ftpClient = null;

    public function setOption(string $name, $value): void
    {
        switch ($name) {
            case self::OPTION_HOST:
                $this->host = (string)$value;
                break;
            case self::OPTION_LOGIN:
                $this->login = (string)$value;
                break;
            case self::OPTION_PASSWORD:
                $this->password = (string)$value;
                break;
            case self::OPTION_SSL:
                $this->ssl = (bool)$value;
                break;
            case self::OPTION_PORT:
                $this->port = (int)$value;
                break;
            case self::OPTION_TIMEOUT:
                $this->timeout = (int)$value;
                break;
            case self::OPTION_BASE_PATH:
                $this->basePath = '/' . trim((string)$value, '/');
                break;
            case self::OPTION_CACHE_DIRECTORY:
                $value = (string)$value;
                if (!is_dir($value)) {
                    throw new InvalidArgumentException('not found directory "' . $value . '"');
                }
                $this->cache = new Cache($value);
                break;
            default:
                parent::setOption($name, $value);
        }
    }

    protected function connect(): void
    {
        if (empty($this->host)) {
            throw new StorageException('invalid configuration: missing host');
        }
        if ($this->cache === null) {
            throw new StorageException('invalid configuration: cache directory is not set');
        }
        if ($this->ftpClient === null) {
            $ftpOptions = new FtpOptions();
            $ftpOptions->host = $this->host;
            $ftpOptions->username = $this->login;
            $ftpOptions->password = $this->password;
            $ftpOptions->ssl = $this->ssl;
            $ftpOptions->port = $this->port;
            $ftpOptions->timeout = $this->timeout;
            $this->ftpClient = new FtpClient();
            $this->ftpClient->connect($ftpOptions);
        }
    }

    public function getFolders(): iterable
    {
        $this->connect();
        $this->cache->cleanExpiredCache();
        $items = $this->ftpClient->tree($this->basePath);
        return $this->getSubFolders($items);
    }

    /**
     * recursive function, returns folders in given directory
     * @param iterable<FtpFileInfo> $folders
     * @return iterable<FolderInterface>
     */
    private function getSubFolders(iterable $folders): iterable
    {
        $result = [];

        $basePathLength = strlen($this->basePath);
        foreach ($folders as $folder) {
            $item = new Folder(
                $folder->name,
                substr($folder->path, $basePathLength),
                $this->getSubFolders($folder->subDirectories)
            );
            $result[] = $item;
        }
        return $result;
    }

    public function getFiles(string $path, bool $ignoreCache = false): iterable
    {
        if (!$ignoreCache) {
            $result = $this->cache->getFiles($path);
            if (is_array($result)) {
                return $result;
            }
        }

        $result = [];
        $this->connect();
        $absPath = $this->absPath($path);

        try {
            $ftpFiles = $this->ftpClient->list($absPath);
        } catch (FtpException $exception) {
            throw new StorageException('not found path "' . $path . '"', 0, $exception);
        }

        foreach ($ftpFiles as $ftpFile) {
            $file = $this->convertFtpFile($absPath, $ftpFile);

            $file->setDirectoryUrl($this->baseUrl . $path);
            $result[$file->getName()] = $file;
        }

        $this->cache->saveFiles($absPath, $result);

        return $result;
    }

    private function absPath(string $path): string
    {
        return $this->basePath . trim($path, '/');
    }

    public function mkDir(string $path, string $newFolder): void
    {
        $absPath = $this->absPath($path);
        try {
            $this->connect();
            $this->ftpClient->mkdir($absPath, $absPath . '/' . $newFolder, $this->modeDir);
            $this->cache->update($path, new File($newFolder, File::TYPE_DIR));
        } catch (FtpException $exception) {
            throw new StorageException('cannot create folder "' . $newFolder . '"', 0, $exception);
        }
    }

    public function upload(string $path, array $files): void
    {
        $absPath = $this->absPath($path);
        $noOverwritten = [];

        $this->connect();

        foreach ($files as $field) {
            if ($field['error'] !== 0) {
                throw new StorageException('upload "' . $field['name'] . '" failed', $field['error']);
            }
            $fileName = basename($field['name']);
            if ($this->normalizeNames) {
                $fileName = Utils::normalizeFileName($fileName);
            }

            if (!$this->overwriteFiles && $this->ftpClient->isFile($fileName)) {
                $noOverwritten[] = $fileName;
                continue;
            }

            $this->ftpClient->put($absPath . $fileName, $field['tmp_name']);
            $this->ftpClient->chmod($absPath . $fileName, $this->modeFile);

            $newFile = $this->getFile($absPath, $fileName);
            if ($newFile) {
                $this->cache->update($path, $newFile);
            }
        }

        if (!empty($noOverwritten)) {
            throw new StorageException('files were not overwritten: ' . implode(',', $noOverwritten));
        }
    }

    public function delete(string $path, array $files, bool &$deletedFolder): void
    {
        if (empty($files)) {
            return;
        }

        $this->connect();
        $this->ftpClient->chdir($path);

        foreach ($files as $file) {
            try {
                if ($this->ftpClient->isDirectory($file)) {
                    $this->ftpClient->rmdir($file);
                    $deletedFolder = true;
                } else {
                    $this->ftpClient->delete($file);
                }
                $this->cache->delete($path, $file);
            } catch (FtpException $exception) {
                throw new StorageException('cannot delete "' . $file . '"', 0, $exception);
            }
        }
    }

    public function rename(string $path, string $oldName, string $newName, bool &$renamedFolder): void
    {
        $absPath = $this->absPath($path);

        $this->connect();

        try {
            $this->ftpClient->rename($absPath . '/' . $oldName, $absPath . '/' . $newName);
            $renamedFolder = $this->ftpClient->isDirectory($absPath . '/' . $newName);
        } catch (FtpException $exception) {
            throw new StorageException('cannot rename "' . $oldName . '" to "' . $newName . '"', 0, $exception);
        }

        $this->cache->delete($path, $oldName);
        $newFile = $this->getFile($absPath, $newName);
        if ($newFile) {
            $this->cache->update($path, $newFile);
        }
    }

    private function getFile(string $absPath, string $fileOrFolder): ?File
    {
        $ftpFiles = $this->ftpClient->list($absPath);
        foreach ($ftpFiles as $ftpFile) {
            if ($ftpFile->name !== $fileOrFolder) {
                continue;
            }
            return $this->convertFtpFile($absPath, $ftpFile);
        }
        return null;
    }

    private function convertFtpFile(string $path, FtpFileInfo $ftpFile): File
    {
        $file = new File($ftpFile->name, $ftpFile->type);
        $file->setSize($ftpFile->size);
        $file->setModified($ftpFile->modified->format('c'));

        $ext = strtolower(pathinfo($ftpFile->name, PATHINFO_EXTENSION));
        $isWebImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true);

        if ($isWebImage) {
            $fileContent = (string)$this->ftpClient->getFileContent($path . '/' . $ftpFile->name);
            if (!empty($fileContent)) {
                $image = imagecreatefromstring($fileContent);
                if ($image) {
                    $file->setWidth((int)imagesx($image));
                    $file->setHeight((int)imagesy($image));
                }

                $thumbnail = Thumbnail::createThumbnailFromString($fileContent, $ext, 50);
                $file->setThumbnail($thumbnail);
            }
        }

        return $file;
    }

    public function copy(string $path, array $files, string $targetPath, bool &$copiedFolder): void
    {
        $path = $this->absPath($path);
        $targetPath = $this->absPath($targetPath);

        $this->connect();

        foreach ($files as $file) {
            try {
                if ($this->ftpClient->isDirectory($path . $file)) {
                    $this->ftpClient->copyDirectory($path, $path . $file, $targetPath . '/' . $file);
                    $this->cache->update($targetPath, new File($file, File::TYPE_DIR));
                    $copiedFolder = true;
                } else {
                    $this->ftpClient->copyFile($path . $file, $targetPath . '/' . $file);
                    $newFile = $this->getFile($targetPath, $file);
                    if ($newFile) {
                        $this->cache->update($targetPath, $newFile);
                    }
                }
            } catch (FtpException $exception) {
                throw new StorageException('cannot copy "' . $file . '"', 0, $exception);
            }
        }
    }

    public function move(string $path, array $files, string $targetPath, bool &$movedFolder): void
    {
        $path = $this->absPath($path);
        $targetPath = $this->absPath($targetPath);

        $this->connect();

        foreach ($files as $file) {
            try {
                $isDirectory = $this->ftpClient->isDirectory($path . $file);
                $this->ftpClient->rename($path . '/' . $file, $targetPath . '/' . $file);
                $this->cache->delete($path, $file);
                if ($isDirectory) {
                    $this->cache->update($targetPath, new File($file, File::TYPE_DIR));
                    $movedFolder = true;
                } else {
                    $newFile = $this->getFile($targetPath, $file);
                    if ($newFile) {
                        $this->cache->update($targetPath, $newFile);
                    }
                }
            } catch (FtpException $exception) {
                throw new StorageException('cannot move "' . $file . '"', 0, $exception);
            }
        }
    }
}
