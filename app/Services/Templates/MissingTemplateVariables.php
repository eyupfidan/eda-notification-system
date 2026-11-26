<?php

namespace App\Services\Templates;

use RuntimeException;

class MissingTemplateVariables extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Missing template variables: '.implode(', ', $missing).'.');
    }
}
