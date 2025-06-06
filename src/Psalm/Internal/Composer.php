<?php

declare(strict_types=1);

namespace Psalm\Internal;

use Psalm\Internal\Provider\Providers;

use function basename;
use function file_exists;
use function getenv;
use function is_readable;
use function pathinfo;
use function substr;
use function trim;

use const PATHINFO_EXTENSION;

/**
 * @internal
 */
final class Composer
{
    /**
     * Retrieve the path to composer.json file.
     *
     * @see https://github.com/composer/composer/blob/5df1797d20c6ab1eb606dc0f0d76a16ba57ddb7f/src/Composer/Factory.php#L233
     */
    public static function getJsonFilePath(string $root): string
    {
        $file_name = getenv('COMPOSER') ?: 'composer.json';
        $file_name = basename(trim($file_name));

        return $root . '/' . $file_name;
    }

    /**
     * Retrieve the path to composer.lock file.
     *
     * @see https://github.com/composer/composer/blob/5df1797d20c6ab1eb606dc0f0d76a16ba57ddb7f/src/Composer/Factory.php#L238
     */
    public static function getLockFilePath(string $root): string
    {
        $composer_json_path = self::getJsonFilePath($root);
        return "json" === pathinfo($composer_json_path, PATHINFO_EXTENSION)
            ? substr($composer_json_path, 0, -4).'lock'
            : $composer_json_path . '.lock';
    }

    private static ?string $lockFile = null;

    public static function getLockFile(string $root): string
    {
        if (self::$lockFile !== null) {
            return self::$lockFile;
        }
        $root = self::getLockFilePath($root);
        if (file_exists($root) && is_readable($root)) {
            $root = Providers::safeFileGetContents($root);
        } else {
            $root = '';
        }
        return self::$lockFile = $root;
    }
}
