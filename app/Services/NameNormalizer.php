<?php

namespace App\Services;

class NameNormalizer
{
    private const ACADEMIC_SUFFIXES = [
        'S\\.?\\s*Pd\\.?',
        'M\\.?\\s*Pd\\.?',
        'S\\.?\\s*Kom\\.?',
        'M\\.?\\s*Kom\\.?',
        'S\\.?\\s*E\\.?',
        'S\\.?\\s*Si\\.?',
        'S\\.?\\s*Sos\\.?',
        'S\\.?\\s*Ag\\.?',
        'S\\.?\\s*H\\.?',
        'M\\.?\\s*Si\\.?',
        'M\\.?\\s*Hum\\.?',
    ];

    public function normalize(string $name): string
    {
        $suffixPattern = implode('|', self::ACADEMIC_SUFFIXES);
        $name = preg_replace('/,\\s*(?:'.$suffixPattern.')(?:\\s*,\\s*(?:'.$suffixPattern.'))*\\s*$/iu', '', trim($name)) ?? $name;
        $name = preg_replace('/[~.,;:]+/u', ' ', $name) ?? $name;
        $name = mb_strtolower($name, 'UTF-8');

        return trim(preg_replace('/\\s+/u', ' ', $name) ?? $name);
    }
}
