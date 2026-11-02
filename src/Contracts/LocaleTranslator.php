<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Contracts;

interface LocaleTranslator
{
    public function translate(string $value, string $targetLanguage, string $path = ''): string;
}