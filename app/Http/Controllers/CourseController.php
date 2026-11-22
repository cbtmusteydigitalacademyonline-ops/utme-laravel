<?php

namespace App\Http\Controllers;

use App\Models\Course;

class CourseController extends Controller
{
    public function index()
    {
        return Course::orderBy('name')->get(['id', 'name', 'subjects', 'notes', 'source']);
    }
}
