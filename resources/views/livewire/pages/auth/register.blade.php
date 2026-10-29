<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $name = '';
    public string $password = '';
    public string $password_confirmation = '';
    public bool $showPassword = false;
    public bool $showPasswordConfirmation = false;

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:'.User::class.',name'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ], [
            'name.required' => 'Nama pengguna wajib diisi.',
            'name.string' => 'Nama pengguna harus berupa teks.',
            'name.max' => 'Nama pengguna maksimal 255 karakter.',
            'name.unique' => 'Nama pengguna sudah digunakan, silakan pilih nama lain.',
            'password.required' => 'Password wajib diisi.',
            'password.string' => 'Password harus berupa teks.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.min' => 'Password minimal 8 karakter.',
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create([
            'name' => $validated['name'],
            'password' => $validated['password'],
        ]);

        event(new Registered($user));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }

    public function togglePassword(): void
    {
        $this->showPassword = ! $this->showPassword;
    }

    public function togglePasswordConfirmation(): void
    {
        $this->showPasswordConfirmation = ! $this->showPasswordConfirmation;
    }
}; ?>

<div class="auth-page">
    <div class="auth-shell">
        <div class="auth-card auth-card--wide">
            <div class="auth-card__inner">
                <div class="auth-brand">
                    <div class="auth-brand__logo" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 10.5 12 4l9 6.5V19a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1v-8.5Z" />
                        </svg>
                    </div>
                    <div class="auth-brand__text">
                        <span class="auth-brand__eyebrow">Akses Sistem</span>
                        <span class="auth-brand__title">Buat Akun</span>
                    </div>
                </div>

                <p class="auth-subtitle">Buat akun untuk mengakses sistem absensi sekolah dan kelola data kehadiran dengan aman.</p>

                <form wire:submit="register" class="auth-form">
                    <div class="auth-field">
                        <label for="name">Nama Pengguna</label>
                        <input wire:model="name" id="name" class="auth-input" type="text" name="name" placeholder="Masukkan username" required autofocus autocomplete="username" />
                        @error('name')
                            <span class="auth-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="auth-field">
                        <label for="password">Password</label>
                        <div class="auth-password-wrap">
                            <input wire:model="password" id="password" class="auth-input" type="{{ $showPassword ? 'text' : 'password' }}" name="password" placeholder="Buat password" required autocomplete="new-password" />
                            <button type="button" class="auth-toggle-password" wire:click="togglePassword" aria-label="Tampilkan password">
                                {{ $showPassword ? 'Sembunyikan' : 'Tampilkan' }}
                            </button>
                        </div>
                        @error('password')
                            <span class="auth-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="auth-field">
                        <label for="password_confirmation">Konfirmasi Password</label>
                        <div class="auth-password-wrap">
                            <input wire:model="password_confirmation" id="password_confirmation" class="auth-input" type="{{ $showPasswordConfirmation ? 'text' : 'password' }}" name="password_confirmation" placeholder="Ulangi password" required autocomplete="new-password" />
                            <button type="button" class="auth-toggle-password" wire:click="togglePasswordConfirmation" aria-label="Tampilkan konfirmasi password">
                                {{ $showPasswordConfirmation ? 'Sembunyikan' : 'Tampilkan' }}
                            </button>
                        </div>
                        @error('password_confirmation')
                            <span class="auth-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <button type="submit" class="auth-btn">
                        Daftar Akun
                    </button>
                </form>

                <div class="auth-meta">
                    Sudah punya akun? <a href="{{ route('login') }}" wire:navigate>Masuk di sini</a>
                </div>
            </div>
        </div>
    </div>
</div>
