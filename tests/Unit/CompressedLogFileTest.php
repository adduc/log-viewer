<?php

use Illuminate\Support\Facades\File;
use Opcodes\LogViewer\Exceptions\CannotOpenFileException;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\LogFile;
use Opcodes\LogViewer\Logs\LogType;
use Opcodes\LogViewer\Readers\IndexedLogReader;
use Opcodes\LogViewer\Utils\Utils;

beforeEach(function () {
    config(['log-viewer.decompressed_files_path' => storage_path('logs-decompressed-test')]);
});

afterEach(function () {
    File::deleteDirectory(storage_path('logs-decompressed-test'));
});

function generateGzipLogFile(string $fileName, string $content): LogFile
{
    $path = storage_path('logs'.DIRECTORY_SEPARATOR.$fileName);
    File::put($path, gzencode($content));

    return new LogFile($path);
}

it('reads logs from a gzip-compressed file', function () {
    $content = implode(PHP_EOL, [
        makeLaravelLogEntry(level: 'error', message: 'first'),
        makeLaravelLogEntry(level: 'info', message: 'second'),
        makeLaravelLogEntry(level: 'debug', message: 'third'),
    ]);
    $file = generateGzipLogFile('laravel.log.1.gz', $content);

    expect($file->isCompressed())->toBeTrue()
        ->and($file->type()->value)->toBe(LogType::LARAVEL)
        ->and($file->size())->toBe(strlen($content));

    $file->logs()->scan();
    $logs = $file->logs()->get();

    expect($file->logs()->requiresScan())->toBeFalse()
        ->and($logs)->toHaveCount(3)
        ->and(collect($logs)->pluck('message')->sort()->values()->all())
        ->toBe(['first', 'second', 'third']);
});

it('can search within a gzip-compressed file', function () {
    $file = generateGzipLogFile('laravel.log.2.gz', implode(PHP_EOL, [
        makeLaravelLogEntry(message: 'needle here'),
        makeLaravelLogEntry(message: 'just hay'),
    ]));

    $logs = $file->search('needle')->get();

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->message)->toBe('needle here');
});

it('guesses the size from the gzip trailer without decompressing', function () {
    $content = str_repeat(makeLaravelLogEntry().PHP_EOL, 100);
    $file = generateGzipLogFile('laravel.log.3.gz', $content);

    expect($file->size())->toBe(strlen($content))
        ->and(glob(LogFile::decompressedDirectory().'/*'))->toBeEmpty();
});

it('refreshes the decompressed copy when the source file changes', function () {
    $file = generateGzipLogFile('laravel.log.4.gz', makeLaravelLogEntry(message: 'old'));
    $oldCopy = $file->readablePath();

    // Simulate logrotate replacing the archive with different contents.
    File::put($file->path, gzencode(makeLaravelLogEntry(message: 'new and longer')));
    touch($file->path, time() + 10);

    $newCopy = $file->readablePath();

    expect($newCopy)->not->toBe($oldCopy)
        ->and(file_exists($oldCopy))->toBeFalse()
        ->and(file_get_contents($newCopy))->toContain('new and longer');
});

it('removes the decompressed copy when the cache is cleared or file deleted', function () {
    $file = generateGzipLogFile('laravel.log.5.gz', makeLaravelLogEntry());
    $copy = $file->readablePath();
    expect(file_exists($copy))->toBeTrue();

    $file->clearCache();
    expect(file_exists($copy))->toBeFalse();

    $copy = $file->readablePath();
    $file->delete();
    expect(file_exists($copy))->toBeFalse()
        ->and(file_exists($file->path))->toBeFalse();
});

it('discovers gzip files matching include patterns', function () {
    config(['log-viewer.include_files' => ['*.log', '*.log.*.gz']]);
    generateLogFile('laravel.log', makeLaravelLogEntry());
    generateGzipLogFile('laravel.log.1.gz', makeLaravelLogEntry());

    $names = LogViewer::getFiles()->map->name->sort()->values()->all();

    expect($names)->toBe(['laravel.log', 'laravel.log.1.gz']);
});

it('includes the decompressed copy in the cache size', function () {
    $content = str_repeat(makeLaravelLogEntry().PHP_EOL, 50);
    $file = generateGzipLogFile('laravel.log.6.gz', $content);

    $sizeBefore = $file->cacheSize();
    $file->readablePath();

    expect($file->decompressedCopiesSize())->toBe(strlen($content))
        ->and($file->cacheSize())->toBe($sizeBefore + strlen($content));
});

it('prunes decompressed copies unused for longer than the TTL', function () {
    $stale = generateGzipLogFile('laravel.log.7.gz', makeLaravelLogEntry());
    $fresh = generateGzipLogFile('laravel.log.8.gz', makeLaravelLogEntry());
    $staleCopy = $stale->readablePath();
    $freshCopy = $fresh->readablePath();
    $orphanTemp = LogFile::decompressedDirectory().'/abc-1-1.log.tmp';
    touch($orphanTemp);

    $longAgo = time() - LogFile::decompressedCopyTtl() - 60;
    touch($staleCopy, $longAgo);
    touch($orphanTemp, $longAgo);

    expect(LogFile::pruneDecompressedCopies())->toBe(2)
        ->and(file_exists($staleCopy))->toBeFalse()
        ->and(file_exists($orphanTemp))->toBeFalse()
        ->and(file_exists($freshCopy))->toBeTrue();

    // A pruned copy is transparently recreated on next use.
    expect(file_get_contents($stale->readablePath()))->toContain('Testing log entry');
});

it('refreshes the last-used time when an old copy is read', function () {
    $file = generateGzipLogFile('laravel.log.9.gz', makeLaravelLogEntry());
    $copy = $file->readablePath();
    touch($copy, $twoHoursAgo = time() - 7200);
    clearstatcache();

    $file->readablePath();
    clearstatcache();

    expect(filemtime($copy))->toBeGreaterThan($twoHoursAgo);
});

it('prunes stale copies when listing files', function () {
    $file = generateGzipLogFile('laravel.log.10.gz', makeLaravelLogEntry());
    $copy = $file->readablePath();
    touch($copy, time() - LogFile::decompressedCopyTtl() - 60);

    LogViewer::getFiles();

    expect(file_exists($copy))->toBeFalse();
});

it('leaves uncompressed files untouched', function () {
    $file = generateLogFile('plain.log', makeLaravelLogEntry());

    expect($file->isCompressed())->toBeFalse()
        ->and($file->readablePath())->toBe($file->path);
});

it('re-indexes a gzip file that was replaced by a different archive', function () {
    $file = generateGzipLogFile('laravel.log.11.gz', implode(PHP_EOL, [
        makeLaravelLogEntry(message: 'old entry with a much longer message than the new ones'),
        makeLaravelLogEntry(message: 'old-b'),
    ]));
    $file->logs()->scan();
    expect($file->logs()->get())->toHaveCount(2);

    // Simulate logrotate moving a different (larger) archive into the same path,
    // then a fresh request reading it with the persisted index still cached.
    File::put($file->path, gzencode(implode(PHP_EOL, array_map(
        fn ($i) => makeLaravelLogEntry(message: "new-$i"),
        range(1, 5),
    ))));
    touch($file->path, time() + 10);
    IndexedLogReader::clearInstances();
    $file = new LogFile($file->path);

    expect($file->logs()->requiresScan())->toBeTrue();
    $file->logs()->scan();

    expect(collect($file->logs()->get())->pluck('message')->sort()->values()->all())
        ->toBe(['new-1', 'new-2', 'new-3', 'new-4', 'new-5']);
});

it('re-indexes a replaced gzip file even if its uncompressed size is unchanged', function () {
    $file = generateGzipLogFile('laravel.log.12.gz', makeLaravelLogEntry(message: 'old-a'));
    $file->logs()->scan();

    File::put($file->path, gzencode(makeLaravelLogEntry(message: 'new-a')));
    touch($file->path, time() + 10);
    IndexedLogReader::clearInstances();
    $file = new LogFile($file->path);

    expect($file->logs()->requiresScan())->toBeTrue();
    $file->logs()->scan();

    expect(collect($file->logs()->get())->pluck('message')->all())->toBe(['new-a']);
});

it('only prunes files it created in the decompressed directory', function () {
    $directory = LogFile::decompressedDirectory();
    File::ensureDirectoryExists($directory);
    $longAgo = time() - LogFile::decompressedCopyTtl() - 60;

    $unrelated = ["$directory/laravel.log", "$directory/notes.txt", "$directory/abc-1-1.log.bak"];
    $ours = ["$directory/abc-1-1.log", "$directory/abc-1-1.log.tmp", "$directory/abc.lock"];

    foreach ([...$unrelated, ...$ours] as $path) {
        touch($path, $longAgo);
    }

    expect(LogFile::pruneDecompressedCopies())->toBe(3);

    foreach ($unrelated as $path) {
        expect(file_exists($path))->toBeTrue();
    }
    foreach ($ours as $path) {
        expect(file_exists($path))->toBeFalse();
    }
});

it('overwrites a temp file left behind by an interrupted decompression', function () {
    $file = generateGzipLogFile('laravel.log.13.gz', makeLaravelLogEntry(message: 'complete'));
    $target = expectedDecompressedPath($file);
    File::ensureDirectoryExists(dirname($target));
    file_put_contents($target.'.tmp', 'partial garbage from a killed request');

    expect($file->readablePath())->toBe($target)
        ->and(file_get_contents($target))->toContain('complete')
        ->and(file_get_contents($target))->not->toContain('garbage')
        ->and(file_exists($target.'.tmp'))->toBeFalse()
        ->and(glob(dirname($target).'/*.tmp'))->toBeEmpty();
});

it('does not keep a truncated copy when writing fails', function () {
    $file = generateGzipLogFile('laravel.log.14.gz', str_repeat(makeLaravelLogEntry().PHP_EOL, 50));
    $target = expectedDecompressedPath($file);
    File::ensureDirectoryExists(dirname($target));

    // Writes to /dev/full fail with ENOSPC, just like a full disk.
    symlink('/dev/full', $target.'.tmp');

    expect(fn () => $file->readablePath())->toThrow(CannotOpenFileException::class, 'Is the disk full?')
        ->and(file_exists($target))->toBeFalse()
        ->and(is_link($target.'.tmp') || file_exists($target.'.tmp'))->toBeFalse();

    // The next attempt succeeds normally.
    expect(file_get_contents($file->readablePath()))->toContain('Testing log entry');
})->skip(! is_writable('/dev/full'), 'Requires /dev/full (Linux).');

function expectedDecompressedPath(LogFile $file): string
{
    clearstatcache();

    return LogFile::decompressedDirectory().DIRECTORY_SEPARATOR
        .sprintf('%s-%d-%d.log', Utils::shortMd5($file->path), filemtime($file->path), filesize($file->path));
}
