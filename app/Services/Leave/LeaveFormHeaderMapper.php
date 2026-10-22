<?php

namespace App\Services\Leave;

class LeaveFormHeaderMapper
{
    private const HEADER_ALIASES = [
        'raw_name' => ['Nama Lengkap dan Gelar'],
        'position' => ['Jabatan'],
        'request_date' => ['Tanggal Pengajuan Izin'],
        'request_time' => [
            'Jam Kepulangan / Jam Perkiraan Kehadiran / Jam Kembali (jika tidak hadir/Dinas maka tulis 00.00)',
        ],
        'leave_type' => ['Keperluan Izin'],
        'description' => ['Keterangan Izin (Jelaskan)'],
        'attachment' => ['Dokumentasi (Khusus Perjalanan Dinas)', 'Dokumentasi'],
    ];

    private const REQUIRED_FIELDS = [
        'raw_name',
        'position',
        'request_date',
        'request_time',
        'leave_type',
        'description',
    ];

    public function map(array $headers): array
    {
        $aliases = [];

        foreach (self::HEADER_ALIASES as $field => $fieldAliases) {
            foreach ($fieldAliases as $alias) {
                $aliases[$this->normalize($alias)] = $field;
            }
        }

        $positions = [];
        $normalizedHeaders = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalize((string) ($header ?? ''));
            $normalizedHeaders[$index] = $normalized;

            if (isset($aliases[$normalized])) {
                $positions[$aliases[$normalized]][] = $index;
            }
        }

        $mapping = [];
        $ambiguousFields = [];

        foreach ($positions as $field => $indexes) {
            if (count($indexes) === 1) {
                $mapping[$field] = $indexes[0];
            } else {
                $ambiguousFields[] = $field;
            }
        }

        $missingFields = array_values(array_diff(self::REQUIRED_FIELDS, array_keys($mapping), $ambiguousFields));
        $recognizedIndexes = [];

        foreach ($positions as $indexes) {
            foreach ($indexes as $index) {
                $recognizedIndexes[] = $index;
            }
        }

        $additionalHeaders = [];

        foreach ($headers as $index => $header) {
            if (! in_array($index, $recognizedIndexes, true)) {
                $additionalHeaders[] = ['index' => $index, 'header' => (string) ($header ?? '')];
            }
        }

        $mappedFields = [];

        foreach ($mapping as $field => $index) {
            $mappedFields[$field] = (string) ($headers[$index] ?? '');
        }

        return [
            'detected_headers' => array_map(static fn ($header): string => (string) ($header ?? ''), array_values($headers)),
            'normalized_headers' => array_values($normalizedHeaders),
            'mapping' => $mapping,
            'mapped_fields' => $mappedFields,
            'missing_fields' => $missingFields,
            'ambiguous_fields' => $ambiguousFields,
            'additional_headers' => $additionalHeaders,
            'recognized_count' => count($mapping) + count($ambiguousFields),
        ];
    }

    public function normalize(string $header): string
    {
        $header = str_replace(["\xEF\xBB\xBF", "\u{FEFF}", "\u{00A0}"], ' ', $header);
        $header = preg_replace('/[\r\n\t]+/u', ' ', $header) ?? $header;
        $header = preg_replace('/[\p{Z}\s]+/u', ' ', $header) ?? $header;
        $header = trim($header);
        $header = preg_replace('/\s*\*\s*$/u', '', $header) ?? $header;

        return mb_strtolower(trim($header), 'UTF-8');
    }
}
