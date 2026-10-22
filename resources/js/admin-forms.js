const statusFor = (form) => form.querySelector('[data-form-status]');

const scheduleCategorySelectForForm = (form) => form.querySelector('[data-schedule-category-select]')
    || document.querySelector(`[form="${form.id}"][data-schedule-category-select]`);

const syncScheduleProfileOptions = (categorySelect) => {
    const form = categorySelect.form;
    const profileSelect = form?.querySelector('[data-schedule-profile-select]')
        || document.querySelector(`[form="${form?.id}"][data-schedule-profile-select]`);
    if (!profileSelect) return;

    const categoryId = categorySelect.value;
    const selectedCategory = categorySelect.selectedOptions[0];
    const categoryIsActive = Boolean(categoryId) && selectedCategory?.dataset.categoryActive !== 'false';
    const isEditing = !profileSelect.hasAttribute('data-edit-input')
        || profileSelect.closest('tr')?.dataset.editing === 'true';
    profileSelect.disabled = !categoryIsActive || !isEditing;

    profileSelect.querySelectorAll('option[data-category-id]').forEach((option) => {
        const categoryMatches = option.dataset.categoryId === categoryId;
        const isAvailable = categoryIsActive && categoryMatches && option.dataset.selectable !== 'false';
        option.hidden = !categoryIsActive || !categoryMatches;
        option.disabled = !isAvailable;
        if (!categoryMatches && option.selected) profileSelect.value = '';
    });
};

document.querySelectorAll('[data-schedule-category-select]').forEach((categorySelect) => {
    syncScheduleProfileOptions(categorySelect);
    categorySelect.addEventListener('change', () => syncScheduleProfileOptions(categorySelect));
});

const showStatus = (form, message, isError = false) => {
    const status = statusFor(form);

    if (!status) return;

    status.textContent = message;
    status.classList.toggle('text-rose-700', isError);
    status.classList.toggle('text-emerald-700', !isError);
};

const responseMessage = (payload, response) => {
    if (payload?.errors) {
        const messages = Object.values(payload.errors).flat();
        if (messages.length) return messages.join(' ');
    }

    if (response.ok && payload?.message) return payload.message;
    if (response.status === 409) return payload?.message || 'Data duplikat: file ini sudah pernah diproses.';
    if (response.status === 422) return payload?.message || 'Periksa kembali data yang dimasukkan.';

    return response.ok ? 'Perubahan berhasil disimpan.' : 'Permintaan gagal. Periksa data dan coba lagi.';
};

const setBusy = (form, busy) => {
    form.toggleAttribute('aria-busy', busy);
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = busy;
    });
};

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('form[data-api-form]');
    if (!form) return;

    event.preventDefault();
    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;

    setBusy(form, true);
    showStatus(form, 'Menyimpan...');

    try {
        const response = await fetch(form.dataset.endpoint, {
            method: form.dataset.method || 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: new FormData(form),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            showStatus(form, responseMessage(payload, response), true);
            return;
        }

        showStatus(form, responseMessage(payload, response));
        if (form.dataset.reloadOnSuccess === 'true') {
            if (form.dataset.closeDialogOnSuccess === 'true') {
                form.closest('dialog')?.close();
            }
            window.setTimeout(() => window.location.reload(), 450);
        } else {
            form.reset();
            const categorySelect = scheduleCategorySelectForForm(form);
            if (categorySelect) syncScheduleProfileOptions(categorySelect);
        }
    } catch {
        showStatus(form, 'Tidak dapat terhubung ke server. Coba lagi.', true);
    } finally {
        setBusy(form, false);
    }
});

const massProfileDialog = document.querySelector('#mass-profile-dialog');
const massProfileReadinessDialog = document.querySelector('#mass-profile-readiness-dialog');
let readinessDialogOpener = null;

massProfileReadinessDialog?.addEventListener('close', () => {
    readinessDialogOpener?.focus();
    readinessDialogOpener = null;
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || !massProfileReadinessDialog?.open) return;

    event.preventDefault();
    massProfileReadinessDialog.close();
});

const appendMassProfileItems = (list, items, format) => {
    list.replaceChildren();
    if (!items.length) {
        const item = document.createElement('li');
        item.textContent = 'Tidak ada';
        list.append(item);
        return;
    }

    items.forEach((value) => {
        const item = document.createElement('li');
        item.textContent = format(value);
        list.append(item);
    });
};

document.addEventListener('click', async (event) => {
    const readinessButton = event.target.closest('[data-open-readiness]');
    if (readinessButton) {
        if (!massProfileReadinessDialog) return;

        readinessDialogOpener = readinessButton;
        massProfileReadinessDialog.showModal();
        massProfileReadinessDialog.querySelector('[data-readiness-target][aria-pressed="true"]')?.focus();
        return;
    }

    const readinessTarget = event.target.closest('[data-readiness-target]');
    if (readinessTarget && massProfileReadinessDialog?.contains(readinessTarget)) {
        const selectedTarget = readinessTarget.dataset.readinessTarget;

        massProfileReadinessDialog.querySelectorAll('[data-readiness-target]').forEach((button) => {
            const selected = button === readinessTarget;
            button.setAttribute('aria-pressed', String(selected));
            button.className = selected
                ? 'rounded-t-lg border-b-2 border-blue-600 px-4 py-2 text-sm font-semibold text-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500'
                : 'rounded-t-lg border-b-2 border-transparent px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500';
        });
        massProfileReadinessDialog.querySelectorAll('[data-readiness-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.readinessPanel !== selectedTarget;
        });
        return;
    }

    const previewButton = event.target.closest('[data-mass-profile-preview]');
    if (previewButton) {
        if (previewButton.disabled || !massProfileDialog) return;

        massProfileDialog.dataset.applyEndpoint = previewButton.dataset.applyEndpoint;
        massProfileDialog.dataset.confirmationFingerprint = '';
        massProfileDialog.querySelector('[data-mass-profile-title]').textContent = `Terapkan profil ${previewButton.dataset.target === 'normal' ? 'Normal' : 'Liburan Semester'}`;
        massProfileDialog.querySelector('[data-mass-profile-status]').textContent = 'Memuat rencana...';
        massProfileDialog.querySelector('[data-mass-profile-eligible]').textContent = '...';
        massProfileDialog.querySelector('[data-mass-profile-replacements]').textContent = '...';
        massProfileDialog.querySelector('[data-mass-profile-new-assignments]').textContent = '...';
        massProfileDialog.querySelector('[data-mass-profile-confirm]').disabled = true;
        massProfileDialog.querySelector('[data-mass-profile-confirm]').className = 'cursor-not-allowed rounded-lg bg-slate-300 px-4 py-2 text-sm font-semibold text-slate-600';
        massProfileDialog.showModal();

        try {
            const response = await fetch(previewButton.dataset.previewEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                massProfileDialog.querySelector('[data-mass-profile-status]').textContent = payload.message || 'Rencana tidak dapat dimuat.';
                return;
            }

            const plan = payload.data;
            massProfileDialog.dataset.confirmationFingerprint = plan.confirmation_fingerprint;
            massProfileDialog.querySelector('[data-mass-profile-eligible]').textContent = String(plan.eligible_employee_count);
            massProfileDialog.querySelector('[data-mass-profile-replacements]').textContent = String(plan.replacement_count);
            massProfileDialog.querySelector('[data-mass-profile-new-assignments]').textContent = String(plan.new_assignment_count);
            appendMassProfileItems(
                massProfileDialog.querySelector('[data-mass-profile-included]'),
                plan.included_categories,
                (category) => `${category.name}: ${category.active_employee_count} pegawai — ${category.profile_name}`,
            );
            appendMassProfileItems(
                massProfileDialog.querySelector('[data-mass-profile-excluded]'),
                plan.excluded_categories,
                (category) => `${category.name}: ${category.active_employee_count} pegawai — ${category.reason}`,
            );

            const blockerContainer = massProfileDialog.querySelector('[data-mass-profile-blockers-container]');
            const blockerList = massProfileDialog.querySelector('[data-mass-profile-blockers]');
            blockerContainer.classList.toggle('hidden', plan.blocking_conditions.length === 0);
            appendMassProfileItems(
                blockerList,
                plan.blocking_conditions,
                (blocker) => blocker.category ? `${blocker.category}: ${blocker.reason}` : blocker.reason,
            );

            const confirmButton = massProfileDialog.querySelector('[data-mass-profile-confirm]');
            const canApply = plan.blocking_conditions.length === 0 && plan.eligible_employee_count > 0;
            confirmButton.disabled = !canApply;
            confirmButton.className = canApply
                ? 'rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800'
                : 'cursor-not-allowed rounded-lg bg-slate-300 px-4 py-2 text-sm font-semibold text-slate-600';
            massProfileDialog.querySelector('[data-mass-profile-status]').textContent = canApply
                ? 'Konfirmasi akan mengganti assignment yang tercantum. Perubahan dibatalkan otomatis jika cakupan berubah.'
                : 'Penerapan terkunci karena terdapat penghalang atau tidak ada pegawai yang memenuhi syarat.';
        } catch {
            massProfileDialog.querySelector('[data-mass-profile-status]').textContent = 'Tidak dapat terhubung ke server saat memuat rencana.';
        }

        return;
    }

    const confirmButton = event.target.closest('[data-mass-profile-confirm]');
    if (!confirmButton || confirmButton.disabled || !massProfileDialog) return;

    confirmButton.disabled = true;
    massProfileDialog.querySelector('[data-mass-profile-status]').textContent = 'Memproses penerapan...';
    try {
        const response = await fetch(massProfileDialog.dataset.applyEndpoint, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                confirmed: true,
                confirmation_fingerprint: massProfileDialog.dataset.confirmationFingerprint,
            }),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            massProfileDialog.querySelector('[data-mass-profile-status]').textContent = payload.message || 'Penerapan gagal; tidak ada perubahan yang dikonfirmasi.';
            if (response.status === 409) {
                massProfileDialog.dataset.confirmationFingerprint = '';
                massProfileDialog.querySelector('[data-mass-profile-status]').textContent = payload.message || 'Cakupan berubah. Tutup dialog lalu muat ulang rencana.';
                return;
            }
            confirmButton.disabled = false;
            return;
        }

        massProfileDialog.querySelector('[data-mass-profile-status]').textContent = payload.message || 'Penerapan berhasil.';
        window.setTimeout(() => window.location.reload(), 700);
    } catch {
        massProfileDialog.querySelector('[data-mass-profile-status]').textContent = 'Tidak dapat terhubung ke server. Periksa status sebelum mencoba kembali.';
        confirmButton.disabled = false;
    }
});

document.addEventListener('click', (event) => {
    const editButton = event.target.closest('[data-employee-edit]');
    const cancelButton = event.target.closest('[data-employee-cancel]');

    if (editButton || cancelButton) {
        const employeeId = (editButton || cancelButton).dataset.employeeEdit
            || (editButton || cancelButton).dataset.employeeCancel;
        const form = document.querySelector(`[data-employee-profile-form="${employeeId}"]`);
        const row = form?.closest('tr');

        if (!form || !row) return;

        if (cancelButton) form.reset();

        row.dataset.editing = editButton ? 'true' : 'false';
        document.querySelectorAll(`[form="${form.id}"][data-edit-input]`).forEach((input) => {
            input.disabled = !editButton;
            input.classList.toggle('hidden', !editButton);
        });
        const categorySelect = scheduleCategorySelectForForm(form);
        if (categorySelect) syncScheduleProfileOptions(categorySelect);
        row.querySelectorAll('[data-edit-view]').forEach((value) => {
            value.classList.toggle('hidden', Boolean(editButton));
        });
        row.querySelector('[data-employee-edit]')?.classList.toggle('hidden', Boolean(editButton));
        row.querySelector('[data-employee-cancel]')?.classList.toggle('hidden', !editButton);
        row.querySelector('[data-edit-save]')?.classList.toggle('hidden', !editButton);
        row.querySelectorAll('[data-edit-hide]').forEach((control) => {
            control.classList.toggle('hidden', Boolean(editButton));
        });
        showStatus(form, '');
        return;
    }

    const openButton = event.target.closest('[data-open-dialog]');
    if (openButton) {
        document.getElementById(openButton.dataset.openDialog)?.showModal();
        return;
    }

    const closeButton = event.target.closest('[data-close-dialog]');
    if (closeButton) {
        closeButton.closest('dialog')?.close();
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('form[data-import-form]');
    if (!form) return;

    event.preventDefault();
    const action = event.submitter?.value || 'preview';
    const preview = action === 'preview';
    const isSync = action === 'sync';
    const endpoint = preview
        ? form.dataset.previewEndpoint
        : isSync
            ? form.dataset.syncEndpoint || form.dataset.previewEndpoint.replace('/preview', '/sync')
            : form.dataset.importEndpoint;
    const output = form.querySelector('[data-import-preview]');
    const importButton = form.querySelector('[data-import-confirm]');

    if (!preview && form.dataset.previewReady !== 'true') {
        showStatus(form, 'Jalankan preview sebelum menjalankan aksi ini.', true);
        return;
    }

    setBusy(form, true);
    showStatus(form, preview ? 'Memeriksa file...' : isSync ? 'Mensinkronkan pegawai...' : 'Mengimpor file...');

    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: new FormData(form),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            form.dataset.previewReady = 'false';
            importButton.hidden = true;
            showStatus(form, responseMessage(payload, response), true);
            return;
        }

        const data = payload.data || {};

        if (preview || isSync) {
            form.dataset.previewReady = 'true';
            output.hidden = false;

            if (isSync) {
                const summary = [
                    ['File', data.filename],
                    ['Unit', data.unit?.code || data.unit?.name],
                    ['Baris', data.total_rows],
                    ['Pegawai terdeteksi', data.employees_detected],
                    ['Pegawai dibuat', data.employees_created],
                    ['Pegawai sudah ada', data.employees_existing],
                    ['Baris dilewati', data.employees_skipped],
                    ['Kategori dibuat', data.categories_created],
                ].filter(([, value]) => value !== undefined && value !== null && value !== '');

                output.replaceChildren(...summary.map(([label, value]) => {
                    const item = document.createElement('li');
                    item.textContent = `${label}: ${value}`;
                    return item;
                }));

                if (data.sync_conflicts?.length) {
                    const heading = document.createElement('li');
                    heading.className = 'mt-2 border-t border-amber-200 pt-2 font-semibold text-amber-800';
                    heading.textContent = 'Conflict nama pegawai';
                    output.append(heading);

                    data.sync_conflicts.forEach((conflict) => {
                        const item = document.createElement('li');
                        item.className = 'text-amber-800';
                        item.textContent = `Fingerprint ID ${conflict.fingerprint_id}: Master = ${conflict.master_name || '-'} | File = ${conflict.file_name || '-'}`;
                        output.append(item);
                    });
                }

                showStatus(form, payload.message || 'Sinkronisasi pegawai selesai.');
                setBusy(form, false);
                return;
            }

            const summary = [
                ['File', data.filename],
                ['Unit', data.unit?.code || data.unit?.name],
                ['Baris', data.total_rows],
                ['Pegawai terdeteksi', data.employees_detected],
                ['Periode', [data.period_start, data.period_end].filter(Boolean).join(' s/d ')],
                ['Timestamp mentah', data.raw_timestamps_detected],
                ['Timestamp valid', data.valid_timestamps ?? data.parsed_timestamp_count],
                ['Timestamp tidak valid', data.invalid_timestamps],
                ['Cocok', data.matched_employees ?? data.matched],
                ['Tidak cocok', data.unmatched_employees ?? data.unmatched],
                ['Ambigu', data.ambiguous_employees ?? data.ambiguous],
                ['Baris tidak valid', data.invalid_rows],
                ['Perlu verifikasi kategori', data.needs_verification_count ?? 0],
                ['File pernah diimpor', data.already_imported ? 'Ya' : 'Tidak'],
            ].filter(([, value]) => value !== undefined && value !== null && value !== '');

            output.replaceChildren(...summary.map(([label, value]) => {
                const item = document.createElement('li');
                item.textContent = `${label}: ${value}`;
                return item;
            }));

            if (data.sync_conflicts?.length) {
                const heading = document.createElement('li');
                heading.className = 'mt-2 border-t border-amber-200 pt-2 font-semibold text-amber-800';
                heading.textContent = 'Conflict nama pegawai';
                output.append(heading);

                data.sync_conflicts.forEach((conflict) => {
                    const item = document.createElement('li');
                    item.className = 'text-amber-800';
                    item.textContent = `Fingerprint ID ${conflict.fingerprint_id}: Master = ${conflict.master_name || '-'} | File = ${conflict.file_name || '-'}`;
                    output.append(item);
                });
            }

            if (data.category_assignments?.length) {
                const heading = document.createElement('li');
                heading.className = 'mt-2 border-t border-slate-200 pt-2 font-semibold text-slate-800';
                heading.textContent = 'Kategori dan jam kerja karyawan';
                output.append(heading);

                data.category_assignments.forEach((assignment) => {
                    const item = document.createElement('li');
                    const sourceHours = assignment.source_start_time && assignment.source_end_time
                        ? `${assignment.source_start_time.slice(0, 5)}-${assignment.source_end_time.slice(0, 5)}`
                        : 'jam tidak tersedia';
                    const employeeHours = assignment.employee_start_time && assignment.employee_end_time
                        ? `${assignment.employee_start_time.slice(0, 5)}-${assignment.employee_end_time.slice(0, 5)}`
                        : 'belum ditetapkan';
                    const status = assignment.needs_verification ? 'Perlu verifikasi' : 'Terverifikasi';
                    item.textContent = `ID ${assignment.fingerprint_id || '-'} ${assignment.employee_name || ''}: departemen ${assignment.department || '-'} -> ${assignment.source_category || 'kategori tidak ditemukan'} (${sourceHours}); kategori karyawan ${assignment.employee_category || 'belum ada'} (${employeeHours}); ${status}`;
                    output.append(item);
                });
            }

            if (data.verification_issues?.length) {
                const heading = document.createElement('li');
                heading.className = 'mt-2 border-t border-amber-200 pt-2 font-semibold text-amber-800';
                heading.textContent = 'Data yang perlu diverifikasi';
                output.append(heading);

                data.verification_issues.forEach((issue) => {
                    const item = document.createElement('li');
                    const sourceHours = issue.source_start_time && issue.source_end_time
                        ? ` (${issue.source_start_time.slice(0, 5)}-${issue.source_end_time.slice(0, 5)})`
                        : '';
                    const employeeHours = issue.employee_start_time && issue.employee_end_time
                        ? `; jadwal karyawan: ${issue.employee_start_time.slice(0, 5)}-${issue.employee_end_time.slice(0, 5)}`
                        : '';
                    item.className = 'text-amber-800';
                    item.textContent = `Baris ${issue.row}, ID ${issue.fingerprint_id || '-'}, ${issue.employee_name || 'nama kosong'}, departemen ${issue.department || '-'}${issue.source_category ? ` -> ${issue.source_category}${sourceHours}` : ''}${employeeHours}: ${issue.message}`;
                    output.append(item);
                });
            }

            output.hidden = false;
            importButton.hidden = false;
            showStatus(form, data.already_imported
                ? 'File ini sudah pernah diimpor untuk unit tersebut. Sistem akan melakukan sinkronisasi batch lama agar mapping pegawai dan attendance diperbarui.'
                : 'Preview selesai. Periksa ringkasan sebelum impor.');
            return;
        }

        form.dataset.previewReady = 'false';
        importButton.hidden = true;
        const batchId = payload?.data?.id;
        if (batchId) {
            showStatus(form, 'Import berhasil disimpan. Mengalihkan ke detail batch...');
            window.setTimeout(() => {
                window.location.href = `/fingerprint-imports/${batchId}`;
            }, 250);
            return;
        }

        showStatus(form, 'Impor berhasil disimpan.');
        window.setTimeout(() => window.location.reload(), 600);
    } catch {
        showStatus(form, 'Tidak dapat memproses file. Periksa koneksi dan coba lagi.', true);
    } finally {
        setBusy(form, false);
    }
});

document.addEventListener('input', (event) => {
    const form = event.target.closest('form[data-import-form]');
    if (!form) return;

    form.dataset.previewReady = 'false';
    const importButton = form.querySelector('[data-import-confirm]');
    if (importButton) importButton.hidden = true;
});

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-replace-file]');
    if (!trigger) return;

    const form = trigger.closest('form[data-import-form]');
    if (!form) return;

    const input = form.querySelector('input[type="file"]');
    if (input) {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    form.dataset.previewReady = 'false';
    const preview = form.querySelector('[data-import-preview]');
    const importButton = form.querySelector('[data-import-confirm]');
    if (preview) {
        preview.replaceChildren();
        preview.hidden = true;
    }
    if (importButton) importButton.hidden = true;
    showStatus(form, 'File baru siap dipilih.');
});

document.addEventListener('reset', (event) => {
    const form = event.target.closest('form[data-import-form]');
    if (!form) return;

    form.dataset.previewReady = 'false';
    const importButton = form.querySelector('[data-import-confirm]');
    if (importButton) importButton.hidden = true;
    const preview = form.querySelector('[data-import-preview]');
    if (preview) {
        preview.replaceChildren();
        preview.hidden = true;
    }
});

const manualLeaveValidationMessages = {
    unit: 'Pilih unit karyawan.',
    employee: 'Pilih karyawan dari hasil pencarian yang valid.',
    date: 'Pilih tanggal terjadinya izin.',
    reason: 'Pilih salah satu alasan izin.',
};

const setManualLeaveError = (form, field, message = '') => {
    const error = form.querySelector(`[data-manual-leave-error="${field}"]`);
    const control = field === 'unit'
        ? form.querySelector('[name="unit_id"]')
        : field === 'employee'
        ? form.querySelector('#manual-leave-employee-search')
        : form.querySelector(field === 'date' ? '[name="request_date"]' : '[name="leave_type"]');

    if (error) {
        error.textContent = message;
        error.classList.toggle('hidden', message === '');
    }

    if (control) {
        control.setAttribute('aria-invalid', message === '' ? 'false' : 'true');
    }
};

const clearManualLeaveErrors = (form) => {
    Object.keys(manualLeaveValidationMessages).forEach((field) => setManualLeaveError(form, field));
};

const manualLeaveSearchState = new WeakMap();
const clearManualLeaveResults = (form) => {
    const results = form.querySelector('[data-manual-leave-results]');
    if (results) {
        results.replaceChildren();
        results.hidden = true;
    }

    form.querySelector('#manual-leave-employee-search')?.setAttribute('aria-expanded', 'false');
};

const searchManualLeaveEmployees = async (form) => {
    const input = form.querySelector('#manual-leave-employee-search');
    const status = form.querySelector('[data-manual-leave-search-status]');
    const results = form.querySelector('[data-manual-leave-results]');
    const unitId = form.querySelector('[name="unit_id"]')?.value || '';
    const search = input?.value.trim() || '';
    const state = manualLeaveSearchState.get(form) || {};

    if (state.controller) state.controller.abort();
    clearManualLeaveResults(form);

    if (!unitId) {
        status.textContent = 'Pilih unit sebelum mencari karyawan.';
        return;
    }

    if (search.length < 2) {
        status.textContent = search ? 'Masukkan minimal 2 karakter untuk mencari.' : 'Masukkan nama lengkap atau NIP.';
        return;
    }

    const controller = new AbortController();
    manualLeaveSearchState.set(form, { ...state, controller });
    status.textContent = 'Sedang mencari karyawan...';

    try {
        const url = new URL(form.dataset.employeeSearchEndpoint, window.location.origin);
        url.searchParams.set('unit_id', unitId);
        url.searchParams.set('search', search);

        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        });
        if (!response.headers.get('content-type')?.includes('application/json')) {
            throw new Error('Respons pencarian tidak valid. Periksa sesi login lalu coba lagi.');
        }
        const payload = await response.json();

        if (!response.ok) {
            const messages = Object.values(payload?.errors || {}).flat();
            throw new Error(messages.join(' ') || payload?.message || 'Pencarian karyawan gagal. Coba lagi.');
        }

        if (input.value.trim() !== search || form.querySelector('[name="unit_id"]')?.value !== unitId) return;

        const employees = Array.isArray(payload?.data) ? payload.data : [];
        if (employees.length === 0) {
            status.textContent = 'Karyawan tidak ditemukan pada unit ini.';
            return;
        }

        employees.forEach((employee) => {
            const item = document.createElement('li');
            const option = document.createElement('button');
            option.type = 'button';
            option.role = 'option';
            option.tabIndex = 0;
            option.dataset.manualLeaveEmployeeOption = '';
            option.dataset.employeeId = String(employee.id);
            option.dataset.employeeName = employee.name || '';
            option.dataset.employeeNip = employee.nip || '';
            option.className = 'w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-cyan-50 focus:bg-cyan-50 focus:outline-none';
            option.textContent = `${employee.name || 'Nama tidak tersedia'} — NIP: ${employee.nip || '—'}`;
            item.append(option);
            results.append(item);
        });

        results.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        status.textContent = `${employees.length} hasil ditemukan. Pilih satu karyawan.`;
    } catch (error) {
        if (error.name === 'AbortError') return;
        status.textContent = error.message || 'Pencarian karyawan gagal. Coba lagi.';
        status.classList.remove('text-slate-600', 'text-emerald-700');
        status.classList.add('text-rose-700');
    }
};

document.addEventListener('input', (event) => {
    const form = event.target.closest('form[data-manual-leave-form]');
    if (!form) return;

    if (event.target.matches('#manual-leave-employee-search')) {
        form.querySelector('[data-manual-leave-employee-id]').value = '';
        setManualLeaveError(form, 'employee');
        clearManualLeaveResults(form);
        const searchStatus = form.querySelector('[data-manual-leave-search-status]');
        if (searchStatus) {
            searchStatus.classList.remove('text-rose-700', 'text-emerald-700');
            searchStatus.classList.add('text-slate-600');
        }
        const state = manualLeaveSearchState.get(form) || {};
        if (state.timer) window.clearTimeout(state.timer);
        const timer = window.setTimeout(() => searchManualLeaveEmployees(form), 250);
        manualLeaveSearchState.set(form, { ...state, timer });
    } else if (event.target.matches('[name="request_date"]')) {
        setManualLeaveError(form, 'date');
    }
});

document.addEventListener('change', (event) => {
    const form = event.target.closest('form[data-manual-leave-form]');
    if (!form) return;

    if (event.target.matches('[name="unit_id"]')) {
        const state = manualLeaveSearchState.get(form) || {};
        if (state.timer) window.clearTimeout(state.timer);
        if (state.controller) state.controller.abort();
        manualLeaveSearchState.set(form, {});
        form.querySelector('[data-manual-leave-employee-id]').value = '';
        const input = form.querySelector('#manual-leave-employee-search');
        input.value = '';
        clearManualLeaveResults(form);
        setManualLeaveError(form, 'unit');
        setManualLeaveError(form, 'employee');
        const searchStatus = form.querySelector('[data-manual-leave-search-status]');
        searchStatus.textContent = 'Masukkan nama lengkap atau NIP.';
        searchStatus.classList.remove('text-rose-700', 'text-emerald-700');
        searchStatus.classList.add('text-slate-600');
    } else if (event.target.matches('[name="leave_type"]')) {
        setManualLeaveError(form, 'reason');
    }

    if (event.target.matches('[name="unit_id"]')) setManualLeaveError(form, 'unit');
});

document.addEventListener('click', (event) => {
    const option = event.target.closest('[data-manual-leave-employee-option]');
    if (option) {
        const form = option.closest('form[data-manual-leave-form]');
        const input = form.querySelector('#manual-leave-employee-search');
        form.querySelector('[data-manual-leave-employee-id]').value = option.dataset.employeeId;
        input.value = `${option.dataset.employeeName} — NIP: ${option.dataset.employeeNip || '—'}`;
        clearManualLeaveResults(form);
        form.querySelector('[data-manual-leave-search-status]').textContent = 'Karyawan dipilih.';
        form.querySelector('[data-manual-leave-search-status]').classList.remove('text-rose-700', 'text-slate-600');
        form.querySelector('[data-manual-leave-search-status]').classList.add('text-emerald-700');
        setManualLeaveError(form, 'employee');
        return;
    }

    const editButton = event.target.closest('[data-manual-leave-edit]');
    if (editButton) {
        const form = document.querySelector('form[data-manual-leave-form]');
        form.reset();
        form.dataset.manualLeaveId = editButton.dataset.id;
        form.querySelector('[name="unit_id"]').value = editButton.dataset.unitId;
        form.querySelector('[data-manual-leave-employee-id]').value = editButton.dataset.employeeId;
        form.querySelector('#manual-leave-employee-search').value =
            `${editButton.dataset.employeeName} — NIP: ${editButton.dataset.employeeNip || '—'}`;
        form.querySelector('[name="request_date"]').value = editButton.dataset.requestDate;
        form.querySelector('[name="leave_type"]').value = editButton.dataset.leaveType;
        form.querySelector('[data-manual-leave-search-status]').textContent = 'Karyawan dipilih.';
        form.querySelector('[data-manual-leave-search-status]').classList.remove('text-rose-700', 'text-slate-600');
        form.querySelector('[data-manual-leave-search-status]').classList.add('text-emerald-700');
        form.querySelector('button[type="submit"]').textContent = 'Perbarui izin';
        form.querySelector('#manual-leave-unit').dispatchEvent(new Event('change', { bubbles: true }));
        form.querySelector('[data-manual-leave-employee-id]').value = editButton.dataset.employeeId;
        form.querySelector('#manual-leave-employee-search').value =
            `${editButton.dataset.employeeName} — NIP: ${editButton.dataset.employeeNip || '—'}`;
        form.querySelector('[data-manual-leave-search-status]').textContent = 'Karyawan dipilih.';
        form.querySelector('[data-manual-leave-search-status]').classList.remove('text-slate-600');
        form.querySelector('[data-manual-leave-search-status]').classList.add('text-emerald-700');
        form.querySelector('[data-manual-leave-form-status]').textContent = 'Perbaiki data lalu simpan perubahan.';
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        form.querySelector('#manual-leave-employee-search').focus();
        return;
    }

    const deleteButton = event.target.closest('[data-manual-leave-delete]');
    if (!deleteButton || !window.confirm(deleteButton.dataset.confirm)) return;

    deleteButton.disabled = true;
    fetch(deleteButton.dataset.endpoint, {
        method: 'DELETE',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
    })
        .then(async (response) => {
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(responseMessage(payload, response));
            window.location.reload();
        })
        .catch((error) => {
            window.alert(error.message || 'Izin manual tidak dapat dihapus. Coba lagi.');
            deleteButton.disabled = false;
        });
});

document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-manual-leave-form]');
    if (!form) return;

    event.preventDefault();
    if (form.dataset.saving === 'true') return;
    clearManualLeaveErrors(form);

    const values = {
        unit: form.querySelector('[name="unit_id"]')?.value.trim() || '',
        employee: form.querySelector('[data-manual-leave-employee-id]')?.value.trim() || '',
        date: form.querySelector('[name="request_date"]')?.value.trim() || '',
        reason: form.querySelector('[name="leave_type"]')?.value.trim() || '',
    };
    const invalidFields = Object.keys(values).filter((field) => values[field] === '');

    invalidFields.forEach((field) => setManualLeaveError(form, field, manualLeaveValidationMessages[field]));

    if (invalidFields.length > 0) {
        const firstInvalid = invalidFields[0] === 'unit'
            ? form.querySelector('[name="unit_id"]')
            : invalidFields[0] === 'employee'
            ? form.querySelector('#manual-leave-employee-search')
            : form.querySelector(invalidFields[0] === 'date' ? '[name="request_date"]' : '[name="leave_type"]');
        firstInvalid?.focus();
        return;
    }

    const status = form.querySelector('[data-manual-leave-form-status]');
    const setFormStatus = (message, state = 'neutral') => {
        if (!status) return;
        status.textContent = message;
        status.classList.remove('text-slate-600', 'text-emerald-700', 'text-amber-700', 'text-rose-700');
        status.classList.add({
            neutral: 'text-slate-600',
            success: 'text-emerald-700',
            error: 'text-rose-700',
        }[state]);
    };

    form.dataset.saving = 'true';
    form.setAttribute('aria-busy', 'true');
    form.querySelectorAll('button').forEach((button) => { button.disabled = true; });
    setFormStatus('Menyimpan data izin...');

    const manualLeaveId = form.dataset.manualLeaveId;
    const endpoint = manualLeaveId
        ? form.dataset.manualLeaveUpdateTemplate.replace('__ID__', encodeURIComponent(manualLeaveId))
        : form.dataset.manualLeaveEndpoint;
    fetch(endpoint, {
        method: manualLeaveId ? 'PUT' : 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: new FormData(form),
    })
        .then(async (response) => {
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                const validationFields = {
                    employee_id: 'employee',
                    unit_id: 'unit',
                    request_date: 'date',
                    leave_type: 'reason',
                };
                Object.entries(payload?.errors || {}).forEach(([field, messages]) => {
                    const formField = validationFields[field];
                    if (formField) setManualLeaveError(form, formField, messages.join(' '));
                });
                setFormStatus(responseMessage(payload, response), 'error');
                return;
            }

            form.reset();
            setFormStatus(payload?.message || 'Data izin berhasil disimpan.', 'success');
            window.setTimeout(() => window.location.reload(), 500);
        })
        .catch(() => {
            setFormStatus('Tidak dapat terhubung ke server. Data belum dapat dikonfirmasi.', 'error');
        })
        .finally(() => {
            form.dataset.saving = 'false';
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button').forEach((button) => { button.disabled = false; });
        });
});

document.addEventListener('reset', (event) => {
    const form = event.target.closest('form[data-manual-leave-form]');
    if (!form) return;

    clearManualLeaveErrors(form);
    delete form.dataset.manualLeaveId;
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) submitButton.textContent = 'Simpan';
    const state = manualLeaveSearchState.get(form) || {};
    if (state.timer) window.clearTimeout(state.timer);
    if (state.controller) state.controller.abort();
    manualLeaveSearchState.delete(form);
    clearManualLeaveResults(form);
    const searchStatus = form.querySelector('[data-manual-leave-search-status]');
    if (searchStatus) {
        searchStatus.textContent = 'Pilih unit dan masukkan minimal 2 karakter.';
        searchStatus.classList.remove('text-rose-700', 'text-emerald-700');
        searchStatus.classList.add('text-slate-600');
    }

    const status = form.querySelector('[data-manual-leave-form-status]');
    if (status) {
        status.textContent = 'Data akan disimpan setelah backend mengonfirmasi.';
        status.classList.remove('text-amber-700', 'text-emerald-700', 'text-rose-700');
        status.classList.add('text-slate-600');
    }
});