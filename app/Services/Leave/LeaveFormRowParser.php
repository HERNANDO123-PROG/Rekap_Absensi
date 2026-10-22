<?php

namespace App\Services\Leave;

use App\Enums\AbsenceReason;
use App\Enums\LeaveType;
use App\Services\NameNormalizer;

class LeaveFormRowParser
{
    private const LEAVE_TYPES = [
        'terlambat' => LeaveType::Late,
        'tidak hadir' => LeaveType::Absent,
        'pulang lebih awal' => LeaveType::EarlyDeparture,
        'perjalanan dinas/tugas resmi' => LeaveType::OfficialDuty,
        'keluar' => LeaveType::TemporaryExit,
    ];

    public function __construct(
        private readonly LeaveDateParser $dates,
        private readonly LeaveTimeParser $times,
        private readonly NameNormalizer $normalizer,
        private readonly LeaveReasonClassifier $reasonClassifier,
    ) {}

    public function parse(array $row, int $sourceRow, array $mapping): ?array
    {
        if ($this->isBlankRow($row)) {
            return null;
        }

        $rawName = $this->value($row, $mapping, 'raw_name', 'raw');
        $positionValue = $this->value($row, $mapping, 'position', 'value');
        $rawDate = $this->value($row, $mapping, 'request_date', 'raw');
        $dateValue = $this->value($row, $mapping, 'request_date', 'value');
        $rawTime = $this->value($row, $mapping, 'request_time', 'raw');
        $timeValue = $this->value($row, $mapping, 'request_time', 'value');
        $rawLeaveType = $this->value($row, $mapping, 'leave_type', 'raw');
        $descriptionValue = $this->value($row, $mapping, 'description', 'value');
        $attachmentValue = $this->value($row, $mapping, 'attachment', 'raw');
        $errors = [];

        if (! is_string($rawName) || trim($rawName) === '') {
            $errors[] = 'Nama Lengkap dan Gelar wajib diisi.';
        }

        if (! is_string($positionValue) || trim($positionValue) === '') {
            $errors[] = 'Jabatan wajib diisi.';
        }

        if (! is_string($descriptionValue) || trim($descriptionValue) === '') {
            $errors[] = 'Keterangan Izin (Jelaskan) wajib diisi.';
        }

        $requestDate = null;

        try {
            $requestDate = $this->dates->parse($dateValue);
        } catch (LeaveImportException $exception) {
            $errors[] = $exception->getMessage();
        }

        $requestTime = null;

        try {
            $requestTime = $this->times->parse($timeValue);
        } catch (LeaveImportException $exception) {
            $errors[] = $exception->getMessage();
        }

        $normalizedLeaveType = $this->normalizeLeaveType((string) ($rawLeaveType ?? ''));
        $leaveType = self::LEAVE_TYPES[$normalizedLeaveType] ?? null;

        if ($leaveType === null) {
            $errors[] = 'Keperluan Izin tidak dikenal.';
        }

        $normalizedName = is_string($rawName) ? $this->normalizer->normalize($rawName) : '';

        if ($normalizedName === '') {
            $errors[] = 'Nama Lengkap dan Gelar tidak dapat dinormalisasi untuk matching.';
        }

        $description = is_string($descriptionValue) ? trim($descriptionValue) : null;
        $absenceReason = $leaveType === LeaveType::Absent
            ? $this->reasonClassifier->classify($description ?? '')
            : null;

        return [
            'source_row' => $sourceRow,
            'raw_name' => is_string($rawName) ? $rawName : '',
            'normalized_name' => $normalizedName,
            'position' => is_string($positionValue) ? trim($positionValue) : null,
            'request_date' => $requestDate,
            'raw_date' => $rawDate,
            'request_time' => $requestTime,
            'raw_time' => $rawTime,
            'leave_type' => $leaveType?->value,
            'raw_leave_type' => is_string($rawLeaveType) ? $rawLeaveType : '',
            'description' => $description,
            'attachment' => is_string($attachmentValue) && trim($attachmentValue) !== '' ? trim($attachmentValue) : null,
            'absence_reason' => $absenceReason === null ? null : AbsenceReason::from($absenceReason)->value,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function value(array $row, array $mapping, string $field, string $key): mixed
    {
        if (! array_key_exists($field, $mapping)) {
            return null;
        }

        return $row[$mapping[$field]][$key] ?? null;
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell['raw'] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeLeaveType(string $leaveType): string
    {
        $leaveType = mb_strtolower(trim($leaveType), 'UTF-8');
        $leaveType = preg_replace('/\s*\/\s*/u', '/', $leaveType) ?? $leaveType;

        return trim(preg_replace('/\s+/u', ' ', $leaveType) ?? $leaveType);
    }
}
