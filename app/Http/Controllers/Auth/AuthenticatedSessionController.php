<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Đăng nhập / đăng xuất (auth cơ bản kiểu Breeze). */
class AuthenticatedSessionController extends Controller
{
    /** GET /login — form đăng nhập. */
    public function create()
    {
        return view('auth.login');
    }

    /** POST /login — xác thực; sai thì ném ValidationException, đúng thì regenerate session. */
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Email hoặc mật khẩu không đúng.',
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        return redirect()->intended($user->isAdmin() ? '/admin/courses' : '/courses');
    }

    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/courses');
    }
}
