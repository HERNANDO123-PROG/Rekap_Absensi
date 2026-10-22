<?php

namespace App\Services\Fingerprint;

use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class FingerprintDateParser
{
    private const FORMATS = [
        '!d/m/Y', '!d-m-Y', '!Y-m-d', '!Y/m/d', '!d.m.Y',
        '!d/m/Y H:i:s', '!d/m/Y H:i', '!d-m-Y H:i:s', '!d-m-Y H:i',
        '!Y-m-d H:i:s', '!Y-m-d H:i', '!Y/m/d H:i:s', '!Y/m/d H:i',
    ];

    public function parse(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            $serial = (float) $value;

            if ($serial < 1 || $serial > 2958465) {
                throw new FingerprintImportException('Invalid Excel date serial.');
            }

            try {
                return ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
            } catch (Throwable $exception) {
                throw new FingerprintImportException('Invalid Excel date serial.', previous: $exception);
            }
        }

        if (! is_string($value) || trim($value) === '') {
            throw new FingerprintImportException('Attendance date is empty or invalid.');
        }

        $date = trim($value);

        foreach (self::FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $date);
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed->format('Y-m-d');
            }
        }

        throw new FingerprintImportException('Attendance date format is invalid.');
    }
}
