<?php

use App\Http\Controllers\AttendanceProcessingController;
use App\Http\Controllers\EmployeeAliasController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FingerprintImportController;
use App\Http\Controllers\FingerprintPeriodReportController;
use App\Http\Controllers\LeaveImportController;
use App\Http\Controllers\ManualLeaveRequestController;
use App\Http\Controllers\NationalCalendarController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\WorkCalendarController;
use App\Http\Controllers\WorkScheduleCategoryController;
use App\Http\Controllers\WorkSchedulePeriodController;
use App\Http\Controllers\WorkScheduleController;
use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::post('/logout', function () {
    Auth::guard('web')->logout();

    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->middleware('auth')->name('logout');

Route::middleware(['auth'])->group(function () {
    Route::get('employees/manage', [EmployeeController::class, 'manage'])->name('employees.manage');
    Route::view('fingerprint-import', 'livewire.fingerprint-import.index')->name('fingerprint-import.index');
    Route::view('leave-import', 'livewire.leave-import.index')->name('leave-import.index');
    Route::view('work-schedule', 'livewire.work-schedule.index')->name('work-schedule.index');
    Route::view('work-schedule-categories/manage', 'livewire.work-schedule-categories.manage')->name('work-schedule-categories.manage');
    Route::view('work-calendar', 'livewire.work-calendar.index')->name('work-calendar.index');
    Route::view('rekap-periode', 'livewire.reports.period')->name('rekap.period');
    Route::get('rekap/pegawai/{employee}/fingerprint', [FingerprintPeriodReportController::class, 'show'])
        ->name('rekap.period.employee');
    Route::view('rekap-tahunan', 'livewire.reports.annual')->name('rekap.annual');
    Route::get('rekap/pegawai/{employee}', fn (Employee $employee) => view('livewire.reports.employee-detail', [
        'employeeId' => $employee->id,
    ]))->name('rekap.employee');
});

Route::middleware('auth')->prefix('employees')->name('employees.')->group(function () {
    Route::post('/mass-profile/{target}/preview', [EmployeeController::class, 'previewMassProfile'])
        ->whereIn('target', ['normal', 'liburan-semester'])
        ->name('mass-profile.preview');
    Route::post('/mass-profile/{target}', [EmployeeController::class, 'applyMassProfile'])
        ->whereIn('target', ['normal', 'liburan-semester'])
        ->name('mass-profile.apply');
    Route::get('/search', [EmployeeController::class, 'search'])->name('search');
    Route::get('/', [EmployeeController::class, 'index'])->name('index');
    Route::post('/', [EmployeeController::class, 'store'])->name('store');
    Route::get('/{employee}', [EmployeeController::class, 'show'])->name('show');
    Route::put('/{employee}', [EmployeeController::class, 'update'])->name('update');
    Route::post('/{employee}/activate', [EmployeeController::class, 'activate'])->name('activate');
    Route::post('/{employee}/deactivate', [EmployeeController::class, 'deactivate'])->name('deactivate');
    Route::post('/{employee}/aliases', [EmployeeAliasController::class, 'store'])->name('aliases.store');
    Route::put('/{employee}/aliases/{alias}', [EmployeeAliasController::class, 'update'])->name('aliases.update');
    Route::delete('/{employee}/aliases/{alias}', [EmployeeAliasController::class, 'destroy'])->name('aliases.destroy');
});

Route::middleware('auth')->prefix('fingerprint-imports')->name('fingerprint-imports.')->group(function () {
    Route::get('/', [FingerprintImportController::class, 'index'])->name('index');
    Route::get('/{batch}', [FingerprintImportController::class, 'show'])->name('show');
    Route::post('/preview', [FingerprintImportController::class, 'preview'])->name('preview');
    Route::post('/sync', [FingerprintImportController::class, 'sync'])->name('sync');
    Route::post('/', [FingerprintImportController::class, 'store'])->name('store');
    Route::post('/{batch}/reprocess', [FingerprintImportController::class, 'reprocess'])->name('reprocess');
});

Route::middleware('auth')->prefix('leave-imports')->name('leave-imports.')->group(function () {
    Route::post('/preview', [LeaveImportController::class, 'preview'])->name('preview');
    Route::post('/', [LeaveImportController::class, 'store'])->name('store');
});

Route::post('/leave-requests/manual', [ManualLeaveRequestController::class, 'store'])
    ->middleware('auth')
    ->name('leave-requests.manual.store');
Route::put('/leave-requests/manual/{leaveRequest}', [ManualLeaveRequestController::class, 'update'])
    ->middleware('auth')
    ->name('leave-requests.manual.update');
Route::delete('/leave-requests/manual/{leaveRequest}', [ManualLeaveRequestController::class, 'destroy'])
    ->middleware('auth')
    ->name('leave-requests.manual.destroy');

Route::post('/attendance/process', [AttendanceProcessingController::class, 'store'])
    ->middleware('auth')
    ->name('attendance.process');

Route::middleware('auth')->group(function () {
    Route::get('/reports/period', [ReportController::class, 'period'])->name('reports.period');
    Route::get('/reports/period/export', [ReportExportController::class, 'period'])->name('reports.period.export');
    Route::get('/reports/fingerprint-period/export', [ReportExportController::class, 'fingerprintPeriod'])
        ->name('reports.fingerprint-period.export');
    Route::get('/reports/annual', [ReportController::class, 'annual'])->name('reports.annual');
    Route::get('/reports/annual/export', [ReportExportController::class, 'annual'])->name('reports.annual.export');
    Route::get('/employees/{employee}/attendance', [ReportController::class, 'employeeAttendance'])->name('employees.attendance');
    Route::get('/reports/employee/{employee}/export', [ReportExportController::class, 'employee'])->name('reports.employee.export');

    Route::get('/work-schedules', [WorkScheduleController::class, 'index'])->name('work-schedules.index');
    Route::post('/work-schedules', [WorkScheduleController::class, 'store'])->name('work-schedules.store');
    Route::put('/work-schedules/{schedule}', [WorkScheduleController::class, 'update'])->name('work-schedules.update');
    Route::delete('/work-schedules/{schedule}', [WorkScheduleController::class, 'destroy'])->name('work-schedules.destroy');

    Route::prefix('/work-schedule-categories')->name('work-schedule-categories.')->controller(WorkScheduleCategoryController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/{category}/profiles', 'storeProfile')->name('profiles.store');
        Route::put('/{category}/profiles/{profile}', 'updateProfile')->name('profiles.update');
        Route::put('/{category}', 'update')->name('update');
        Route::post('/{category}/activate', 'activate')->name('activate');
        Route::post('/{category}/deactivate', 'deactivate')->name('deactivate');
    });

    Route::get('/work-calendars', [WorkCalendarController::class, 'index'])->name('work-calendars.index');
    Route::post('/work-calendars', [WorkCalendarController::class, 'store'])->name('work-calendars.store');
    Route::put('/work-calendars/{calendar}', [WorkCalendarController::class, 'update'])->name('work-calendars.update');
    Route::delete('/work-calendars/{calendar}', [WorkCalendarController::class, 'destroy'])->name('work-calendars.destroy');
    Route::post('/work-calendars/upsert', [WorkCalendarController::class, 'upsert'])->name('work-calendars.upsert');
    Route::post('/work-calendars/bulk-upsert', [WorkCalendarController::class, 'bulkUpsert'])->name('work-calendars.bulk-upsert');

    Route::get('/national-calendar-events', [NationalCalendarController::class, 'index'])->name('national-calendar-events.index');
    Route::post('/national-calendar-events/sync', [NationalCalendarController::class, 'sync'])->name('national-calendar-events.sync');
    Route::post('/national-calendar-events/{event}/verify', [NationalCalendarController::class, 'verify'])->name('national-calendar-events.verify');

    Route::get('/work-schedule-periods', [WorkSchedulePeriodController::class, 'index'])->name('work-schedule-periods.index');
    Route::post('/work-schedule-periods', [WorkSchedulePeriodController::class, 'store'])->name('work-schedule-periods.store');
    Route::put('/work-schedule-periods/{period}', [WorkSchedulePeriodController::class, 'update'])->name('work-schedule-periods.update');
    Route::delete('/work-schedule-periods/{period}', [WorkSchedulePeriodController::class, 'destroy'])->name('work-schedule-periods.destroy');
});

require __DIR__.'/auth.php';
