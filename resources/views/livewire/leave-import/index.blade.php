<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Import data</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Data Izin</h2>
            </div>
            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">{{ \App\Models\LeaveRequest::query()->count() }} formulir</span>
        </div>
    </x-slot>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">Input Excel</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900">Unggah data izin</h3>
                <p class="mt-1 text-sm text-slate-500">Unggah file Excel atau CSV seperti sebelumnya.</p>
            </div>
            <form data-import-form data-preview-endpoint="{{ route('leave-imports.preview') }}" data-import-endpoint="{{ route('leave-imports.store') }}" data-preview-ready="false" class="mt-5 space-y-4">
                <div>
                    <label for="leave-unit" class="mb-1 block text-sm font-medium text-slate-700">Unit</label>
                    <select id="leave-unit" name="unit_id" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none">
                        @foreach (\App\Models\Unit::query()->orderBy('name')->get() as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->code ?? $unit->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="leave-file" class="mb-1 block text-sm font-medium text-slate-700">File Excel atau CSV</label>
                    <input id="leave-file" name="file" type="file" accept=".xls,.xlsx,.csv" required class="block w-full rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-emerald-700 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white" />
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" value="preview" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-800">Preview izin</button>
                    <button type="submit" value="import" data-import-confirm hidden class="rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-cyan-800">Impor data</button>
                    <button type="reset" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</button>
                </div>
                <p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
                <ul data-import-preview hidden class="space-y-1 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"></ul>
            </form>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="manual-leave-heading">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-700">Input Manual</p>
                <h3 id="manual-leave-heading" class="mt-1 text-lg font-semibold text-slate-900">Catat izin karyawan</h3>
                <p class="mt-1 text-sm text-slate-500">Pilih karyawan, tanggal, dan alasan izin.</p>
            </div>

            <form data-manual-leave-form data-employee-search-endpoint="{{ route('employees.search') }}" data-manual-leave-endpoint="{{ route('leave-requests.manual.store') }}" data-manual-leave-update-template="{{ route('leave-requests.manual.update', ['leaveRequest' => '__ID__']) }}" novalidate class="mt-5 space-y-4">
                <div>
                    <label for="manual-leave-unit" class="mb-1 block text-sm font-medium text-slate-700">Unit karyawan</label>
                    <select id="manual-leave-unit" name="unit_id" required aria-describedby="manual-leave-unit-error" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none">
                        <option value="">Pilih unit</option>
                        @foreach (\App\Models\Unit::query()->orderBy('name')->get() as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->code ?? $unit->name }}</option>
                        @endforeach
                    </select>
                    <p id="manual-leave-unit-error" data-manual-leave-error="unit" class="mt-1 hidden text-sm text-rose-700"></p>
                </div>
                <div>
                    <label for="manual-leave-employee-search" class="mb-1 block text-sm font-medium text-slate-700">Cari karyawan</label>
                    <input
                        id="manual-leave-employee-search"
                        type="search"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="manual-leave-employee-results"
                        aria-expanded="false"
                        placeholder="Nama lengkap atau NIP"
                        aria-describedby="manual-leave-employee-help manual-leave-employee-status manual-leave-employee-error"
                        class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 placeholder:text-slate-400 focus:border-cyan-500 focus:outline-none"
                    />
                    <input type="hidden" name="employee_id" data-manual-leave-employee-id />
                    <p id="manual-leave-employee-help" class="mt-1 text-xs text-slate-500">Pilih unit, lalu cari berdasarkan nama lengkap atau NIP. Pilih pegawai dari hasil pencarian.</p>
                    <p id="manual-leave-employee-status" data-manual-leave-search-status role="status" aria-live="polite" class="mt-2 text-sm text-slate-600">Pilih unit dan masukkan minimal 2 karakter.</p>
                    <p id="manual-leave-employee-error" data-manual-leave-error="employee" class="mt-1 hidden text-sm text-rose-700"></p>
                    <ul id="manual-leave-employee-results" data-manual-leave-results role="listbox" aria-label="Hasil pencarian karyawan" hidden class="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-xl border border-slate-200 bg-white p-2 shadow-sm"></ul>
                </div>

                <div>
                    <label for="manual-leave-date" class="mb-1 block text-sm font-medium text-slate-700">Tanggal izin</label>
                    <input id="manual-leave-date" name="request_date" type="date" required aria-describedby="manual-leave-date-error" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none" />
                    <p id="manual-leave-date-error" data-manual-leave-error="date" class="mt-1 hidden text-sm text-rose-700"></p>
                </div>

                <div>
                    <label for="manual-leave-reason" class="mb-1 block text-sm font-medium text-slate-700">Alasan izin</label>
                    <select id="manual-leave-reason" name="leave_type" required aria-describedby="manual-leave-reason-error" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none">
                        <option value="">Pilih alasan izin</option>
                        <option value="tidak_hadir">Tidak Hadir</option>
                        <option value="dinas">Dinas</option>
                        <option value="keluar">Keluar</option>
                    </select>
                    <p id="manual-leave-reason-error" data-manual-leave-error="reason" class="mt-1 hidden text-sm text-rose-700"></p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-cyan-800 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2">Simpan</button>
                    <button type="reset" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">Reset</button>
                </div>
                <p data-manual-leave-form-status role="status" aria-live="polite" class="text-sm text-slate-600">Izin manual baru menunggu konfirmasi sebelum dihitung dalam rekap.</p>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2" aria-labelledby="manual-leave-history-heading">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-700">Data manual</p>
                <h3 id="manual-leave-history-heading" class="mt-1 text-lg font-semibold text-slate-900">Izin manual terbaru</h3>
                <p class="mt-1 text-sm text-slate-500">Hanya izin yang dibuat secara manual dapat diperbaiki atau dihapus dari sini.</p>
            </div>
            @php($manualLeaveRequests = \App\Models\LeaveRequest::query()->with('employee')->where('source', 'manual')->latest('request_date')->latest('id')->limit(50)->get())
            @if ($manualLeaveRequests->isEmpty())
                <p class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">Belum ada izin manual.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Tanggal</th>
                                <th class="px-3 py-3">Karyawan</th>
                                <th class="px-3 py-3">Jenis izin</th>
                                <th class="px-3 py-3">Status</th>
                                <th class="px-3 py-3">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($manualLeaveRequests as $manualLeaveRequest)
                                @php($isConfirmed = $manualLeaveRequest->approval_status === \App\Enums\LeaveApprovalStatus::Confirmed)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-3 text-slate-700">{{ $manualLeaveRequest->request_date->format('Y-m-d') }}</td>
                                    <td class="px-3 py-3">
                                        <span class="block font-medium text-slate-800">{{ $manualLeaveRequest->employee?->displayName() ?? $manualLeaveRequest->raw_name }}</span>
                                        <span class="block text-xs text-slate-500">NIP: {{ $manualLeaveRequest->employee?->nip ?? '—' }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-slate-700">{{ $manualLeaveRequest->raw_leave_type }}</td>
                                    <td class="whitespace-nowrap px-3 py-3">
                                        @if ($isConfirmed)
                                            <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold text-emerald-700">Terkonfirmasi</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold text-amber-700">Menunggu konfirmasi</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3">
                                        <button
                                            type="button"
                                            data-manual-leave-edit
                                            data-id="{{ $manualLeaveRequest->id }}"
                                            data-employee-id="{{ $manualLeaveRequest->employee_id }}"
                                            data-employee-name="{{ $manualLeaveRequest->employee?->displayName() ?? $manualLeaveRequest->raw_name }}"
                                            data-employee-nip="{{ $manualLeaveRequest->employee?->nip ?? '' }}"
                                            data-unit-id="{{ $manualLeaveRequest->unit_id }}"
                                            data-request-date="{{ $manualLeaveRequest->request_date->format('Y-m-d') }}"
                                            data-leave-type="{{ $manualLeaveRequest->leave_type->value }}"
                                            class="rounded-lg border border-cyan-200 px-3 py-1.5 text-xs font-medium text-cyan-800 hover:bg-cyan-50"
                                        >Edit</button>
                                        @if (! $isConfirmed)
                                            <button
                                                type="button"
                                                data-manual-leave-confirm
                                                data-endpoint="{{ route('leave-requests.manual.confirm', $manualLeaveRequest) }}"
                                                data-confirm="Konfirmasi izin manual untuk {{ $manualLeaveRequest->employee?->displayName() ?? $manualLeaveRequest->raw_name }} tanggal {{ $manualLeaveRequest->request_date->format('Y-m-d') }}?"
                                                class="ml-1 rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50"
                                            >Konfirmasi</button>
                                        @else
                                            <span class="ml-1 inline-flex rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-[11px] font-medium text-emerald-700">Terkonfirmasi</span>
                                        @endif
                                        <button
                                            type="button"
                                            data-manual-leave-delete
                                            data-endpoint="{{ route('leave-requests.manual.destroy', $manualLeaveRequest) }}"
                                            data-confirm="Hapus izin manual untuk {{ $manualLeaveRequest->employee?->displayName() ?? $manualLeaveRequest->raw_name }} tanggal {{ $manualLeaveRequest->request_date->format('Y-m-d') }}?"
                                            class="ml-1 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50"
                                        >Hapus</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-slate-500">Menampilkan maksimal 50 izin manual terbaru. Koreksi atau penghapusan akan menghitung ulang attendance pada tanggal dan pegawai yang terdampak, jika sebelumnya sudah diproses.</p>
            @endif
        </section>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2">
            <h3 class="text-lg font-semibold text-slate-900">Status import</h3>
            <div class="mt-5 space-y-4 text-sm">
                @php($batchStats = \App\Models\LeaveImportBatch::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'))
                <div class="flex items-center justify-between rounded-xl bg-emerald-50 px-3 py-2">
                    <span class="text-emerald-700">Selesai</span>
                    <strong class="text-emerald-700">{{ $batchStats->get('completed', 0) }}</strong>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-sky-50 px-3 py-2">
                    <span class="text-sky-700">Menunggu / diproses</span>
                    <strong class="text-sky-700">{{ $batchStats->get('pending', 0) + $batchStats->get('processing', 0) }}</strong>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-rose-50 px-3 py-2">
                    <span class="text-rose-700">Gagal</span>
                    <strong class="text-rose-700">{{ $batchStats->get('failed', 0) }}</strong>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
