<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\LocationLookupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SchoolController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

// Cascading location dropdowns (used by registration, so public; read-only names and codes)
Route::get('/locations/{type}', LocationLookupController::class)->whereIn('type', ['districts', 'blocks', 'clusters'])
    ->middleware('throttle:120,1')->name('locations');

/* ---------------- Guest ---------------- */
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:register');
    Route::get('/forgot-password', [PasswordController::class, 'forgot'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'sendLink'])->middleware('throttle:5,10')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'reset'])->name('password.update');
});

/* ---------------- Authenticated ---------------- */
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/password/change', [PasswordController::class, 'edit'])->name('password.change');
    Route::put('/password/change', [PasswordController::class, 'update'])->name('password.change.update');

    Route::middleware('password.fresh')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
        Route::post('/profile/mobile', [ProfileController::class, 'requestMobileChange'])->name('profile.mobile');

        // Schools & inspections
        Route::middleware('can:conduct_inspections')->group(function () {
            Route::get('/schools', [SchoolController::class, 'index'])->name('schools.index');
            Route::get('/schools/{school}', [SchoolController::class, 'show'])->name('schools.show');
            Route::post('/schools/{school}/inspections', [InspectionController::class, 'start'])->name('inspections.start');
            Route::get('/my-inspections', [ReportController::class, 'mine'])->name('inspections.mine');
        });

        Route::prefix('inspections/{inspection}')->group(function () {
            Route::get('/step/{step}', [InspectionController::class, 'step'])->whereNumber('step')->name('inspections.step');
            Route::post('/answers', [InspectionController::class, 'saveAnswer'])->name('inspections.answers');
            Route::get('/progress', [InspectionController::class, 'progress'])->name('inspections.progress');
            Route::post('/teachers', [InspectionController::class, 'addAbsentTeacher'])->name('inspections.teachers.store');
            Route::delete('/teachers/{teacher}', [InspectionController::class, 'removeAbsentTeacher'])->name('inspections.teachers.destroy');
            Route::post('/funds', [InspectionController::class, 'addFund'])->name('inspections.funds.store');
            Route::delete('/funds/{fund}', [InspectionController::class, 'removeFund'])->name('inspections.funds.destroy');
            Route::post('/suggestions', [InspectionController::class, 'saveSuggestions'])->name('inspections.suggestions');
            Route::get('/review', [InspectionController::class, 'review'])->name('inspections.review');
            Route::post('/submit', [InspectionController::class, 'submit'])->name('inspections.submit');
            Route::get('/submitted', [InspectionController::class, 'submitted'])->name('inspections.submitted');
            Route::post('/cancel', [InspectionController::class, 'cancel'])->name('inspections.cancel');
            Route::post('/unlock', [InspectionController::class, 'unlock'])->name('inspections.unlock');
            Route::post('/evidence', [EvidenceController::class, 'store'])->middleware('throttle:uploads')->name('evidence.store');
            Route::get('/', [ReportController::class, 'show'])->name('inspections.show');
            Route::get('/pdf', [ReportController::class, 'pdf'])->name('inspections.pdf');
        });

        Route::get('/evidence/{evidence}', [EvidenceController::class, 'show'])->name('evidence.show');
        Route::delete('/evidence/{evidence}', [EvidenceController::class, 'destroy'])->name('evidence.destroy');

        Route::get('/inspections', [ReportController::class, 'area'])->middleware('can:view_reports')->name('inspections.area');
        Route::get('/export/{mode}', [ReportController::class, 'export'])->middleware('can:export_excel')->name('inspections.export');

        /* ---------------- Administration ---------------- */
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::middleware('can:approve_registrations')->group(function () {
                Route::get('/approvals', [Admin\ApprovalController::class, 'index'])->name('approvals.index');
                Route::get('/approvals/{registration}', [Admin\ApprovalController::class, 'show'])->name('approvals.show');
                Route::post('/approvals/{registration}/approve', [Admin\ApprovalController::class, 'approve'])->name('approvals.approve');
                Route::post('/approvals/{registration}/reject', [Admin\ApprovalController::class, 'reject'])->name('approvals.reject');
            });
            Route::get('/users/{user}/id-proof', [Admin\ApprovalController::class, 'idProof'])->name('users.id-proof');

            Route::middleware('can:manage_users')->group(function () {
                Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
                Route::get('/users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
                Route::put('/users/{user}', [Admin\UserController::class, 'update'])->name('users.update');
                Route::patch('/users/{user}/status', [Admin\UserController::class, 'toggleStatus'])->name('users.status');
                Route::post('/users/{user}/transfer', [Admin\UserController::class, 'transfer'])->name('users.transfer');
                Route::post('/users/{user}/reset-password', [Admin\UserController::class, 'resetPassword'])->name('users.reset-password');
                Route::put('/users/{user}/permissions', [Admin\UserController::class, 'permissions'])->name('users.permissions');
            });

            Route::middleware('can:state_admin')->group(function () {
                Route::get('/mobile-requests', [Admin\MobileRequestController::class, 'index'])->name('mobile-requests.index');
                Route::post('/mobile-requests/{mobileRequest}', [Admin\MobileRequestController::class, 'decide'])->name('mobile-requests.decide');
                Route::get('/audit', [Admin\AuditLogController::class, 'index'])->name('audit.index');
            });

            Route::middleware('can:manage_masters')->group(function () {
                Route::get('/locations/{type?}', [Admin\LocationController::class, 'index'])->name('locations.index');
                Route::post('/locations/{type}', [Admin\LocationController::class, 'store'])->name('locations.store');
                Route::put('/locations/{type}/{id}', [Admin\LocationController::class, 'update'])->whereNumber('id')->name('locations.update');
                Route::get('/schools', [Admin\SchoolMasterController::class, 'index'])->name('schools.index');
                Route::get('/schools/create', [Admin\SchoolMasterController::class, 'create'])->name('schools.create');
                Route::post('/schools', [Admin\SchoolMasterController::class, 'store'])->name('schools.store');
                Route::get('/schools/{school}/edit', [Admin\SchoolMasterController::class, 'edit'])->name('schools.edit');
                Route::put('/schools/{school}', [Admin\SchoolMasterController::class, 'update'])->name('schools.update');
                Route::get('/imports', [Admin\ImportController::class, 'index'])->name('imports.index');
                Route::post('/imports', [Admin\ImportController::class, 'store'])->name('imports.store');
                Route::get('/imports/template/{type}', [Admin\ImportController::class, 'template'])->name('imports.template');
                Route::get('/imports/{batch}/errors', [Admin\ImportController::class, 'errors'])->name('imports.errors');
            });
        });
    });
});
