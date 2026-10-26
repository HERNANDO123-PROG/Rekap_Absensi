<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\NationalCalendarEvent;
use App\Services\NationalCalendarSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NationalCalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['year' => ['required', 'integer', 'between:2000,2100']]);

        return response()->json([
            'data' => NationalCalendarEvent::query()
                ->where('country_code', config('services.nager_date.country', 'ID'))
                ->whereYear('date', $validated['year'])
                ->orderBy('date')
                ->get(),
        ]);
    }

    public function sync(Request $request, NationalCalendarSyncService $sync): JsonResponse
    {
        $validated = $request->validate(['year' => ['required', 'integer', 'between:2000,2100']]);

        return response()->json([
            'data' => $sync->sync((int) $validated['year'], $request->user()?->id),
            'message' => 'Kalender nasional berhasil disinkronkan. Data tetap menunggu keputusan operasional sekolah.',
        ]);
    }

    public function verify(Request $request, NationalCalendarEvent $event): JsonResponse
    {
        $validated = $request->validate([
            'verification_source_url' => ['required', 'url', 'max:500'],
        ]);
        $sourceHost = parse_url($validated['verification_source_url'], PHP_URL_HOST);
        if (
            ! is_string($sourceHost)
            || (strtolower($sourceHost) !== 'go.id' && ! str_ends_with(strtolower($sourceHost), '.go.id'))
        ) {
            throw ValidationException::withMessages([
                'verification_source_url' => 'Gunakan tautan ketetapan resmi pemerintah dengan domain .go.id.',
            ]);
        }

        return DB::transaction(function () use ($request, $event, $validated): JsonResponse {
            $event = NationalCalendarEvent::query()->lockForUpdate()->findOrFail($event->id);

            if ($event->verification_status === 'pending') {
                $event->update([
                    'verification_status' => 'verified',
                    'verification_source_url' => $validated['verification_source_url'],
                    'verified_by' => $request->user()?->id,
                    'verified_at' => now(),
                ]);
                AuditLog::create([
                    'user_id' => $request->user()?->id,
                    'action' => 'national_calendar.verified',
                    'module' => 'work_calendar',
                    'record_type' => NationalCalendarEvent::class,
                    'record_id' => $event->id,
                    'description' => json_encode([
                        'date' => $event->date->format('Y-m-d'),
                        'local_name' => $event->local_name,
                        'source_url' => $event->source_url,
                        'verification_source_url' => $event->verification_source_url,
                    ], JSON_THROW_ON_ERROR),
                ]);
            } else {
                throw ValidationException::withMessages([
                    'verification_status' => 'Data ini sudah diverifikasi dan tidak memengaruhi operasional sekolah.',
                ]);
            }

            return response()->json([
                'data' => $event->refresh(),
                'message' => 'Sumber kalender diverifikasi. Aturan ini belum diterapkan pada absensi; buat keputusan di kalender operasional sekolah.',
            ]);
        });
    }
}
