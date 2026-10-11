<?php

use App\Http\Controllers\LaporanController;
use App\Http\Controllers\DashboardController;
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
    Route::get('/admin/santri/template', [SantriImportController::class, 'template']);
    Route::post('/admin/santri/import', [SantriImportController::class, 'import']);

    Route::post('/reports', [LaporanController::class, 'store']);
    Route::get('/reports/mine', [LaporanController::class, 'mine']);
    Route::patch('/reports/{laporan}/identity', [LaporanController::class, 'toggleIdentity'])->whereNumber('laporan');
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
});

Route::resource('/santri', SantriController::class);
Route::post('/santri/toggle', [SantriController::class, 'toggleSantri'])->name('santri.toggle');
