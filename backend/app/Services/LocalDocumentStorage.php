<?php

namespace App\Services;

use App\Contracts\DocumentStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;
use RuntimeException;

class LocalDocumentStorage implements DocumentStorage
{
    public function __construct(private readonly FilesystemAdapter $disk) {}

    public function put(string $sourcePath): string
    {
        // Копия ложится на тот же том, что и цель: link() атомарен только в пределах одной файловой системы
        $this->disk->makeDirectory('tmp');
        $temporaryPath = $this->disk->path('tmp/'.Str::uuid());

        try {
            $this->copyDurably($sourcePath, $temporaryPath);

            // Дайджест считается по копии, а не по источнику: под адресом гарантированно лежат именно эти байты
            $digest = hash_file('sha256', $temporaryPath);
            $relativePath = $this->rawPath($digest);
            $this->disk->makeDirectory(dirname($relativePath));

            // link(), а не rename(): rename молча перезаписывает цель, link отказывает, если blob уже опубликован
            if (! @link($temporaryPath, $this->disk->path($relativePath)) && ! $this->disk->exists($relativePath)) {
                throw new RuntimeException("Unable to publish document blob {$digest}.");
            }
        } finally {
            @unlink($temporaryPath);
        }

        return $digest;
    }

    public function get(string $digest)
    {
        return $this->disk->readStream($this->rawPath($digest));
    }

    public function exists(string $digest): bool
    {
        return $this->disk->exists($this->rawPath($digest));
    }

    public function delete(string $digest): void
    {
        $this->disk->delete($this->rawPath($digest));
    }

    public function putExtracted(string $digest, string $json): void
    {
        $relativePath = $this->extractedPath($digest);
        $this->disk->makeDirectory(dirname($relativePath));

        // ADR-0026: воркеру виден только extracted/, поэтому временный файл лежит там же; rename атомарно заменяет прошлое извлечение
        $temporaryPath = $this->disk->path('extracted/.tmp-'.Str::uuid());

        try {
            $target = fopen($temporaryPath, 'xb');

            try {
                if ($target === false || fwrite($target, $json) !== strlen($json) || ! fsync($target)) {
                    throw new RuntimeException("Unable to write extraction of {$digest}.");
                }
            } finally {
                is_resource($target) && fclose($target);
            }

            if (! rename($temporaryPath, $this->disk->path($relativePath))) {
                throw new RuntimeException("Unable to publish extraction of {$digest}.");
            }
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function getExtracted(string $digest): string
    {
        return $this->disk->get($this->extractedPath($digest));
    }

    private function extractedPath(string $digest): string
    {
        return 'extracted/'.substr($digest, 0, 2).'/'.$digest.'.json';
    }

    private function rawPath(string $digest): string
    {
        return 'raw/'.substr($digest, 0, 2).'/'.$digest;
    }

    private function copyDurably(string $sourcePath, string $targetPath): void
    {
        $source = fopen($sourcePath, 'rb');
        $target = fopen($targetPath, 'xb');

        try {
            if ($source === false || $target === false || stream_copy_to_stream($source, $target) === false || ! fsync($target)) {
                throw new RuntimeException("Unable to copy {$sourcePath} into document storage.");
            }
        } finally {
            is_resource($source) && fclose($source);
            is_resource($target) && fclose($target);
        }
    }
}
