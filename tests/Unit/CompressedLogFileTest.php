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

it('leaves uncompressed files untouched', function () {
    $file = generateLogFile('plain.log', makeLaravelLogEntry());

    expect($file->isCompressed())->toBeFalse()
        ->and($file->readablePath())->toBe($file->path);
});
