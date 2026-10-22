<?php

namespace App\Services\Fingerprint;

class FingerprintRowParser
{
    public function __construct(
        private readonly FingerprintDateParser $dates,
        private readonly FingerprintTimeParser $times,
    ) {}

    public function parse(array $row): ?array
    {
        $fingerprintId = trim((string) ($row['fingerprint_id'] ?? ''));
        $rawName = $row['employee_name_raw'] ?? null;
        $rawDepartment = $row['employee_department_raw'] ?? null;
        $rawDate = $row['raw_date'] ?? null;
        $timeCells = $row['time_cells'] ?? [];

        if ($fingerprintId === '' && ($rawDate === null || $rawDate === '') && $this->allTimeCellsEmpty($timeCells)) {
            return null;
        }

        $errors = [];
        $attendanceDate = null;
        $timestamps = [];
        $rawTimestampCount = 0;
        $invalidTimestampCount = 0;

        if ($fingerprintId === '') {
            $errors[] = 'Fingerprint ID is empty.';
        }

        try {
            $attendanceDate = $this->dates->parse($rawDate);
        } catch (FingerprintImportException $exception) {
            $errors[] = $exception->getMessage();
        }

        foreach ($timeCells as $cell) {
            $rawValue = $cell['raw_value'];
            $parseValue = (string) ($cell['parse_value'] ?? $rawValue ?? '');

            if (trim($parseValue) === '') {
                continue;
            }

            preg_match_all('/\d{1,2}:\d{2}/', $parseValue, $rawMatches);
            $rawTimestampCount += count($rawMatches[0]);

            try {
                foreach ($this->times->parse($parseValue) as $time) {
                    $timestamps[] = [
                        'time' => $time,
                        'raw_value' => $rawValue === null ? null : (string) $rawValue,
                        'source_cell' => $cell['source_cell'] ?? null,
                    ];
                }
            } catch (FingerprintImportException $exception) {
                $errors[] = $exception->getMessage();
                $invalidTimestampCount++;
            }
        }

        if ($timestamps === []) {
            $errors[] = 'No valid timestamp found in row.';
        }

        return [
            'source_row' => $row['source_row'],
            'fingerprint_id' => $fingerprintId,
            'employee_name_raw' => $rawName === null ? null : (string) $rawName,
            'employee_department_raw' => $rawDepartment === null ? null : (string) $rawDepartment,
            'attendance_date' => $attendanceDate,
            'source_date_cell' => $row['source_date_cell'] ?? null,
            'timestamps' => $timestamps,
            'raw_timestamp_count' => $rawTimestampCount,
            'valid_timestamp_count' => count($timestamps),
            'invalid_timestamp_count' => $invalidTimestampCount,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function allTimeCellsEmpty(array $timeCells): bool
    {
        foreach ($timeCells as $cell) {
            if (trim((string) ($cell['parse_value'] ?? $cell['raw_value'] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
