<?php

namespace App\Exception;

use Exception;
use Throwable;

/**
 * Thrown when an incorrect instance descriptor is provided to the worker.
 */
class BadDescriptorException extends Exception
{
    protected $descriptor;

    public function __construct($descriptor = "", string $message = "", int $code = 0, Throwable $previous = NULL) {
        parent::__construct($message, $code, $previous);

        $this->descriptor = $descriptor;
    }

    public function getDescriptor()
    {
        return $this->descriptor;
    }
}