<?php

declare(strict_types=1);

return [
    'scalar' => 'cacheer',
    'array'  => [
        'library' => 'CacheerPHP',
        'values'  => range(1, 32),
        'nested'  => ['simple' => true, 'powerful' => true],
    ],
    'object' => (object) [
        'library' => 'CacheerPHP',
        'version' => 5,
        'stable'  => true,
    ],
    '1_kb'   => str_repeat('c', 1024),
    '100_kb' => str_repeat('c', 100 * 1024),
    '1_mb'   => str_repeat('c', 1024 * 1024),
];
