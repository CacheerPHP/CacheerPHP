<?php

namespace Silviooosilva\CacheerPhp\Helpers;

use Composer\InstalledVersions;

/**
 * Class EnvHelper
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 */
class EnvHelper
{
    /**
     * Gets the root path of the project.
     *
     * @return string
     */
    public static function getRootPath(): string
    {
        // Try to get the root path from Composer's installed versions
        if (class_exists(InstalledVersions::class)) {
            $rootPackage = InstalledVersions::getRootPackage();
            if (!empty($rootPackage['install_path'])) {
                return rtrim($rootPackage['install_path'], DIRECTORY_SEPARATOR);
            }
        }

        // Fallback: traverse directories from __DIR__ looking for .env.example
        $baseDir = __DIR__;
        while (!file_exists($baseDir . DIRECTORY_SEPARATOR . '.env.example') && $baseDir !== dirname($baseDir)) {
            $baseDir = dirname($baseDir);
        }

        return rtrim($baseDir, DIRECTORY_SEPARATOR);
    }
}
