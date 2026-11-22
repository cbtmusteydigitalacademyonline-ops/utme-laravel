<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\SubjectController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/subjects', [SubjectController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/exams', [ExamController::class, 'index']);
    Route::post('/exams', [ExamController::class, 'store']);
    Route::get('/exams/{id}', [ExamController::class, 'show']);
    Route::put('/exams/{id}/answer', [ExamController::class, 'answer']);
    Route::post('/exams/{id}/submit', [ExamController::class, 'submit']);

    Route::get('/admin/questions', [AdminController::class, 'questions']);
    Route::post('/admin/questions', [AdminController::class, 'storeQuestion']);
    Route::post('/admin/questions/import', [AdminController::class, 'importQuestions']);
    Route::put('/admin/questions/{id}', [AdminController::class, 'updateQuestion']);
    Route::delete('/admin/questions/{id}', [AdminController::class, 'deleteQuestion']);
    Route::get('/admin/stats', [AdminController::class, 'stats']);
});

Route::get('/courses', [App\Http\Controllers\CourseController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/admin/courses/import', [App\Http\Controllers\CourseAdminController::class, 'import']);
    Route::post('/admin/courses', [App\Http\Controllers\CourseAdminController::class, 'store']);
    Route::put('/admin/courses/{id}', [App\Http\Controllers\CourseAdminController::class, 'update']);
    Route::delete('/admin/courses/{id}', [App\Http\Controllers\CourseAdminController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/exams/{id}/reveal', [App\Http\Controllers\ExamController::class, 'reveal']);
    Route::post('/questions/{id}/report', [App\Http\Controllers\ReportController::class, 'store']);
    Route::get('/admin/reports', [App\Http\Controllers\ReportController::class, 'index']);
    Route::post('/admin/reports/{id}/resolve', [App\Http\Controllers\ReportController::class, 'resolve']);
    Route::delete('/admin/reports/{id}', [App\Http\Controllers\ReportController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/stats/progress', [App\Http\Controllers\StatsController::class, 'progress']);
});

Route::post('/auth/forgot', [App\Http\Controllers\AuthCodeController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/auth/reset', [App\Http\Controllers\AuthCodeController::class, 'reset'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/verify/send', [App\Http\Controllers\AuthCodeController::class, 'sendVerify'])->middleware('throttle:5,1');
    Route::post('/auth/verify', [App\Http\Controllers\AuthCodeController::class, 'verify']);
    Route::get('/leaderboard', [App\Http\Controllers\StatsController::class, 'leaderboard']);
});
