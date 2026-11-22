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
