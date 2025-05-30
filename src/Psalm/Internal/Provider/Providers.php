<?php

declare(strict_types=1);

namespace Psalm\Internal\Provider;

use RuntimeException;

use function fclose;
use function flock;
use function fopen;
use function stream_get_contents;
use function usleep;

use const LOCK_SH;

/**
 * @internal
 */
final class Providers
{
    public FileStorageProvider $file_storage_provider;

    public ClassLikeStorageProvider $classlike_storage_provider;

    public StatementsProvider $statements_provider;

    public FileReferenceProvider $file_reference_provider;

    public function __construct(
        public FileProvider $file_provider,
        public ?ParserCacheProvider $parser_cache_provider = null,
        ?FileStorageCacheProvider $file_storage_cache_provider = null,
        ?ClassLikeStorageCacheProvider $classlike_storage_cache_provider = null,
        ?FileReferenceCacheProvider $file_reference_cache_provider = null,
        public ?ProjectCacheProvider $project_cache_provider = null,
    ) {
        $this->file_storage_provider = new FileStorageProvider($file_storage_cache_provider);
        $this->classlike_storage_provider = new ClassLikeStorageProvider($classlike_storage_cache_provider);
        $this->statements_provider = new StatementsProvider(
            $file_provider,
            $parser_cache_provider,
        );
        $this->file_reference_provider = new FileReferenceProvider($file_provider, $file_reference_cache_provider);
    }

    public static function safeFileGetContents(string $path): string
    {
        // no readable validation as that must be done in the caller
        $fp = fopen($path, 'r');
        if ($fp === false) {
            return '';
        }
        $max_wait_cycles = 5;
        $has_lock = false;
        while ($max_wait_cycles > 0) {
            if (flock($fp, LOCK_SH)) {
                $has_lock = true;
                break;
            }
            $max_wait_cycles--;
            usleep(50_000);
        }

        if (!$has_lock) {
            fclose($fp);
            throw new RuntimeException('Could not acquire lock for ' . $path);
        }

        $content = stream_get_contents($fp);

        fclose($fp);

        return $content;
    }
}
