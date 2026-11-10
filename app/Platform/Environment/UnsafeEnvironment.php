<?php

namespace App\Platform\Environment;

use RuntimeException;

class UnsafeEnvironment extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Unsafe configuration, refusing to start: '.implode(' ', $problems));
    }
}
