<?php

namespace Opcodes\LogViewer\Utils;

use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\LogFile;
use Opcodes\LogViewer\LogIndex;

class GenerateCacheKey
{
    public static function for(mixed $object, ?string $namespace = null): string
    {
        $key = '';

        if ($object instanceof LogFile) {
            $key = self::baseKey().':file:'.$object->identifier;

            // Compressed archives are replaced wholesale (e.g. by logrotate), never
            // appended to. Keying on the version gives a replaced archive a fresh,
            // empty index; the old version's entries expire with the cache TTL.
            if ($signature = $object->sourceSignature()) {
                $key .= ':'.$signature;
            }
        }

        if ($object instanceof LogIndex) {
            $key = self::for($object->file).':'.$object->identifier;
        }

        if (is_string($object)) {
            $key = self::baseKey().':'.$object;
        }

        if (! empty($namespace)) {
            $key .= ':'.$namespace;
        }

        return $key;
    }

    protected static function baseKey(): string
    {
        return config('log-viewer.cache_key_prefix', 'lv').':'.LogViewer::version();
    }
}
