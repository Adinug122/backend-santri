<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeactivationRequestController;
use App\Http\Controllers\LaporanController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SantriController;
use App\Http\Controllers\SantriImportController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [UserController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/password', [UserController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [UserController::class, 'me']);
    Route::post('/auth/logout', [UserController::class, 'logout']);
    Route::post('/chat', [ChatController::class, 'send'])
        ->middleware('throttle:20,1');
});

// test auto deploy
Route::middleware(['auth:sanctum', EnsurePasswordChanged::class])->group(function () {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/admin/accounts', [DashboardController::class, 'account']);
    Route::post('/admin/accounts', [DashboardController::class, 'storeAccount']);
    Route::patch('/admin/accounts/{user}/status', [DashboardController::class, 'isActiveAccount'])->whereNumber('user');
    Route::post('/admin/accounts/{user}/deactivation-requests', [DeactivationRequestController::class, 'store'])->whereNumber('user');
    Route::get('/admin/deactivation-requests', [DeactivationRequestController::class, 'index']);
    Route::patch('/admin/deactivation-requests/{deactivationRequest}', [DeactivationRequestController::class, 'update'])->whereNumber('deactivationRequest');
    Route::get('/admin/audit-logs', [AuditLogController::class, 'index']);
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

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->middleware('throttle:120,1');
    Route::get('/notifications/summary', [NotificationController::class, 'summary'])
        ->middleware('throttle:120,1');
});

Route::resource('/santri', SantriController::class);
Route::post('/santri/toggle', [SantriController::class, 'toggleSantri'])->name('santri.toggle');
