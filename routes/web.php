<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\LocationLookupController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CoverageReportController;
use App\Http\Controllers\NetworkIssueController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\RatingReportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\TodaySchoolsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

// Cascading location dropdowns (used by registration, so public; read-only names and codes)
Route::get('/locations/{type}', LocationLookupController::class)->whereIn('type', ['districts', 'blocks', 'clusters'])
    ->middleware('throttle:120,1')->name('locations');

/* ---------------- Guest ---------------- */
// 25-11-2026, one user per browser: the sign-in page is open while signed in too; it then shows who is signed in
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
Route::post('/login/switch', [LoginController::class, 'switchUser'])->middleware('auth')->name('login.switch');
Route::middleware('guest')->group(function () {
    Route::get('/login/math-captcha', [LoginController::class, 'mathCaptcha'])->middleware('throttle:60,1')->name('login.math-captcha');
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:register');
    Route::get('/register/kept/{field}', [RegisterController::class, 'keptUpload'])->whereIn('field', ['photo', 'id_proof'])->name('register.kept');
    Route::get('/forgot-password', [PasswordController::class, 'forgot'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'sendLink'])->middleware('throttle:5,10')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'reset'])->name('password.update');
});

/* ---------------- Authenticated ---------------- */
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    // "Stay signed in" from the idle warning: any authenticated request refreshes the 20-minute session
    Route::get('/session/ping', fn () => response()->noContent())->middleware('throttle:30,1')->name('session.ping');
    Route::get('/password/change', [PasswordController::class, 'edit'])->name('password.change');
    Route::put('/password/change', [PasswordController::class, 'update'])->name('password.change.update');

    Route::middleware('password.fresh')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
        Route::post('/profile/mobile', [ProfileController::class, 'requestMobileChange'])->name('profile.mobile');
        Route::post('/profile/photo', [ProfileController::class, 'updatePhoto'])->name('profile.photo');
        // V14: school name / UDISE auto-suggestions for every school search box (jurisdiction-scoped)
        Route::get('/school-suggest', [SchoolController::class, 'suggest'])->middleware('throttle:120,1')->name('schools.suggest');

        // Schools & inspections
        Route::middleware('can:conduct_inspections')->group(function () {
            Route::get('/schools', [SchoolController::class, 'index'])->name('schools.index');
            Route::get('/my-schools', [SchoolController::class, 'mine'])->name('schools.mine'); // 23-11-2026: own jurisdiction list
            Route::get('/my-schools/export', [SchoolController::class, 'mineExport'])->middleware(['can:export_excel', 'throttle:6,1'])->name('schools.mine.export');
            Route::get('/my-schools/export-pdf', [SchoolController::class, 'minePdf'])->middleware(['can:export_excel', 'throttle:6,1'])->name('schools.mine.export-pdf');
            Route::get('/schools/{school}', [SchoolController::class, 'show'])->name('schools.show');
            Route::get('/schools/{school}/blank-form', [SchoolController::class, 'blankForm'])->middleware('throttle:30,1')->name('schools.blank-form'); // V11
            Route::post('/schools/{school}/inspections', [InspectionController::class, 'start'])->name('inspections.start');
            Route::get('/my-inspections', [ReportController::class, 'mine'])->name('inspections.mine');
            Route::get('/subjects/suggest', [InspectionController::class, 'subjectSuggestions'])->middleware('throttle:120,1')->name('subjects.suggest');
        });

        Route::prefix('inspections/{inspection}')->group(function () {
            Route::get('/step/{step}', [InspectionController::class, 'step'])->whereNumber('step')->name('inspections.step');
            Route::post('/answers', [InspectionController::class, 'saveAnswer'])->name('inspections.answers');
            Route::get('/progress', [InspectionController::class, 'progress'])->name('inspections.progress');
            Route::get('/teacher-names', [InspectionController::class, 'teacherNames'])->middleware('throttle:120,1')->name('inspections.teacher-names'); // V11
            Route::post('/teachers', [InspectionController::class, 'addAbsentTeacher'])->name('inspections.teachers.store');
            Route::put('/teachers/{teacher}', [InspectionController::class, 'updateAbsentTeacher'])->name('inspections.teachers.update');
            Route::delete('/teachers/{teacher}', [InspectionController::class, 'removeAbsentTeacher'])->name('inspections.teachers.destroy');
            Route::post('/classrooms', [InspectionController::class, 'addClassroom'])->name('inspections.classrooms.store');
            Route::put('/classrooms/{classroom}', [InspectionController::class, 'updateClassroom'])->name('inspections.classrooms.update');
            Route::delete('/classrooms/{classroom}', [InspectionController::class, 'removeClassroom'])->name('inspections.classrooms.destroy');
            Route::post('/funds',[InspectionController::class, 'addFund'])->name('inspections.funds.store');
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
        // Inspection Coverage Report (District / Block / Cluster wise) and its configurable coverage period
        Route::prefix('reports/coverage')->name('reports.coverage')->group(function () {
            Route::get('/', [CoverageReportController::class, 'index'])->middleware('can:view_reports')->name('');
            Route::get('/export', [CoverageReportController::class, 'export'])->middleware(['can:view_reports', 'can:export_excel'])->name('.export');
            Route::get('/export-pdf', [CoverageReportController::class, 'exportPdf'])->middleware(['can:view_reports', 'can:export_excel', 'throttle:6,1'])->name('.export-pdf');
            Route::get('/settings', [CoverageReportController::class, 'settings'])->middleware('can:configure_coverage')->name('.settings');
            Route::put('/settings', [CoverageReportController::class, 'updateSettings'])->middleware('can:configure_coverage')->name('.settings.update');
        });
        // Modification V11 item 2: network / internet problems reported at schools
        Route::get('/reports/network-issues', [NetworkIssueController::class, 'index'])->middleware('can:view_reports')->name('reports.network');
        Route::get('/reports/network-issues/export-pdf', [NetworkIssueController::class, 'exportPdf'])
            ->middleware(['can:view_reports', 'can:export_excel', 'throttle:6,1'])->name('reports.network.export-pdf'); // 25-11-2026
        // V6 Module 10: rating report
        Route::middleware('can:view_reports')->prefix('reports/ratings')->name('reports.ratings')->group(function () {
            Route::get('/', [RatingReportController::class, 'index'])->name('');
            Route::get('/export', [RatingReportController::class, 'export'])->name('.export');
            Route::get('/export-pdf', [RatingReportController::class, 'exportPdf'])->middleware(['can:export_excel', 'throttle:6,1'])->name('.export-pdf');
        });
        // 25-11-2026: the Inspections Excel download is gone (My Inspections in V14, My Area now); the PDF stays
        Route::get('/export-pdf/{mode}', [ReportController::class, 'exportPdf'])->middleware(['can:export_excel', 'throttle:6,1'])->name('inspections.export-pdf');

        /* ---------------- Map View (New Module 1) ---------------- */
        Route::middleware('can:view_reports')->prefix('map')->name('map.')->group(function () {
            Route::get('/', [MapController::class, 'index'])->name('index');
            Route::get('/areas/{level}', [MapController::class, 'areas'])->middleware('throttle:120,1')->name('areas');
            Route::get('/schools', [MapController::class, 'schools'])->middleware('throttle:120,1')->name('schools');
        });

        /* ---------------- V14 (25-11-2026): holiday calendar ---------------- */
        Route::prefix('holidays')->name('holidays.')->group(function () {
            Route::get('/', [HolidayController::class, 'index'])->name('index');
            Route::middleware('can:manage_holidays')->group(function () {
                Route::post('/', [HolidayController::class, 'store'])->name('store');
                Route::get('/template', [HolidayController::class, 'template'])->name('template');
                Route::post('/import', [HolidayController::class, 'import'])->middleware('throttle:10,1')->name('import');
                Route::put('/{holiday}', [HolidayController::class, 'update'])->name('update');
                Route::delete('/{holiday}', [HolidayController::class, 'destroy'])->name('destroy');
            });
        });

        /* ---------------- V14 item 5: Today's schools (daily school display) ---------------- */
        Route::prefix('today')->name('today.')->group(function () {
            Route::get('/', [TodaySchoolsController::class, 'index'])->middleware('can:conduct_inspections')->name('index');
            Route::post('/rows/{row}/skip', [TodaySchoolsController::class, 'skip'])->name('skip');
            Route::delete('/rows/{row}', [TodaySchoolsController::class, 'remove'])->name('remove');
            Route::get('/assign', [TodaySchoolsController::class, 'assign'])->middleware('can:view_reports')->name('assign');
            Route::post('/assign', [TodaySchoolsController::class, 'storeAssign'])->middleware(['can:view_reports', 'throttle:30,1'])->name('assign.store');
            Route::get('/settings', [TodaySchoolsController::class, 'settings'])->middleware('can:manage_holidays')->name('settings');
            Route::put('/settings', [TodaySchoolsController::class, 'updateSettings'])->middleware('can:manage_holidays')->name('settings.update');
        });

        /* ---------------- 26-11-2026 performance: large downloads made in the background ---------------- */
        Route::prefix('downloads')->name('downloads.')->group(function () {
            Route::get('/', [\App\Http\Controllers\DownloadController::class, 'index'])->name('index');
            Route::get('/status', [\App\Http\Controllers\DownloadController::class, 'status'])->middleware('throttle:120,1')->name('status');
            Route::get('/{exportJob}/file', [\App\Http\Controllers\DownloadController::class, 'file'])->name('file');
            Route::delete('/{exportJob}', [\App\Http\Controllers\DownloadController::class, 'destroy'])->name('destroy');
        });

        /* ---------------- V14 item 1: Instruction / Notification / Order letters ---------------- */
        Route::prefix('notices')->name('notices.')->group(function () {
            Route::get('/', [NoticeController::class, 'index'])->name('index');
            Route::get('/unread-count', [NoticeController::class, 'unreadCount'])->middleware('throttle:120,1')->name('unread-count'); // 25-11-2026
            Route::middleware('can:issue_notices')->group(function () {
                Route::get('/create', [NoticeController::class, 'create'])->name('create');
                Route::post('/', [NoticeController::class, 'store'])->middleware('throttle:20,1')->name('store');
                Route::post('/{notice}/withdraw', [NoticeController::class, 'withdraw'])->name('withdraw');
                Route::post('/{notice}/home', [NoticeController::class, 'home'])->name('home'); // 25-11-2026: homepage Latest Notice
                Route::post('/{notice}/pin', [NoticeController::class, 'pin'])->name('pin'); // 25-11-2026: Latest Notification
            });
            Route::get('/{notice}', [NoticeController::class, 'show'])->name('show');
            Route::post('/{notice}/acknowledge', [NoticeController::class, 'acknowledge'])->name('acknowledge');
            Route::get('/{notice}/attachment', [NoticeController::class, 'attachment'])->name('attachment');
        });

        /* ---------------- Inspection schedules (New Module 2) ---------------- */
        Route::middleware('can:view_reports')->prefix('schedules')->name('schedules.')->group(function () {
            Route::get('/', [ScheduleController::class, 'index'])->name('index');
            Route::get('/plan', [ScheduleController::class, 'plan'])->name('plan');
            Route::get('/plan/schools', [ScheduleController::class, 'schools'])->middleware('throttle:120,1')->name('schools');
            Route::post('/', [ScheduleController::class, 'store'])->middleware('throttle:60,1')->name('store');
            Route::get('/approvals', [ScheduleController::class, 'approvals'])->name('approvals');
            Route::post('/items/{item}/skip', [ScheduleController::class, 'skip'])->name('skip');
            Route::get('/{schedule}', [ScheduleController::class, 'show'])->name('show');
            Route::get('/{schedule}/order', [ScheduleController::class, 'attachment'])->name('attachment'); // V14 item 4
            Route::post('/{schedule}/approve', [ScheduleController::class, 'approve'])->name('approve');
            Route::post('/{schedule}/reject', [ScheduleController::class, 'reject'])->name('reject');
            Route::post('/{schedule}/cancel', [ScheduleController::class, 'cancel'])->name('cancel');
            Route::post('/{schedule}/reassign', [ScheduleController::class, 'reassign'])->name('reassign');
        });

        /* ---------------- Administration ---------------- */
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::middleware('can:approve_registrations')->group(function () {
                Route::get('/approvals', [Admin\ApprovalController::class, 'index'])->name('approvals.index');
                Route::get('/approvals/{registration}', [Admin\ApprovalController::class, 'show'])->name('approvals.show');
                Route::post('/approvals/{registration}/approve', [Admin\ApprovalController::class, 'approve'])->name('approvals.approve');
                Route::post('/approvals/{registration}/reject', [Admin\ApprovalController::class, 'reject'])->name('approvals.reject');
            });
            Route::get('/users/{user}/id-proof', [Admin\ApprovalController::class, 'idProof'])->name('users.id-proof');
            Route::get('/users/{user}/photo', [Admin\ApprovalController::class, 'photo'])->name('users.photo');

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
                // Modification V13 item 4: audit / cache clean-up
                Route::post('/audit/purge', [Admin\AuditLogController::class, 'purge'])->middleware('throttle:5,1')->name('audit.purge');
                Route::post('/audit/cache', [Admin\AuditLogController::class, 'clearCache'])->middleware('throttle:5,1')->name('audit.cache');
                Route::get('/audit/archive/{file}', [Admin\AuditLogController::class, 'archive'])->name('audit.archive');
                // 25-11-2026: clean the test inspection data (refused in production)
                Route::get('/test-data', [Admin\TestDataController::class, 'index'])->name('test-data.index');
                Route::post('/test-data/clean', [Admin\TestDataController::class, 'clean'])->middleware('throttle:3,1')->name('test-data.clean');
                // V6 Module 6: login user monitoring
                Route::get('/login-report', [Admin\LoginReportController::class, 'index'])->name('login-report.index');
                Route::get('/login-report/export', [Admin\LoginReportController::class, 'export'])->name('login-report.export');
                // Modification V8 item 7: daily login report with the current login status
                Route::get('/login-report/daily', [Admin\LoginReportController::class, 'daily'])->name('login-report.daily');
                Route::get('/login-report/daily/export', [Admin\LoginReportController::class, 'dailyExport'])->name('login-report.daily.export');
                Route::get('/login-report/users/{user}', [Admin\LoginReportController::class, 'user'])->name('login-report.user');
            });

            Route::middleware('can:manage_masters')->group(function () {
                Route::get('/locations/{type?}', [Admin\LocationController::class, 'index'])->name('locations.index');
                Route::get('/locations/{type}/export/{format}', [Admin\LocationController::class, 'export'])->whereIn('format', ['excel', 'pdf'])->middleware('throttle:10,1')->name('locations.export');
                Route::post('/locations/{type}', [Admin\LocationController::class, 'store'])->name('locations.store');
                Route::put('/locations/{type}/{id}', [Admin\LocationController::class, 'update'])->name('locations.update');
                Route::delete('/locations/{type}/{id}', [Admin\LocationController::class, 'destroy'])->name('locations.destroy'); // V13
                Route::get('/schools', [Admin\SchoolMasterController::class, 'index'])->name('schools.index');
                Route::get('/schools/create', [Admin\SchoolMasterController::class, 'create'])->name('schools.create');
                Route::post('/schools', [Admin\SchoolMasterController::class, 'store'])->name('schools.store');
                Route::get('/schools/{school}/edit', [Admin\SchoolMasterController::class, 'edit'])->name('schools.edit');
                Route::put('/schools/{school}', [Admin\SchoolMasterController::class, 'update'])->name('schools.update');
                // Modification V13: list export, delete an unused school, remove a district's unused schools
                Route::get('/schools-export', [Admin\SchoolMasterController::class, 'export'])->name('schools.export');
                Route::get('/schools-export-pdf', [Admin\SchoolMasterController::class, 'exportPdf'])->middleware('throttle:10,1')->name('schools.export-pdf');
                Route::delete('/schools/{school}', [Admin\SchoolMasterController::class, 'destroy'])->name('schools.destroy');
                Route::post('/schools-remove-district', [Admin\SchoolMasterController::class, 'removeDistrict'])->name('schools.remove-district');
                // AC / PC Master (Assembly and Parliamentary constituencies)
                Route::get('/constituencies', [Admin\ConstituencyController::class, 'index'])->name('constituencies.index');
                Route::post('/constituencies/{type}', [Admin\ConstituencyController::class, 'store'])->whereIn('type', ['ac', 'pc'])->name('constituencies.store');
                Route::put('/constituencies/ac/{ac}', [Admin\ConstituencyController::class, 'updateAc'])->name('constituencies.ac.update');
                Route::put('/constituencies/pc/{pc}', [Admin\ConstituencyController::class, 'updatePc'])->name('constituencies.pc.update');
                Route::delete('/constituencies/ac/{ac}', [Admin\ConstituencyController::class, 'destroyAc'])->name('constituencies.ac.destroy'); // V13
                Route::delete('/constituencies/pc/{pc}', [Admin\ConstituencyController::class, 'destroyPc'])->name('constituencies.pc.destroy');
                // FLN TLM master (classroom observation, step 4)
                Route::get('/tlm-items', [Admin\TlmItemController::class, 'index'])->name('tlm.index');
                Route::post('/tlm-items', [Admin\TlmItemController::class, 'store'])->name('tlm.store');
                Route::put('/tlm-items/{tlmItem}', [Admin\TlmItemController::class, 'update'])->name('tlm.update');
                Route::delete('/tlm-items/{tlmItem}', [Admin\TlmItemController::class, 'destroy'])->name('tlm.destroy'); // V13
                // 25-11-2026: Notices & Orders letter types
                Route::get('/notice-types', [Admin\NoticeTypeController::class, 'index'])->name('notice-types.index');
                Route::post('/notice-types', [Admin\NoticeTypeController::class, 'store'])->name('notice-types.store');
                Route::put('/notice-types/{noticeType}', [Admin\NoticeTypeController::class, 'update'])->name('notice-types.update');
                Route::patch('/notice-types/{noticeType}/status', [Admin\NoticeTypeController::class, 'status'])->name('notice-types.status');
                // Rating Settings (New Module 3)
                Route::get('/rating', [Admin\RatingController::class, 'index'])->name('rating.index');
                Route::post('/rating', [Admin\RatingController::class, 'update'])->name('rating.update');
                Route::post('/rating/recalculate', [Admin\RatingController::class, 'recalculate'])->middleware('throttle:5,1')->name('rating.recalculate');

                Route::get('/imports', [Admin\ImportController::class, 'index'])->name('imports.index');
                Route::post('/imports', [Admin\ImportController::class, 'store'])->name('imports.store');
                Route::get('/imports/template/{type}', [Admin\ImportController::class, 'template'])->name('imports.template');
                // District-wise School Master upload: check (dry run) then confirm; district school counts
                Route::post('/imports/preview', [Admin\ImportController::class, 'preview'])->name('imports.preview');
                Route::post('/imports/confirm', [Admin\ImportController::class, 'confirm'])->name('imports.confirm');
                Route::post('/imports/cancel', [Admin\ImportController::class, 'cancel'])->name('imports.cancel');
                Route::get('/imports/district-blocks', [Admin\ImportController::class, 'district'])->name('imports.district');
                Route::get('/imports/report/export', [Admin\ImportController::class, 'report'])->name('imports.report');
                Route::get('/imports/{batch}/errors', [Admin\ImportController::class, 'errors'])->name('imports.errors');
            });
        });
    });
});
