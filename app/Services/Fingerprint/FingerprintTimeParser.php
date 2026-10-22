<?php

namespace App\Services\Fingerprint;

class FingerprintTimeParser
{
    public function parse(string $rawValue): array
    {
        $rawValue = trim($rawValue);

        if ($rawValue === '') {
            throw new FingerprintImportException('Timestamp value is empty.');
        }

        preg_match_all('/\d{1,2}:\d{2}/', $rawValue, $matches, PREG_OFFSET_CAPTURE);

        if ($matches[0] === []) {
            throw new FingerprintImportException('Timestamp format is invalid.');
        }

        $times = [];
        $offset = 0;

        foreach ($matches[0] as [$token, $position]) {
            $separator = substr($rawValue, $offset, $position - $offset);

            if ($separator !== '' && preg_match('/^[\s,;|\/\\\\-]*$/u', $separator) !== 1) {
                throw new FingerprintImportException('Timestamp contains an unsupported separator or value.');
            }

            [$hour, $minute] = array_map('intval', explode(':', $token));

            if ($hour > 23 || $minute > 59) {
                throw new FingerprintImportException("Invalid timestamp '{$token}'. Valid times are 00:00 through 23:59.");
            }

            $times[] = sprintf('%02d:%02d', $hour, $minute);
            $offset = $position + strlen($token);
        }

        $trailing = substr($rawValue, $offset);

        if ($trailing !== '' && preg_match('/^[\s,;|\/\\\\-]*$/u', $trailing) !== 1) {
            throw new FingerprintImportException('Timestamp contains an unsupported separator or value.');
        }

        return $times;
    }
}
