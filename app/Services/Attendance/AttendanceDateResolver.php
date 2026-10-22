<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Services\Calendar\CalendarResolver;

class AttendanceDateResolver
{
    public function __construct(private readonly CalendarResolver $resolver) {}

    public function resolve(
        int $unitId,
        string $date,
        ?int $categoryId = null,
        ?Employee $employee = null,
    ): array {
        return $this->resolver->resolve($unitId, $date, $categoryId, $employee);
    }
}
