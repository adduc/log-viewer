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
 *
 * Each LogFile instance pins the source's "mtime-size" signature, and the
 * decompressed path, metadata and index cache keys are all derived from it.
 * A replaced archive (e.g. by logrotate) therefore gets a fresh copy and a
 * fresh, empty index rather than one that has to be detected and cleared.
 */
trait CanDecompress
{
    protected ?string $pinnedSourceSignature = null;

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

        $this->refreshSourceSignature();
        $target = $this->decompressedPath();

        // A single suppressed stat rather than is_file() + filemtime(): another
        // request may delete the copy in between, and an unsuppressed warning
        // would become an ErrorException under Laravel.
        $lastUsed = @filemtime($target);

        if ($lastUsed === false) {
            $target = $this->decompressTo($target);
        } elseif ($lastUsed < time() - 3600) {
            // The copy's mtime records when it was last used, so pruning can find
            // copies nobody has opened recently. Only touch it hourly to avoid a
            // filesystem write on every request.
            @touch($target);
        }

        return $target;
    }

    /**
     * Open the plain-text contents for reading.
     *
     * Resolving the copy's path and opening it are separate steps, so another
     * request can delete the copy in between: after decompressing a newer
     * version of the archive, or when clearing the cache. Once opened, the copy
     * stays readable even if it is unlinked, so only the open needs a retry.
     *
     * @return resource
     *
     * @throws CannotOpenFileException
     */
    public function openForReading()
    {
        for ($attempt = 1; ; $attempt++) {
            $path = $this->readablePath();
            $handle = @fopen($path, 'r');

            if ($handle !== false) {
                return $handle;
            }

            if (! $this->isCompressed() || $attempt >= 3) {
                throw new CannotOpenFileException('Could not open "'.$this->path.'" for reading.');
            }

            clearstatcache(false, $path);
        }
    }

    /**
     * Size of the uncompressed contents, in bytes. Byte offsets stored in the
     * log index refer to the uncompressed contents, so this must be used
     * wherever we compare against scan progress.
     */
    protected function uncompressedSize(): int
    {
        // Suppressed, not guarded by is_file(): the copy can be deleted by
        // another request between the check and the read. See readablePath().
        $copySize = @filesize($this->decompressedPath());

        if ($copySize !== false) {
            return $copySize;
        }

        // The copy was pruned or hasn't been made yet (e.g. we're just listing
        // files). Metadata is keyed by the source signature, so a size recorded
        // by a scan is exact for this version of the archive.
        $scannedSize = $this->getMetadata('size');

        if ($scannedSize !== null) {
            return $scannedSize;
        }

        return $this->estimatedUncompressedSize();
    }

    /**
     * A display-only estimate for archives that have never been scanned, which
     * avoids decompressing every archive just to list it. The gzip trailer's
     * ISIZE is the size mod 2^32 of the last member only, so it understates
     * archives over 4 GiB and multi-member (concatenated) archives. Readers never
     * compare scan progress against it; see IndexedLogReader::requiresScan().
     */
    protected function estimatedUncompressedSize(): int
    {
        return max($this->gzipTrailerSize() ?? 0, @filesize($this->path) ?: 0);
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
     * The version ("mtime-size") of a compressed source file this instance works
     * with, or null for uncompressed files. It is pinned on first use and only
     * changes through refreshSourceSignature(), so cache keys and the copy's
     * path stay consistent while a reader is using them.
     */
    public function sourceSignature(): ?string
    {
        if (! $this->isCompressed()) {
            return null;
        }

        return $this->pinnedSourceSignature ??= $this->currentSourceSignature();
    }

    /**
     * Re-check the source file and switch to its current version. Archives are
     * replaced rather than appended to, so a new version has its own cache keys
     * and starts with an empty index. In-memory state belonging to the previous
     * version is dropped, so it can't be written back under the new keys.
     *
     * @return bool Whether the source changed.
     */
    public function refreshSourceSignature(): bool
    {
        if (! $this->isCompressed()) {
            return false;
        }

        $previous = $this->pinnedSourceSignature;
        $this->pinnedSourceSignature = $this->currentSourceSignature();

        if ($previous === null || $previous === $this->pinnedSourceSignature) {
            return false;
        }

        // Readers keep their direction, filters and pagination on the index
        // (they re-fetch it via index() on every call), so carry those over to
        // the new version's indices instead of silently resetting them.
        $previousIndices = $this->_logIndexCache ?? [];
        $this->_logIndexCache = [];
        $this->loadMetadata();

        foreach ($previousIndices as $query => $previousIndex) {
            $this->index((string) $query)->copyQuerySettingsFrom($previousIndex);
        }

        return true;
    }

    protected function currentSourceSignature(): string
    {
        clearstatcache(false, $this->path);

        return sprintf('%d-%d', @filemtime($this->path) ?: 0, @filesize($this->path) ?: 0);
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
     * @return string Path of the copy, which differs from $target if the source
     *                was replaced while waiting for the lock.
     *
     * @throws CannotOpenFileException
     */
    protected function decompressTo(string $target): string
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

        if ($lock !== false && ! flock($lock, LOCK_EX)) {
            fclose($lock);
            $lock = false;
        }

        if ($lock === false) {
            throw new CannotOpenFileException('Could not lock "'.$lockPath.'" to decompress "'.$this->path.'".');
        }

        // Keep the lock's mtime fresh so pruning never removes a lock in use.
        @touch($lockPath);

        try {
            // The source may have been replaced while we waited for the lock.
            // Decompressing it under the old name would store the new contents
            // under the old version's copy and cache keys.
            if ($this->refreshSourceSignature()) {
                $target = $this->decompressedPath();
            }

            clearstatcache(false, $target);

            if (is_file($target)) {
                // Another request finished decompressing while we waited for the lock.
                return $target;
            }

            // A fixed temp name (rather than a random one) means a decompression
            // killed mid-way leaves at most one leftover, which the next attempt
            // overwrites. Renaming into place means readers never see a partial copy.
            $temp = $target.'.tmp';
            $this->writeDecompressedCopy($temp);

            if ($this->currentSourceSignature() !== $this->sourceSignature()) {
                @unlink($temp);
                throw new CannotOpenFileException('"'.$this->path.'" was replaced while it was being decompressed. Please try again.');
            }

            if (! @rename($temp, $target)) {
                @unlink($temp);
                throw new CannotOpenFileException('Could not move decompressed copy of "'.$this->path.'" into place.');
            }

            // Remove stale copies from previous versions of the source file. This
            // happens after the rename and skips $target. A concurrent reader that
            // resolved an older copy but hasn't opened it yet retries; see
            // openForReading().
            $this->deleteDecompressedCopies(except: $target);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        // Disk usage just grew, so this is a good time to drop unused copies.
        static::pruneDecompressedCopies();

        return $target;
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
