<?php

namespace App\Services\Leave;

use App\Services\Fingerprint\FingerprintDateParser;
use App\Services\Fingerprint\FingerprintImportException;

class LeaveDateParser
{
    public function __construct(private readonly FingerprintDateParser $dates) {}

    public function parse(mixed $value): string
    {
        try {
            return $this->dates->parse($value);
        } catch (FingerprintImportException $exception) {
            throw new LeaveImportException('Tanggal Pengajuan Izin tidak valid.', previous: $exception);
        }
    }
}
