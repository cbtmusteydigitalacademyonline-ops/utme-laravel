<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    private function deny(Request $request): ?JsonResponse
    {
        return $request->user()->role === 'admin' ? null : response()->json(['error' => 'Admins only'], 403);
    }

    // POST /api/questions/{id}/report   { message }
    public function store(Request $request, $id): JsonResponse
    {
        $message = trim((string) $request->input('message'));
        if (mb_strlen($message) < 3 || mb_strlen($message) > 500) {
            return response()->json(['error' => 'Please describe the problem in 3 to 500 characters'], 400);
        }

        // only students who actually met this question in one of their exams can report it
        $seen = DB::table('exam_questions as eq')
            ->join('exams as e', 'e.id', '=', 'eq.exam_id')
            ->where('e.user_id', $request->user()->id)
            ->where('eq.question_id', $id)
            ->exists();
        if (!$seen) return response()->json(['error' => 'You can only report questions from your own exams'], 403);

        $already = DB::table('question_reports')
            ->where('user_id', $request->user()->id)->where('question_id', $id)->where('status', 'open')->exists();
        if ($already) return response()->json(['error' => 'You already reported this question. Thank you!'], 409);

        DB::table('question_reports')->insert([
            'question_id' => (int) $id,
            'user_id' => $request->user()->id,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    // GET /api/admin/reports?status=open|resolved|all&page=1
    public function index(Request $request): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        $per = 20;
        $page = max(1, (int) $request->query('page', 1));
        $status = (string) $request->query('status', 'open');

        $base = DB::table('question_reports as r')
            ->join('questions as q', 'q.id', '=', 'r.question_id')
            ->join('subjects as s', 's.id', '=', 'q.subject_id')
            ->join('users as u', 'u.id', '=', 'r.user_id');
        if (in_array($status, ['open', 'resolved'], true)) $base->where('r.status', $status);

        $total = (clone $base)->count();
        $rows = $base->orderByDesc('r.id')->forPage($page, $per)
            ->get(['r.id', 'r.message', 'r.status', 'r.created_at', 'q.id as question_id', 'q.text as question', 's.name as subject', 'u.name as reporter'])
            ->map(fn ($x) => [
                'id' => $x->id, 'message' => $x->message, 'status' => $x->status, 'createdAt' => $x->created_at,
                'questionId' => $x->question_id, 'question' => $x->question, 'subject' => $x->subject, 'reporter' => $x->reporter,
            ]);

        return response()->json([
            'data' => $rows->values(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $per)),
            'open' => DB::table('question_reports')->where('status', 'open')->count(),
        ]);
    }

    public function resolve(Request $request, $id): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        return DB::table('question_reports')->where('id', $id)->update(['status' => 'resolved', 'updated_at' => now()])
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'Not found'], 404);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        return DB::table('question_reports')->where('id', $id)->delete()
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'Not found'], 404);
    }
}
