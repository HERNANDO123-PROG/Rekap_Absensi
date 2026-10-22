<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use DateTimeInterface;

class EmployeeEligibilityService
{
    public function isEligibleOnDate(Employee $employee, DateTimeInterface|string $date): bool
    {
        return $this->ineligibilityReason($employee, $date) === null;
    }

    public function ineligibilityReason(Employee $employee, DateTimeInterface|string $date): ?string
    {
        if ($employee->status !== EmployeeStatus::Active) {
            return 'inactive';
        }

        return null;
    }
}
