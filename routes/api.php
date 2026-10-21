<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LaporanController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SantriController;
use App\Http\Controllers\SantriImportController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [UserController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/password', [UserController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [UserController::class, 'logout']);
});

// test auto deploy
Route::middleware(['auth:sanctum', EnsurePasswordChanged::class])->group(function () {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/admin/accounts', [DashboardController::class, 'account']);
    Route::post('/admin/accounts', [DashboardController::class, 'storeAccount']);
    Route::patch('/admin/accounts/{user}/status', [DashboardController::class, 'isActiveAccount'])->whereNumber('user');
    Route::get('/admin/santri/export', [SantriImportController::class, 'export']);
    Route::get('/admin/santri/template', [SantriImportController::class, 'template']);
    Route::post('/admin/santri/import', [SantriImportController::class, 'import']);

    Route::post('/reports', [LaporanController::class, 'store']);
    Route::get('/reports/mine', [LaporanController::class, 'mine']);
    Route::patch('/reports/{laporan}/identity', [LaporanController::class, 'toggleIdentity'])->whereNumber('laporan');
    Route::post('/reports/{laporan}/identity-request', [LaporanController::class, 'identityRequest'])->whereNumber('laporan');
    Route::patch('/reports/{laporan}/identity-request', [LaporanController::class, 'respondToIdentityRequest'])->whereNumber('laporan');
    Route::get('/reports', [LaporanController::class, 'index']);
    Route::get('/reports/{laporan}', [LaporanController::class, 'show'])->whereNumber('laporan');
    Route::patch('/reports/{laporan}/status', [LaporanController::class, 'updateStatus'])->whereNumber('laporan');
    Route::post('/reports/{laporan}/escalate', [LaporanController::class, 'escalate'])->whereNumber('laporan');
    Route::get('/reports/{laporan}/messages', [LaporanController::class, 'messages'])->whereNumber('laporan');
    Route::post('/reports/{laporan}/messages', [LaporanController::class, 'sendMessage'])->whereNumber('laporan');
    Route::post('/reports/{laporan}/notes', [LaporanController::class, 'addNote'])->whereNumber('laporan');

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::get('/notifications/summary', [NotificationController::class, 'summary'])
        ->middleware('throttle:120,1');
});

Route::resource('/santri', SantriController::class);
Route::post('/santri/toggle', [SantriController::class, 'toggleSantri'])->name('santri.toggle');
