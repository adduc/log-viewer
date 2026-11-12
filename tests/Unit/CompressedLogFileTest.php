<?php

use Illuminate\Support\Facades\File;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\LogFile;
use Opcodes\LogViewer\Logs\LogType;

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
    $orphanTemp = LogFile::decompressedDirectory().'/abc-1-1.log.deadbeef.tmp';
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
