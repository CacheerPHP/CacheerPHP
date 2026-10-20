<?php

declare(strict_types=1);

namespace Evidence;

use Composer\InstalledVersions;

/**
 * Records what a result was measured on, so it can be compared or reproduced.
 */
final class Environment
{
    /**
     * @return array<string, string>
     */
    public static function describe(?RedisServer $redis = null): array
    {
        $info = [
            'date'         => gmdate('Y-m-d\TH:i:s\Z'),
            'os'           => php_uname('s') . ' ' . php_uname('r') . ' (' . php_uname('m') . ')',
            'cpu'          => self::cpu(),
            'memory'       => self::memory(),
            'php'          => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'opcache/jit'  => sprintf(
                'opcache %s, jit %s',
                ini_get('opcache.enable_cli') ? 'on' : 'off',
                ini_get('opcache.jit') ?: 'off',
            ),
            'cacheer-php'  => self::library(),
            'predis'       => self::package('predis/predis'),
        ];

        if ($redis !== null) {
            $info['redis'] = $redis->version() . ' on 127.0.0.1:' . $redis->port . ' (loopback)';
        }

        return $info;
    }

    /**
     * @param array<string, string> $info
     */
    public static function markdown(array $info): string
    {
        $rows = ["| | |", "|---|---|"];
        foreach ($info as $label => $value) {
            $rows[] = sprintf('| %s | %s |', $label, $value);
        }

        return implode("\n", $rows);
    }

    private static function cpu(): string
    {
        $brand = trim((string) @shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null'));
        $cores = trim((string) @shell_exec('sysctl -n hw.ncpu 2>/dev/null'));

        if ($brand === '' && is_readable('/proc/cpuinfo')) {
            preg_match('/model name\s*:\s*(.+)/', (string) file_get_contents('/proc/cpuinfo'), $match);
            $brand = $match[1] ?? 'unknown';
            $cores = trim((string) @shell_exec('nproc 2>/dev/null'));
        }

        return $brand . ($cores !== '' ? ", {$cores} cores" : '');
    }

    private static function memory(): string
    {
        $bytes = (int) trim((string) @shell_exec('sysctl -n hw.memsize 2>/dev/null'));

        if ($bytes === 0 && is_readable('/proc/meminfo')) {
            preg_match('/MemTotal:\s*(\d+)/', (string) file_get_contents('/proc/meminfo'), $match);
            $bytes = (int) ($match[1] ?? 0) * 1024;
        }

        return $bytes > 0 ? round($bytes / 1024 ** 3) . ' GB' : 'unknown';
    }

    /**
     * The library is installed from this checkout through a symlinked path
     * repository, so Composer's recorded reference is only the commit at
     * install time. Report the commit actually checked out, and say when
     * src/ has uncommitted changes.
     */
    private static function library(): string
    {
        $root = dirname(__DIR__, 2);
        $commit = trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null'));
        if ($commit === '') {
            return self::package('silviooosilva/cacheer-php');
        }

        $branch = trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --abbrev-ref HEAD 2>/dev/null'));
        $dirty = trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' status --porcelain -- src 2>/dev/null')) !== '';

        return sprintf('%s @ %s%s (this checkout)', $branch, $commit, $dirty ? ', src/ modified' : '');
    }

    private static function package(string $name): string
    {
        if (!InstalledVersions::isInstalled($name)) {
            return 'not installed';
        }

        $version = (string) InstalledVersions::getPrettyVersion($name);
        $reference = InstalledVersions::getReference($name);

        return $reference !== null && str_contains($version, 'dev') ? $version . ' @ ' . substr($reference, 0, 7) : $version;
    }
}
