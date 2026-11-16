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

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    public function togglePassword(): void
    {
        $this->showPassword = ! $this->showPassword;
    }
}; ?>

<div class="auth-page" style="--auth-background-image: url('{{ asset('images/logo blur.jpeg') }}')">
    <header class="auth-header">
        <div class="auth-header__inner">
            <div class="auth-header__brand">
                <img src="{{ asset('images/logo.png') }}" alt="Logo Khazanah Ilmu" class="auth-header__institution-logo">
                <div class="auth-header__brand-copy">
                    <div class="auth-header__title">KHAZANAH ILMU</div>
                    <div class="auth-header__subtitle">ISLAMIC FULLDAY SCHOOL</div>
                </div>
                <span class="auth-header__divider" aria-hidden="true"></span>
                <img
                    src="{{ asset('images/the_best.png') }}"
                    srcset="{{ asset('images/the_best.png') }} 1x, {{ asset('images/the_best@2x.png') }} 2x"
                    alt="The Best Islamic School"
                    class="slogan-img"
                >
            </div>

            <div class="auth-header__meta">
                <div class="auth-header__item">
                    <span class="auth-header__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="1.8"/></svg>
                    </span>
                    <div class="auth-header__contact-copy">
                        <span class="auth-header__label">Jl. Ubi II No. 23</span>
                        <span class="auth-header__subtext">Wage - Taman - Sidoarjo</span>
                    </div>
                </div>
                <div class="auth-header__item">
                    <span class="auth-header__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M7 3h3l1.5 4-2 1.5a15 15 0 0 0 6 6l1.5-2 4 1.5v3c0 1.1-.9 2-2 2C10.7 19 5 13.3 5 6c0-1.1.9-2 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <div class="auth-header__contact-copy">
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
                        <img src="{{ asset('images/logo.png') }}" alt="" class="auth-card__logo">
                        <div class="auth-card__eyebrow">ABSENSI</div>
                        <h1 class="auth-card__title">Rekap Absensi Pegawai TK/SD</h1>
                    </div>

                    <x-auth-session-status :status="session('status')" />

                    <form wire:submit="login" class="auth-form">
                        <div class="auth-field">
                            <label for="username">Nama Pengguna</label>
                            <div class="auth-input-wrap">
                                <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.8"/><path d="M5 20c.5-3.4 3.2-5.3 7-5.3s6.5 1.9 7 5.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                <input wire:model="form.username" id="username" class="auth-input" type="text" name="username" placeholder="Masukkan username" required autofocus autocomplete="username" @error('form.username') aria-invalid="true" aria-describedby="username-error" @enderror />
                            </div>
                            @error('form.username')
                                <span class="auth-error" id="username-error">
                                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4.5m0 3h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                    {{ $message }}
                                </span>
                            @enderror
                        </div>

                        <div class="auth-field">
                            <label for="password">Password</label>
                            <div class="auth-password-wrap">
                                <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="15" r="1" fill="currentColor"/></svg>
                                <input wire:model="form.password" id="password" class="auth-input" type="{{ $showPassword ? 'text' : 'password' }}" name="password" placeholder="Masukkan password" required autocomplete="current-password" @error('form.password') aria-invalid="true" aria-describedby="password-error" @enderror />
                                <button type="button" class="auth-toggle-password" wire:click="togglePassword" aria-label="{{ $showPassword ? 'Sembunyikan password' : 'Tampilkan password' }}" aria-pressed="{{ $showPassword ? 'true' : 'false' }}">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="2.7" stroke="currentColor" stroke-width="1.8"/></svg>
                                    <span>{{ $showPassword ? 'Sembunyikan' : 'Tampilkan' }}</span>
                                </button>
                            </div>
                            @error('form.password')
                                <span class="auth-error" id="password-error">
                                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4.5m0 3h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                    {{ $message }}
                                </span>
                            @enderror
                        </div>

                        <div class="auth-remember">
                            <label class="auth-check" for="remember">
                                <input wire:model="form.remember" id="remember" type="checkbox" name="remember">
                                <span>Ingat saya</span>
                            </label>
                        </div>

                        <button type="submit" class="auth-btn" wire:loading.attr="disabled" wire:target="login">
                            <span wire:loading.remove wire:target="login">Masuk</span>
                            <span class="auth-btn__loading" wire:loading.flex wire:target="login" role="status" aria-live="polite">
                                <span class="auth-spinner" aria-hidden="true"></span>
                                Memproses...
                            </span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <footer class="auth-footer">
        <p>© 2026 Khazanah Ilmu Islamic Fullday School</p>
    </footer>
</div>
