<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SubjectController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::table('subjects as s')
            ->leftJoin('questions as q', 'q.subject_id', '=', 's.id')
            ->groupBy('s.id', 's.name')
            ->orderBy('s.name')
            ->get(['s.id', 's.name', DB::raw('COUNT(q.id) as questions')]);

        return response()->json($rows);
    }
}
