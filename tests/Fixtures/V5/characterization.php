<?php

declare(strict_types=1);

/**
 * Machine-readable inventory of the v5 behavior that the v6 compatibility
 * layer must either preserve or migrate explicitly.
 */
return [
    'format'     => 'cacheer-v5-characterization-v1',
    'operations' => [
        'putCache', 'getCache', 'clearCache', 'flushCache',
        'has', 'missing', 'getMany', 'getAll', 'putMany', 'renewCache',
        'add', 'appendCache', 'forever', 'pull', 'getAndForget',
        'increment', 'decrement', 'lock',
        'remember', 'rememberForever', 'flexible',
        'tag', 'flushTag', 'in', 'namespace', 'withoutNamespace',
        'setConfig', 'setDriver', 'setUp', 'getOption', 'getOptions',
        'useFormatter', 'useCompression', 'useEncryption',
        'stats', 'resetInstance', 'setInstance',
    ],
    'drivers' => [
        'array'    => ['base', 'batch', 'tags', 'locks', 'atomic', 'touch', 'inspection'],
        'file'     => ['base', 'batch', 'tags', 'locks', 'atomic', 'touch', 'inspection'],
        'redis'    => ['base', 'batch', 'tags', 'locks', 'atomic', 'touch', 'inspection'],
        'database' => ['base', 'batch', 'tags', 'locks', 'atomic', 'touch', 'inspection'],
    ],
    'unsupported_capabilities' => [
        'array'    => ['prune'],
        'file'     => ['prune'],
        'redis'    => ['prune'],
        'database' => ['prune'],
    ],
    'values' => [
        'scalar'       => 'cacheer',
        'integer'      => 42,
        'float'        => 3.14,
        'false'        => false,
        'empty_string' => '',
        'empty_array'  => [],
        'null'         => null,
        'array'        => ['framework' => 'agnostic', 'major' => 5],
        'object'       => (object) ['type' => 'fixture', 'version' => 5],
    ],
];
