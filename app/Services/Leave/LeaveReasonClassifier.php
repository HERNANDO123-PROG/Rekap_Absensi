<?php

namespace App\Services\Leave;

use App\Enums\AbsenceReason;

class LeaveReasonClassifier
{
    private const SICK_KEYWORDS = [
        'sakit',
        'demam',
        'flu',
        'batuk',
        'pilek',
        'tidak enak badan',
        'rawat inap',
        'berobat',
    ];

    private const PERMISSION_KEYWORDS = [
        'keluarga',
        'pribadi',
        'urusan',
        'keperluan',
        'acara',
    ];

    public function classify(string $description): string
    {
        $description = mb_strtolower(trim($description), 'UTF-8');

        foreach (self::SICK_KEYWORDS as $keyword) {
            if (str_contains($description, $keyword)) {
                return AbsenceReason::Sick->value;
            }
        }

        foreach (self::PERMISSION_KEYWORDS as $keyword) {
            if (str_contains($description, $keyword)) {
                return AbsenceReason::Permission->value;
            }
        }

        return AbsenceReason::Unknown->value;
    }
}
