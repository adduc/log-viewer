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
        } elseif (filemtime($target) < time() - 3600) {
            // The copy's mtime records when it was last used, so pruning can find
            // copies nobody has opened recently. Only touch it hourly to avoid a
            // filesystem write on every request.
            @touch($target);
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
        return $this->decompressedDirectory().DIRECTORY_SEPARATOR.sprintf(
            '%s-%s.log',
            $this->decompressedPathPrefix(),
            $this->sourceSignature(),
        );
    }

    /**
     * Identifies the current version of a compressed source file ("mtime-size"),
     * or null for uncompressed files.
     */
    public function sourceSignature(): ?string
    {
        if (! $this->isCompressed()) {
            return null;
        }

        clearstatcache(false, $this->path);

        return sprintf('%d-%d', @filemtime($this->path) ?: 0, @filesize($this->path) ?: 0);
    }

    /**
     * Whether a compressed source was replaced since it was last indexed (e.g. by
     * logrotate). Archives are never appended to, so any change means the stored
     * byte offsets are meaningless and the file must be re-indexed from scratch.
     */
    public function sourceChangedSinceIndexing(): bool
    {
        if (! $this->isCompressed()) {
            return false;
        }

        $indexed = $this->getMetadata('source_signature');

        return $indexed !== null && $indexed !== $this->sourceSignature();
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

        // Serialize decompression of this source across requests. Otherwise every
        // request arriving during a slow decompression starts its own, and any of
        // them killed by max_execution_time (which skips catch/finally) would leave
        // a large temp file behind. The OS releases the lock if the process dies.
        $lockPath = $directory.DIRECTORY_SEPARATOR.$this->decompressedPathPrefix().'.lock';
        $lock = @fopen($lockPath, 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new CannotOpenFileException('Could not lock "'.$lockPath.'" to decompress "'.$this->path.'".');
        }

        // Keep the lock's mtime fresh so pruning never removes a lock in use.
        @touch($lockPath);

        try {
            clearstatcache(false, $target);

            if (is_file($target)) {
                // Another request finished decompressing while we waited for the lock.
                return;
            }

            // A fixed temp name (rather than a random one) means a decompression
            // killed mid-way leaves at most one leftover, which the next attempt
            // overwrites. Renaming into place means readers never see a partial copy.
            $temp = $target.'.tmp';
            $this->writeDecompressedCopy($temp);

            if (! @rename($temp, $target)) {
                @unlink($temp);
                throw new CannotOpenFileException('Could not move decompressed copy of "'.$this->path.'" into place.');
            }

            // Remove stale copies from previous versions of the source file. This
            // happens after the rename and skips $target, so a concurrent reader
            // that already resolved $target never finds it missing.
            $this->deleteDecompressedCopies(except: $target);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        // Disk usage just grew, so this is a good time to drop unused copies.
        static::pruneDecompressedCopies();
    }

    /**
     * @throws CannotOpenFileException
     */
    protected function writeDecompressedCopy(string $temp): void
    {
        $source = @gzopen($this->path, 'rb');

        if ($source === false) {
            throw new CannotOpenFileException('Could not open "'.$this->path.'" for decompression.');
        }

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

                // A short write (e.g. disk full) must not be renamed into place: the
                // copy's name is tied to the source version, so a truncated copy
                // would never be regenerated.
                if (@fwrite($destination, $chunk) !== strlen($chunk)) {
                    throw new CannotOpenFileException('Could not write decompressed copy of "'.$this->path.'". Is the disk full?');
                }
            }

            // fclose() flushes buffered data, so it can fail for the same reasons.
            $closed = fclose($destination);
            $destination = null;

            if (! $closed) {
                throw new CannotOpenFileException('Could not write decompressed copy of "'.$this->path.'". Is the disk full?');
            }
        } catch (\Throwable $e) {
            if (is_resource($destination)) {
                fclose($destination);
            }
            @unlink($temp);

            throw $e;
        } finally {
            gzclose($source);
        }
    }

    /**
     * Total size of this file's decompressed copies on disk, in bytes.
     */
    public function decompressedCopiesSize(): int
    {
        if (! $this->isCompressed()) {
            return 0;
        }

        return array_sum(array_map(
            fn (string $copy) => @filesize($copy) ?: 0,
            $this->decompressedCopies(),
        ));
    }

    /**
     * How long a decompressed copy may go unused before it is pruned.
     * Matches the one-week TTL used for cached indices (see CanCacheData::cacheTtl()).
     */
    public static function decompressedCopyTtl(): int
    {
        return 7 * 24 * 60 * 60;
    }

    /**
     * Delete decompressed copies (plus leftover temp and lock files) that have
     * not been used within the TTL.
     *
     * Only files whose names match what this trait creates are considered, so a
     * `decompressed_files_path` pointed at a shared directory never loses
     * unrelated files.
     *
     * @return int Number of files deleted.
     */
    public static function pruneDecompressedCopies(): int
    {
        $directory = static::decompressedDirectory();

        if (! is_dir($directory)) {
            return 0;
        }

        $cutoff = time() - static::decompressedCopyTtl();
        $deleted = 0;

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (
                static::isDecompressedArtifact(basename($file))
                && is_file($file)
                && (@filemtime($file) ?: 0) < $cutoff
                && @unlink($file)
            ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Whether a file name is one this trait creates: a copy ("<hash>-<mtime>-<size>.log"),
     * its temp file ("<copy>.tmp"), or a per-source lock ("<hash>.lock").
     */
    protected static function isDecompressedArtifact(string $fileName): bool
    {
        return preg_match('/^[0-9a-f]+(?:-\d+-\d+\.log(?:\.tmp)?|\.lock)$/', $fileName) === 1;
    }

    protected function decompressedCopies(): array
    {
        return glob($this->decompressedDirectory().DIRECTORY_SEPARATOR.$this->decompressedPathPrefix().'-*.log') ?: [];
    }

    public function deleteDecompressedCopies(?string $except = null): void
    {
        if (! $this->isCompressed()) {
            return;
        }

        foreach ($this->decompressedCopies() as $copy) {
            if ($copy !== $except) {
                @unlink($copy);
            }
        }
    }
}
