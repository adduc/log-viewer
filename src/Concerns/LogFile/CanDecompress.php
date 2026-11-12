<?php

namespace Opcodes\LogViewer\Concerns\LogFile;

use Opcodes\LogViewer\Exceptions\CannotOpenFileException;
use Opcodes\LogViewer\Utils\Utils;

/**
 * Adds support for gzip-compressed log files (e.g. those rotated by logrotate).
 *
 * The indexed reader relies on random access (fseek) to byte offsets in the
 * uncompressed text, which gzip streams cannot provide efficiently. So instead
 * of reading the .gz file directly, we decompress it once into a plain-text
 * copy and point all readers at that copy. The copy is regenerated whenever
 * the source file's mtime or size changes.
 */
trait CanDecompress
{
    public function isCompressed(): bool
    {
        return str_ends_with(strtolower($this->path), '.gz');
    }

    /**
     * File name without the compression extension, e.g. "laravel.log.1.gz" -> "laravel.log.1".
     */
    public function uncompressedName(): string
    {
        return $this->isCompressed() ? substr($this->name, 0, -3) : $this->name;
    }

    /**
     * Path to the file containing plain-text log contents, which readers should open.
     * For uncompressed files, this is the original path.
     *
     * @throws CannotOpenFileException
     */
    public function readablePath(): string
    {
        if (! $this->isCompressed()) {
            return $this->path;
        }

        $target = $this->decompressedPath();

        if (! is_file($target)) {
            $this->decompressTo($target);
        }

        return $target;
    }

    /**
     * Size of the uncompressed contents, in bytes. Byte offsets stored in the
     * log index refer to the uncompressed contents, so this must be used
     * wherever we compare against scan progress.
     */
    protected function uncompressedSize(): int
    {
        $target = $this->decompressedPath();

        if (is_file($target)) {
            return filesize($target);
        }

        // The copy hasn't been made yet (e.g. we're just listing files). Avoid
        // decompressing the whole file just to show its size; the last 4 bytes
        // of a gzip file hold the uncompressed size (mod 2^32).
        return $this->gzipTrailerSize() ?? filesize($this->path);
    }

    protected function gzipTrailerSize(): ?int
    {
        $compressedSize = @filesize($this->path);

        if (! $compressedSize || $compressedSize < 18) {
            return null;
        }

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            return null;
        }

        fseek($handle, -4, SEEK_END);
        $trailer = fread($handle, 4);
        fclose($handle);

        return strlen($trailer) === 4 ? unpack('V', $trailer)[1] : null;
    }

    /**
     * Where the decompressed copy lives. The name includes the source's mtime and
     * size, so a changed source file automatically maps to a new (missing) copy.
     */
    protected function decompressedPath(): string
    {
        clearstatcache(false, $this->path);

        return $this->decompressedDirectory().DIRECTORY_SEPARATOR.sprintf(
            '%s-%d-%d.log',
            $this->decompressedPathPrefix(),
            @filemtime($this->path) ?: 0,
            @filesize($this->path) ?: 0,
        );
    }

    protected function decompressedPathPrefix(): string
    {
        return Utils::shortMd5($this->path);
    }

    public static function decompressedDirectory(): string
    {
        return config('log-viewer.decompressed_files_path')
            ?? storage_path('framework'.DIRECTORY_SEPARATOR.'log-viewer'.DIRECTORY_SEPARATOR.'decompressed');
    }

    /**
     * @throws CannotOpenFileException
     */
    protected function decompressTo(string $target): void
    {
        $directory = dirname($target);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new CannotOpenFileException('Could not create directory "'.$directory.'" for decompressed log files.');
        }

        $source = @gzopen($this->path, 'rb');

        if ($source === false) {
            throw new CannotOpenFileException('Could not open "'.$this->path.'" for decompression.');
        }

        // Write to a temporary file first and rename it into place, so concurrent
        // requests never see a partially written copy.
        $temp = $target.'.'.bin2hex(random_bytes(4)).'.tmp';
        $destination = @fopen($temp, 'wb');

        if ($destination === false) {
            gzclose($source);
            throw new CannotOpenFileException('Could not write decompressed copy of "'.$this->path.'".');
        }

        try {
            while (! gzeof($source)) {
                $chunk = gzread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new CannotOpenFileException('Could not decompress "'.$this->path.'". Is it a valid gzip file?');
                }

                fwrite($destination, $chunk);
            }
        } catch (\Throwable $e) {
            fclose($destination);
            gzclose($source);
            @unlink($temp);

            throw $e;
        }

        fclose($destination);
        gzclose($source);

        // Remove stale copies from previous versions of the source file.
        $this->deleteDecompressedCopies();

        rename($temp, $target);
    }

    public function deleteDecompressedCopies(): void
    {
        if (! $this->isCompressed()) {
            return;
        }

        $copies = glob($this->decompressedDirectory().DIRECTORY_SEPARATOR.$this->decompressedPathPrefix().'-*.log') ?: [];

        foreach ($copies as $copy) {
            @unlink($copy);
        }
    }
}
