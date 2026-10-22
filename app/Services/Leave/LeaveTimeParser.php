<?php

namespace App\Services\Leave;

use DateTimeInterface;

class LeaveTimeParser
{
    public function parse(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }

        if (! is_scalar($value)) {
            throw new LeaveImportException('Jam Kepulangan / Jam Perkiraan Kehadiran / Jam Kembali tidak valid.');
        }

        $rawTime = trim((string) $value);

        if (preg_match('/^(\d{1,2})[.:](\d{2})(?::(\d{2}))?$/', $rawTime, $matches) !== 1) {
            throw new LeaveImportException('Format jam tidak valid. Gunakan HH.MM atau HH:MM.');
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $second = isset($matches[3]) ? (int) $matches[3] : 0;

        if ($hour > 23 || $minute > 59 || $second > 59) {
            throw new LeaveImportException('Jam harus berada dalam rentang 00:00 sampai 23:59.');
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
