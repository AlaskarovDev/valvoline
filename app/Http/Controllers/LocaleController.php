<?php

namespace App\Http\Controllers;

use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(Locale::isSupported($locale), 404);
        $request->session()->put('locale', $locale);
        Cookie::queue(Cookie::forever(Locale::COOKIE, $locale));

        $back = url()->previous();

        return redirect()->to($back && $back !== $request->fullUrl() ? $back : route('dashboard'));
    }
}
