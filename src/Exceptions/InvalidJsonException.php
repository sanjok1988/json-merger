<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Exceptions;

use Exception;
use Throwable;

final class InvalidJsonException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $path = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function forPath(string $path, string $reason): self
    {
        return new self(
            sprintf('Invalid JSON in "%s": %s', $path, $reason),
            $path,
        );
    }

    public static function forPayload(string $reason): self
    {
        return new self(sprintf('Invalid JSON payload: %s', $reason));
    }

    public static function rootMustBeObject(?string $path = null): self
    {
        $message = 'Root JSON value must be an associative object.';

        if ($path !== null) {
            return new self(sprintf('%s Path: "%s".', $message, $path), $path);
        }

        return new self($message);
    }
}
