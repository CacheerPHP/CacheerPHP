<?php

namespace Silviooosilva\CacheerPhp\Enums;

enum CacheStoreType: string
{
    case DATABASE = 'db';
    case REDIS = 'redis';
    case FILE = 'file';
    case ARRAY = 'array';
}

