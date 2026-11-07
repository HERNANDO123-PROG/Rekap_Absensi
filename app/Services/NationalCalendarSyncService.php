<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\NationalCalendarEvent;
use App\Models\Unit;
use App\Models\WorkCalendar;
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
        $countryCode = 'ID';
        $url = rtrim((string) config(
            'services.indonesian_holidays.base_url',
            'https://indonesian-holidays.ahrulsyamil.com',
        ), '/')."/holidays/{$year}";

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

        $payload = $response->json();
        $events = is_array($payload) ? ($payload['data'] ?? null) : null;
        $sources = is_array($payload) ? ($payload['meta']['sources'] ?? null) : null;

        if (
            ! is_array($payload)
            || ! is_array($payload['meta'] ?? null)
            || (int) ($payload['meta']['year'] ?? 0) !== $year
            || ! is_array($events)
            || ! array_is_list($events)
            || ! is_array($sources)
            || ! array_is_list($sources)
        ) {
            throw ValidationException::withMessages([
                'calendar_api' => 'Respons API kalender nasional tidak berbentuk daftar yang valid.',
            ]);
        }

        $officialSourceUrl = null;
        foreach ($sources as $source) {
            $candidateUrl = is_array($source) ? ($source['url'] ?? null) : null;
            $host = is_string($candidateUrl) ? parse_url($candidateUrl, PHP_URL_HOST) : null;
            if (
                is_array($source)
                && (int) ($source['year'] ?? 0) === $year
                && ($source['type'] ?? null) === 'skb'
                && is_string($candidateUrl)
                && filter_var($candidateUrl, FILTER_VALIDATE_URL) !== false
                && parse_url($candidateUrl, PHP_URL_SCHEME) === 'https'
                && is_string($host)
                && (strtolower($host) === 'go.id' || str_ends_with(strtolower($host), '.go.id'))
            ) {
                $officialSourceUrl = $candidateUrl;
                break;
            }
        }

        if ($officialSourceUrl === null) {
            throw ValidationException::withMessages([
                'calendar_api' => 'API kalender tidak menyertakan tautan keputusan resmi pemerintah.',
            ]);
        }

        $normalized = [];
        foreach ($events as $index => $event) {
            if (
                ! is_array($event)
                || ! is_string($event['date'] ?? null)
                || ! is_string($event['name'] ?? null)
                || trim($event['name']) === ''
                || ! in_array($event['type'] ?? null, ['national_holiday', 'joint_leave'], true)
                || ! is_bool($event['is_national'] ?? null)
                || $event['is_national'] !== (($event['type'] ?? null) === 'national_holiday')
                || (! is_string($event['description'] ?? null) && ($event['description'] ?? null) !== null)
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
                'local_name' => trim($event['name']),
                'name' => trim((string) ($event['description'] ?? $event['name'])),
                'types' => [$event['type']],
                'source' => 'SKB 3 Menteri',
                'source_url' => $officialSourceUrl,
            ];
        }

        return DB::transaction(function () use ($normalized, $url, $year, $userId, $countryCode): array {
            $created = 0;
            $updated = 0;
            $preserved = 0;
            $operationalCreated = 0;
            $operationalUpdated = 0;
            $operationalReplaced = 0;
            $operationalConflicts = 0;
            $replacedManualEntries = [];
            $syncedAt = now();
            $units = Unit::query()->whereIn('code', ['TK', 'SD'])->get();
            $syncedEvents = [];

            foreach ($normalized as $event) {
                $record = NationalCalendarEvent::query()->firstOrNew([
                    'country_code' => $event['country_code'],
                    'date' => $event['date'],
                    'name' => $event['name'],
                ]);

                if (! $record->exists) {
                    $legacyRecord = NationalCalendarEvent::query()
                        ->where('country_code', $event['country_code'])
                        ->whereDate('date', $event['date'])
                        ->where('source', 'Nager.Date')
                        ->where('verification_status', 'pending')
                        ->orderBy('id')
                        ->first();

                    if ($legacyRecord !== null) {
                        $record = $legacyRecord;
                    }
                }

                if ($record->exists && $record->verification_status !== 'pending') {
                    $preserved++;
                    $syncedEvents[] = $record;

                    continue;
                }

                $created += $record->exists ? 0 : 1;
                $updated += $record->exists ? 1 : 0;
                $record->fill([
                    ...$event,
                    'last_synced_at' => $syncedAt,
                    'verification_status' => 'pending',
                ])->save();
                $syncedEvents[] = $record;
            }

            foreach ($syncedEvents as $event) {
                foreach ($units as $unit) {
                    $calendar = WorkCalendar::query()
                        ->where('unit_id', $unit->id)
                        ->whereDate('date', $event->date)
                        ->lockForUpdate()
                        ->first();

                    if ($calendar !== null && $calendar->national_calendar_event_id !== null
                        && (int) $calendar->national_calendar_event_id !== (int) $event->id) {
                        $operationalConflicts++;

                        continue;
                    }

                    $previousManualEntry = $calendar !== null && $calendar->national_calendar_event_id === null
                        ? [
                            'calendar_id' => $calendar->id,
                            'unit_id' => $calendar->unit_id,
                            'date' => $calendar->date->format('Y-m-d'),
                            'type' => $calendar->type->value,
                            'title' => $calendar->title,
                            'description' => $calendar->description,
                            'is_working_day' => $calendar->is_working_day,
                        ]
                        : null;
                    $attributes = [
                        'national_calendar_event_id' => $event->id,
                        'type' => 'holiday',
                        'title' => $event->local_name,
                        'description' => $event->name.' · Sinkronisasi kalender nasional.',
                        'is_working_day' => false,
                        'start_time' => null,
                        'end_time' => null,
                    ];

                    if ($calendar === null) {
                        WorkCalendar::query()->create([
                            'unit_id' => $unit->id,
                            'date' => $event->date,
                            'national_calendar_event_id' => $event->id,
                            ...$attributes,
                        ]);
                        $operationalCreated++;

                        continue;
                    }

                    if ($previousManualEntry !== null) {
                        $notes = array_filter([
                            $previousManualEntry['title'],
                            $previousManualEntry['description'],
                        ]);
                        if ($notes !== []) {
                            $attributes['description'] .= ' Agenda sebelumnya: '.implode(' — ', $notes);
                        }
                        $replacedManualEntries[] = $previousManualEntry;
                        $operationalReplaced++;
                    }

                    if ($calendar->fill($attributes)->isDirty()) {
                        $calendar->save();
                        $operationalUpdated += $previousManualEntry === null ? 1 : 0;
                    }
                }
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
                    'operational_created' => $operationalCreated,
                    'operational_updated' => $operationalUpdated,
                    'operational_replaced_manual' => $operationalReplaced,
                    'operational_conflicts' => $operationalConflicts,
                    'replaced_manual_entries' => $replacedManualEntries,
                ], JSON_THROW_ON_ERROR),
            ]);

            return [
                'year' => $year,
                'received' => count($normalized),
                'created' => $created,
                'updated' => $updated,
                'preserved_verified' => $preserved,
                'operational_created' => $operationalCreated,
                'operational_updated' => $operationalUpdated,
                'operational_replaced_manual' => $operationalReplaced,
                'operational_conflicts' => $operationalConflicts,
                'data' => NationalCalendarEvent::query()
                    ->where('country_code', $countryCode)
                    ->whereYear('date', $year)
                    ->orderBy('date')
                    ->get(),
            ];
        });
    }
}
