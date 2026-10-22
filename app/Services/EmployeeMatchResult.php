<?php

namespace App\Services;

use App\Enums\MatchingStatus;
use App\Models\Employee;
use Illuminate\Support\Collection;

final class EmployeeMatchResult
{
    public function __construct(
        public readonly MatchingStatus $status,
        public readonly ?Employee $employee,
        public readonly Collection $candidates,
    ) {}

    public static function fromCandidates(Collection $candidates): self
    {
        $candidates = $candidates->values();

        return match ($candidates->count()) {
            1 => new self(MatchingStatus::Matched, $candidates->first(), $candidates),
            default => new self(
                $candidates->isEmpty() ? MatchingStatus::Unmatched : MatchingStatus::Ambiguous,
                null,
                $candidates,
            ),
        };
    }
}
