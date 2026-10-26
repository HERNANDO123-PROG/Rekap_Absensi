<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\NationalCalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class NationalCalendarSyncService
{
    public function sync(int $year, ?int $userId = null): array
    {
        $countryCode = (string) config('services.nager_date.country', 'ID');
        $url = rtrim((string) config('services.nager_date.base_url', 'https://date.nager.at/api/v3'), '/')
            ."/PublicHolidays/{$year}/{$countryCode}";

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get($url);
            $response->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw ValidationException::withMessages([
                'calendar_api' => 'Sinkronisasi kalender nasional gagal. Periksa koneksi atau coba lagi nanti.',
            ]);
        }

        $events = $response->json();

        if (! is_array($events) || ! array_is_list($events)) {
            throw ValidationException::withMessages([
                'calendar_api' => 'Respons API kalender nasional tidak berbentuk daftar yang valid.',
            ]);
        }

        $normalized = [];
        foreach ($events as $index => $event) {
            if (
                ! is_array($event)
                || ! is_string($event['date'] ?? null)
                || ! is_string($event['localName'] ?? null)
                || trim($event['localName']) === ''
                || ! is_string($event['name'] ?? null)
                || trim($event['name']) === ''
                || ($event['countryCode'] ?? null) !== $countryCode
                || ! is_array($event['types'] ?? null)
                || ! array_is_list($event['types'])
                || count(array_filter($event['types'], 'is_string')) !== count($event['types'])
            ) {
                throw ValidationException::withMessages([
                    'calendar_api' => 'Data kalender nasional pada baris '.($index + 1).' tidak valid.',
                ]);
            }

            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $event['date']);
            } catch (\Throwable) {
                $date = false;
            }

            if ($date === false || $date->format('Y-m-d') !== $event['date'] || (int) $date->format('Y') !== $year) {
                throw ValidationException::withMessages([
                    'calendar_api' => 'Tanggal kalender nasional pada baris '.($index + 1).' tidak valid.',
                ]);
            }

            $normalized[] = [
                'country_code' => $countryCode,
                'date' => $event['date'],
                'local_name' => trim($event['localName']),
                'name' => trim($event['name']),
                'types' => array_values(array_filter($event['types'], 'is_string')),
                'source' => 'Nager.Date',
                'source_url' => $url,
            ];
        }

        return DB::transaction(function () use ($normalized, $url, $year, $userId): array {
            $created = 0;
            $updated = 0;
            $preserved = 0;
            $syncedAt = now();

            foreach ($normalized as $event) {
                $record = NationalCalendarEvent::query()->firstOrNew([
                    'country_code' => $event['country_code'],
                    'date' => $event['date'],
                    'name' => $event['name'],
                ]);

                if ($record->exists && $record->verification_status !== 'pending') {
                    $preserved++;

                    continue;
                }

                $created += $record->exists ? 0 : 1;
                $updated += $record->exists ? 1 : 0;
                $record->fill([
                    ...$event,
                    'last_synced_at' => $syncedAt,
                    'verification_status' => 'pending',
                ])->save();
            }

            AuditLog::create([
                'user_id' => $userId,
                'action' => 'national_calendar.synced',
                'module' => 'work_calendar',
                'record_type' => NationalCalendarEvent::class,
                'description' => json_encode([
                    'year' => $year,
                    'source_url' => $url,
                    'received' => count($normalized),
                    'created' => $created,
                    'updated' => $updated,
                    'preserved_verified' => $preserved,
                ], JSON_THROW_ON_ERROR),
            ]);

            return [
                'year' => $year,
                'received' => count($normalized),
                'created' => $created,
                'updated' => $updated,
                'preserved_verified' => $preserved,
                'data' => NationalCalendarEvent::query()
                    ->where('country_code', config('services.nager_date.country', 'ID'))
                    ->whereYear('date', $year)
                    ->orderBy('date')
                    ->get(),
            ];
        });
    }
}
