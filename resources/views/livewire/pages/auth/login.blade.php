<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public bool $showPassword = false;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerateToken();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    public function togglePassword(): void
    {
        $this->showPassword = ! $this->showPassword;
    }
}; ?>

<div class="auth-page">
    <header class="auth-header">
        <div class="auth-header__inner">
            <div class="auth-header__brand">
                <div class="auth-header__logo" role="img" aria-label="Logo sementara Khazanah Ilmu">KI</div>
                <div class="auth-header__brand-copy">
                    <div class="auth-header__title">KHAZANAH ILMU</div>
                    <div class="auth-header__subtitle">ISLAMIC FULLDAY SCHOOL</div>
                </div>
            </div>

            <div class="auth-header__meta">
                <div class="auth-header__item">
                    <span class="auth-header__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="1.8"/></svg>
                    </span>
                    <div>
                        <span class="auth-header__label">Jl. Ubi II No. 23</span>
                        <span class="auth-header__subtext">Wage - Taman - Sidoarjo</span>
                    </div>
                </div>
                <div class="auth-header__item">
                    <span class="auth-header__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M7 3h3l1.5 4-2 1.5a15 15 0 0 0 6 6l1.5-2 4 1.5v3c0 1.1-.9 2-2 2C10.7 19 5 13.3 5 6c0-1.1.9-2 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <div>
                        <span class="auth-header__label">(031) 8553790</span>
                        <span class="auth-header__subtext">24/7 Customer Support</span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="auth-main">
        <div class="auth-main__background" aria-hidden="true"></div>
        <div class="auth-main__overlay" aria-hidden="true"></div>
        <div class="auth-shell">
            <div class="auth-card">
                <div class="auth-card__inner">
                    <div class="auth-card__heading">
                        <div class="auth-card__eyebrow">ABSENSI</div>
                        <h1 class="auth-card__title">Rekap Absensi Pegawai TK/SD</h1>
                    </div>

                    <x-auth-session-status :status="session('status')" />

                    <form wire:submit="login" class="auth-form">
                        <div class="auth-field">
                            <label for="username">Nama Pengguna</label>
                            <input wire:model="form.username" id="username" class="auth-input" type="text" name="username" placeholder="Masukkan username" required autofocus autocomplete="username" />
                            @error('form.username')
                                <span class="auth-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="auth-field">
                            <label for="password">Password</label>
                            <div class="auth-password-wrap">
                                <input wire:model="form.password" id="password" class="auth-input" type="{{ $showPassword ? 'text' : 'password' }}" name="password" placeholder="Masukkan password" required autocomplete="current-password" />
                                <button type="button" class="auth-toggle-password" wire:click="togglePassword" aria-label="{{ $showPassword ? 'Sembunyikan password' : 'Tampilkan password' }}">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="2.7" stroke="currentColor" stroke-width="1.8"/></svg>
                                    {{ $showPassword ? 'Sembunyikan' : 'Tampilkan' }}
                                </button>
                            </div>
                            @error('form.password')
                                <span class="auth-error">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="auth-remember">
                            <label class="auth-check" for="remember">
                                <input wire:model="form.remember" id="remember" type="checkbox" name="remember">
                                <span>Ingat saya</span>
                            </label>
                        </div>

                        <button type="submit" class="auth-btn">
                            Masuk
                        </button>
                    </form>

                </div>
            </div>
        </div>
    </main>
</div>
