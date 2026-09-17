<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'correo' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('correo', $credentials['correo'])
            ->where('estado', true)
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password_hash)) {
            return back()->withErrors(['correo' => 'Correo o contraseña incorrectos.'])->withInput();
        }

        Auth::login($user);
        $request->session()->regenerate();
        DB::table('auditoria_movimientos')->insert([
            'usuario_id' => $user->id,
            'accion' => 'inicio_sesion',
            'detalles' => 'Inicio de sesión correcto.',
            'ip_origen' => $request->ip(),
            'creado_en' => now(),
        ]);

        return Auth::user()->rol === 'director'
            ? redirect()->route('director.dashboard')
            : redirect()->route('assistant.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
