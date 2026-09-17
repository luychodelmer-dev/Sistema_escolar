<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DirectorAdminController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.store');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/asistente', [DashboardController::class, 'assistant'])->name('assistant.dashboard')->middleware('role:asistente,director');
    Route::get('/director', [DashboardController::class, 'director'])->name('director.dashboard')->middleware('role:director');
    Route::get('/padres', [ParentController::class, 'index'])->name('parents.index')->middleware('role:asistente,director');
    Route::get('/padres/crear', [ParentController::class, 'create'])->name('parents.create')->middleware('role:asistente,director');
    Route::post('/padres', [ParentController::class, 'store'])->name('parents.store')->middleware('role:asistente,director');
    Route::get('/padres/{padre}/editar', [ParentController::class, 'edit'])->name('parents.edit')->middleware('role:asistente,director');
    Route::put('/padres/{padre}', [ParentController::class, 'update'])->name('parents.update')->middleware('role:asistente,director');
    Route::get('/matriculas', [EnrollmentController::class, 'index'])->name('enrollments.index')->middleware('role:asistente,director');
    Route::get('/matriculas/crear', [EnrollmentController::class, 'create'])->name('enrollments.create')->middleware('role:asistente,director');
    Route::post('/matriculas', [EnrollmentController::class, 'store'])->name('enrollments.store')->middleware('role:asistente,director');
    Route::get('/pagos', [PaymentController::class, 'index'])->name('payments.index')->middleware('role:asistente,director');
    Route::get('/pagos/crear', [PaymentController::class, 'create'])->name('payments.create')->middleware('role:asistente,director');
    Route::post('/pagos', [PaymentController::class, 'store'])->name('payments.store')->middleware('role:asistente,director');
    Route::get('/director/permisos', [DirectorAdminController::class, 'permissions'])->name('director.permissions')->middleware('role:director');
    Route::post('/director/permisos', [DirectorAdminController::class, 'updatePermissions'])->name('director.permissions.update')->middleware('role:director');
    Route::get('/director/auditoria', [DirectorAdminController::class, 'audit'])->name('director.audit')->middleware('role:director');
    Route::get('/modulos/{module}', [ModuleController::class, 'index'])->name('modules.index')->middleware('role:asistente,director');
});
