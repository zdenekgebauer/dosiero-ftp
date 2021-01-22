<?php

declare(strict_types=1);

namespace DosieroFtp;

use DirectoryIterator;
use Dosiero\File;
use Dosiero\FileInterface;
use JsonException;

use function is_array;

class Cache
{

    private const CACHE_TTL = 3600;

    private string $cacheDirectory;

    public function __construct(string $cacheDirectory)
    {
        $this->cacheDirectory = rtrim($cacheDirectory, '/') . '/';
    }

    /**
     * @param string $path
     * @param array<FileInterface> $files
     * @throws JsonException
     */
    public function saveFiles(string $path, array $files): void
    {
        $cache = [];
        foreach ($files as $file) {
            $cache[$file->getName()] = $this->serializeFile($file);
        }
        ksort($cache);
        $cacheFile = $this->getCacheFile($path);
        file_put_contents($cacheFile, json_encode($cache, JSON_THROW_ON_ERROR));
    }

    private function serializeFile(FileInterface $file): array
    {
        return [
            'name' => $file->getName(),
            'type' => $file->getType(),
            'size' => $file->getSize(),
            'modified' => $file->getModified(),
            'width' => $file->getWidth(),
            'height' => $file->getHeight(),
            'thumbnail' => $file->getThumbnail(),
        ];
    }

    public function getFiles(string $path): ?array
    {
        $cacheFile = $this->getCacheFile($path);
        if (!is_file($cacheFile) || filemtime($cacheFile) <= (time() - self::CACHE_TTL)) {
            return null;
        }

        try {
            $cache = json_decode((string)file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return null;
        }
        $result = [];
        foreach ($cache as $item) {
            $file = new File($item['name'], $item['type']);
            $file->setSize($item['size']);
            $file->setModified($item['modified'] ?? '');
            $file->setWidth($item['width']);
            $file->setHeight($item['height']);
            $file->setThumbnail($item['thumbnail']);
            $result[$file->getName()] = $file;
        }
        return $result;
    }

    private function getCacheFile(string $path): string
    {
        $fileName = str_replace('/', '_', $path);
        $fileName = empty($fileName) ? '_' : $fileName;
        return $this->cacheDirectory . $fileName . '.json';
    }

    public function update(string $path, File $file): void
    {
        $files = $this->getFiles($path);
        $files[$file->getName()] = $file;
        ksort($files);
        $this->saveFiles($path, $files);
    }

    public function delete(string $path, string $fileName): void
    {
        $files = $this->getFiles($path);
        unset($files[$fileName]);
        $this->saveFiles($path, is_array($files) ? $files : []);
    }

    public function cleanExpiredCache(): void
    {
        $limit = time() - self::CACHE_TTL;

        $iterator = new DirectoryIterator($this->cacheDirectory);
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getMTime() < $limit) {
                unlink((string)$fileInfo->getRealPath());
            }
        }
    }
}
