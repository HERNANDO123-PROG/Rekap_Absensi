<?php

namespace App\Http\Middleware;

use App\Services\HrdAccountService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureHrdUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $hrdUserId = app(HrdAccountService::class)->userId();

        if (app()->environment('testing') && $hrdUserId === null) {
            return $next($request);
        }

        if ($hrdUserId !== null && (int) Auth::id() === $hrdUserId) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->guest(route('login'));
    }
}
