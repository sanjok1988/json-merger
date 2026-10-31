<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Exceptions;

use Exception;
use Throwable;

final class LocaleFileException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $path = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function notFound(string $path): self
    {
        return new self(sprintf('Locale file not found: "%s".', $path), $path);
    }

    public static function notReadable(string $path): self
    {
        return new self(sprintf('Locale file is not readable: "%s".', $path), $path);
    }

    public static function notWritable(string $path): self
    {
        return new self(sprintf('Unable to write locale file: "%s".', $path), $path);
    }

    public static function alreadyExists(string $path): self
    {
        return new self(
            sprintf(
                'Output file already exists: "%s". Pass allowOverwrite=true or --force to overwrite.',
                $path,
            ),
            $path,
        );
    }

    public static function cannotCreateDirectory(string $path): self
    {
        return new self(sprintf('Unable to create output directory: "%s".', $path), $path);
    }
}
