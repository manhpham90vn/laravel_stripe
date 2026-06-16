<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/** Đăng ký tài khoản mới (auth cơ bản kiểu Breeze). Mặc định role = 'user'. */
class RegisteredUserController extends Controller
{
    /** GET /register — form đăng ký. */
    public function create()
    {
        return view('auth.register');
    }

    /** POST /register — tạo user (role 'user'), hash mật khẩu, đăng nhập luôn. */
    public function store(RegisterRequest $request)
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => User::ROLE_USER,
        ]);

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/courses');
    }
}
