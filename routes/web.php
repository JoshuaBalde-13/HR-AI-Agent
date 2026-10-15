<?php

use App\Http\Controllers\ApplicantAuthController;
use App\Http\Controllers\ApplicantDashboardController;
use App\Http\Controllers\EmployeeDashboardController;
use App\Http\Controllers\HRApplicantController;
use App\Http\Controllers\HRDashboardController;
use App\Http\Controllers\RobotAskController;
use App\Http\Controllers\RobotContextController;
use App\Http\Controllers\RobotEventController;
use App\Http\Controllers\StaffAuthController;
use App\Http\Middleware\BindRobotIdentity;
use App\Http\Middleware\EnsureOwnApplicant;
use App\Http\Middleware\EnsureStaffRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Applicant Authentication
|--------------------------------------------------------------------------
*/

Route::get('/register', [
    ApplicantAuthController::class,
    'showRegister',
])->name('register');

Route::post('/register', [
    ApplicantAuthController::class,
    'register',
])->middleware('throttle:10,1')->name('register.submit');

Route::get('/login', [
    ApplicantAuthController::class,
    'showLogin',
])->name('login');

Route::post('/login', [
    ApplicantAuthController::class,
    'login',
])->middleware('throttle:6,1')->name('login.submit');

Route::post('/logout', [
    ApplicantAuthController::class,
    'logout',
])->name('logout');

/*
|--------------------------------------------------------------------------
| Staff Authentication
|--------------------------------------------------------------------------
*/

Route::get('/staff/login', [
    StaffAuthController::class,
    'showLogin',
])->name('staff.login');

Route::post('/staff/login', [
    StaffAuthController::class,
    'login',
])->middleware('throttle:6,1')->name('staff.login.submit');

Route::post('/staff/logout', [
    StaffAuthController::class,
    'logout',
])->middleware(
    EnsureStaffRole::class . ':admin,hr,security'
)->name('staff.logout');

/*
|--------------------------------------------------------------------------
| Applicant Home
|--------------------------------------------------------------------------
*/

Route::get('/applicant', function () {
    $applicant = Auth::guard('applicant')->user();

    return $applicant
        ? redirect()->route('applicant.dashboard', $applicant)
        : redirect()->route('login');
})->name('applicant.home');

/*
|--------------------------------------------------------------------------
| Applicant Pages
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:applicant',
    EnsureOwnApplicant::class,
])->group(function () {
    Route::get('/applicant/{applicant}/dashboard', [
        ApplicantDashboardController::class,
        'index',
    ])->name('applicant.dashboard');

    Route::post('/applicant/{applicant}/start', [
        ApplicantDashboardController::class,
        'start',
    ])->name('applicant.start');

    Route::post('/applicant/{applicant}/step', [
        ApplicantDashboardController::class,
        'saveStep',
    ])->name('applicant.step');

    Route::post('/applicant/{applicant}/submit', [
        ApplicantDashboardController::class,
        'submit',
    ])->name('applicant.submit');
});

/*
|--------------------------------------------------------------------------
| HR Pages: System Administrator and HR Personnel
|--------------------------------------------------------------------------
*/

Route::middleware(
    EnsureStaffRole::class . ':admin,hr'
)->group(function () {
    Route::get('/hr/dashboard', [
        HRDashboardController::class,
        'index',
    ])->name('hr.dashboard');

    Route::get('/hr/compliance', [
        HRDashboardController::class,
        'compliance',
    ])->name('hr.compliance');

    Route::get('/hr/applicants', [
        HRApplicantController::class,
        'index',
    ])->name('hr.applicants');

    Route::get('/hr/applicants/{application}', [
        HRApplicantController::class,
        'show',
    ])->name('hr.applicants.show');

    Route::post('/hr/applicants/{application}/review', [
        HRApplicantController::class,
        'review',
    ])->name('hr.applicants.review');
});

/*
|--------------------------------------------------------------------------
| Employee Pages: Security Personnel
|--------------------------------------------------------------------------
*/

Route::middleware(
    EnsureStaffRole::class . ':security'
)->group(function () {
    Route::get('/employee/dashboard', [
        EmployeeDashboardController::class,
        'index',
    ])->name('employee.dashboard');

    Route::post('/employee/documents/{document}/renew', [
        EmployeeDashboardController::class,
        'renew',
    ])->name('employee.documents.renew');
});

/*
|--------------------------------------------------------------------------
| Robot / AI Agent
|--------------------------------------------------------------------------
|
| The middleware derives role and employee identity from the authenticated
| user. The role and employee_name sent by the browser are not trusted.
|
*/

Route::middleware(BindRobotIdentity::class)->group(function () {
    Route::get('/robot/context', [
        RobotContextController::class,
        'show',
    ])->name('robot.context');

    Route::post('/robot/ask', [
        RobotAskController::class,
        'ask',
    ])->middleware('throttle:30,1')->name('robot.ask');

    Route::post('/robot/events/{robotEvent}/read', [
        RobotEventController::class,
        'markRead',
    ])->name('robot.events.read');
});

/*
|--------------------------------------------------------------------------
| Default
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return redirect()->route('hr.dashboard');
});