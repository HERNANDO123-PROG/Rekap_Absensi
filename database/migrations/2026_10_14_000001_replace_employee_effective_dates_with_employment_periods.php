<?php

use App\Services\NameNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('employees')->whereNotNull('employee_code')->exists()) {
            throw new RuntimeException(
                'Employee codes are present and cannot be discarded. Export or resolve them before rebuilding the employee schema.',
            );
        }

        if (DB::table('employees')->whereNull('effective_from')->exists()) {
            throw new RuntimeException(
                'Some employees have no effective start date, so their employment-period history cannot be migrated without an assumed date.',
            );
        }

        if (DB::table('employees')
            ->whereNotNull('effective_until')
            ->whereColumn('effective_until', '<', 'effective_from')
            ->exists()) {
            throw new RuntimeException('An employee has an end date before its start date; resolve it before migrating.');
        }

        if (! Schema::hasTable('employee_employment_periods')) {
            Schema::create('employee_employment_periods', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('employee_employment_periods', 'employee_employment_periods_employee_dates_idx')) {
            Schema::table('employee_employment_periods', function (Blueprint $table): void {
                $table->index(
                    ['employee_id', 'start_date', 'end_date'],
                    'employee_employment_periods_employee_dates_idx',
                );
            });
        }

        DB::table('employees')
            ->orderBy('id')
            ->chunkById(500, function ($employees): void {
                $now = now();
                $periods = $employees->map(fn ($employee): array => [
                    'employee_id' => $employee->id,
                    'start_date' => $employee->effective_from,
                    'end_date' => $employee->effective_until,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($periods !== []) {
                    DB::table('employee_employment_periods')->insert($periods);
                }
            });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['unit_id', 'active_employee_code']);
            $table->dropIndex(['normalized_name']);
            $table->dropColumn('active_employee_code');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn([
                'employee_code',
                'name',
                'normalized_name',
                'effective_from',
                'effective_until',
            ]);
        });
    }

    public function down(): void
    {
        $multiplePeriods = DB::table('employee_employment_periods')
            ->select('employee_id')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($multiplePeriods
            || DB::table('employees')
                ->whereNotIn('id', DB::table('employee_employment_periods')->select('employee_id'))
                ->exists()) {
            throw new RuntimeException(
                'Employment periods cannot be represented by the legacy single date range; preserve the new schema or consolidate periods explicitly.',
            );
        }

        if (DB::table('employees')
            ->whereRaw("COALESCE(namafinger, '') != COALESCE(nama_lengkap, '')")
            ->exists()) {
            throw new RuntimeException(
                'Fingerprint and full names differ and cannot be represented losslessly by the legacy name column.',
            );
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employee_code')->nullable();
            $table->string('name')->nullable();
            $table->string('normalized_name', 191)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('active_employee_code')
                ->nullable()
                ->virtualAs("CASE WHEN status = 'active' THEN employee_code ELSE NULL END");
        });

        $normalizer = new NameNormalizer;

        DB::table('employees')->orderBy('id')->chunkById(500, function ($employees) use ($normalizer): void {
            foreach ($employees as $employee) {
                $name = $employee->nama_lengkap ?? $employee->namafinger ?? '';
                $period = DB::table('employee_employment_periods')
                    ->where('employee_id', $employee->id)
                    ->first();

                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update([
                        'name' => $name,
                        'normalized_name' => $normalizer->normalize($name),
                        'effective_from' => $period->start_date,
                        'effective_until' => $period->end_date,
                    ]);
            }
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->index('normalized_name');
            $table->unique(['unit_id', 'active_employee_code']);
        });

        Schema::drop('employee_employment_periods');
    }
};
