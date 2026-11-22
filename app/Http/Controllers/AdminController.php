<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    private function deny(Request $request): ?JsonResponse
    {
        return $request->user()->role === 'admin' ? null : response()->json(['error' => 'Admins only'], 403);
    }

    // GET /admin/questions?page=1&per=20&subject=Biology&q=text&missing=1
    public function questions(Request $request): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        $per = max(5, min(100, (int) $request->query('per', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $subject = trim((string) $request->query('subject', ''));
        $term = trim((string) $request->query('q', ''));

        $base = DB::table('questions as q')->join('subjects as s', 's.id', '=', 'q.subject_id');
        if ($subject !== '') $base->where('s.name', $subject);
        if ($term !== '') $base->where('q.text', 'like', '%' . $term . '%');
        if ($request->boolean('missing')) $base->whereNull('q.explanation');

        $total = (clone $base)->count();

        $rows = $base->orderBy('s.name')->orderBy('q.id')
            ->forPage($page, $per)
            ->get(['q.id', 's.name as subject', 'q.text', 'q.options', 'q.answer', 'q.explanation'])
            ->map(fn ($q) => [
                'id' => $q->id, 'subject' => $q->subject, 'text' => $q->text,
                'options' => json_decode($q->options, true), 'answer' => (int) $q->answer,
                'explanation' => $q->explanation,
            ]);

        return response()->json([
            'data' => $rows->values(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $per)),
        ]);
    }

    public function storeQuestion(Request $request): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        $subject = trim((string) $request->input('subject'));
        $text = trim((string) $request->input('text'));
        $options = $request->input('options');
        $answer = $request->input('answer');

        if ($subject === '' || $text === '' || !is_array($options) || count($options) < 2
            || !is_int($answer) || $answer < 0 || $answer >= count($options)) {
            return response()->json(['error' => 'subject, text, options[] and a valid answer index are required'], 400);
        }

        $sid = DB::table('subjects')->where('name', $subject)->value('id')
            ?? DB::table('subjects')->insertGetId(['name' => $subject, 'created_at' => now(), 'updated_at' => now()]);

        $id = DB::table('questions')->insertGetId([
            'subject_id' => $sid,
            'text' => $text,
            'options' => json_encode(array_values($options)),
            'answer' => $answer,
            'explanation' => $request->input('explanation'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    // body: { questions: [{ subject, text, options: [...], answer: 0-based index }] }
    public function importQuestions(Request $request): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        $items = $request->input('questions');
        if (!is_array($items) || !count($items) || count($items) > 1000) {
            return response()->json(['error' => 'Send between 1 and 1000 questions'], 400);
        }

        $added = 0;
        $skipped = 0;
        $updated = 0;
        $errors = [];
        $subjectIds = [];

        DB::transaction(function () use ($items, &$added, &$skipped, &$updated, &$errors, &$subjectIds) {
            foreach ($items as $n => $it) {
                $row = $n + 1;
                $subject = trim((string) ($it['subject'] ?? ''));
                $text = trim((string) ($it['text'] ?? ''));
                $options = array_values(array_filter(
                    array_map(fn ($o) => trim((string) $o), (array) ($it['options'] ?? [])),
                    fn ($o) => $o !== ''
                ));
                $answer = $it['answer'] ?? null;

                if ($subject === '' || $text === '' || count($options) < 2) {
                    $errors[] = "Row {$row}: subject, question and at least 2 options are required";
                    continue;
                }
                if (!is_int($answer) || $answer < 0 || $answer >= count($options)) {
                    $errors[] = "Row {$row}: correct answer is missing or invalid";
                    continue;
                }

                if (!isset($subjectIds[$subject])) {
                    $subjectIds[$subject] = DB::table('subjects')->where('name', $subject)->value('id')
                        ?? DB::table('subjects')->insertGetId(['name' => $subject, 'created_at' => now(), 'updated_at' => now()]);
                }
                $sid = $subjectIds[$subject];

                $existing = DB::table('questions')->where('subject_id', $sid)->where('text', $text)->first();
                if ($existing) {
                    $note = trim((string) ($it['explanation'] ?? ''));
                    if ($note !== '' && $note !== (string) $existing->explanation) {
                        DB::table('questions')->where('id', $existing->id)->update(['explanation' => $note, 'updated_at' => now()]);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                DB::table('questions')->insert([
                    'subject_id' => $sid,
                    'text' => $text,
                    'options' => json_encode($options),
                    'answer' => $answer,
                    'explanation' => ($it['explanation'] ?? '') !== '' ? $it['explanation'] : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $added++;
            }
        });

        return response()->json(['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 50)]);
    }

    public function updateQuestion(Request $request, $id): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        if (!DB::table('questions')->where('id', $id)->exists()) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $subject = trim((string) $request->input('subject'));
        $text = trim((string) $request->input('text'));
        $options = $request->input('options');
        $answer = $request->input('answer');

        if ($subject === '' || $text === '' || !is_array($options) || count($options) < 2
            || !is_int($answer) || $answer < 0 || $answer >= count($options)) {
            return response()->json(['error' => 'subject, text, options[] and a valid answer index are required'], 400);
        }

        $sid = DB::table('subjects')->where('name', $subject)->value('id')
            ?? DB::table('subjects')->insertGetId(['name' => $subject, 'created_at' => now(), 'updated_at' => now()]);

        $note = trim((string) $request->input('explanation'));

        DB::table('questions')->where('id', $id)->update([
            'subject_id' => $sid,
            'text' => $text,
            'options' => json_encode(array_values($options)),
            'answer' => $answer,
            'explanation' => $note !== '' ? $note : null,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function deleteQuestion(Request $request, $id): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        return DB::table('questions')->where('id', $id)->delete()
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'Not found'], 404);
    }

    public function stats(Request $request): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        return response()->json([
            'students' => DB::table('users')->where('role', 'student')->count(),
            'questions' => DB::table('questions')->count(),
            'exams' => DB::table('exams')->whereNotNull('submitted_at')->count(),
            'unexplained' => DB::table('questions')->whereNull('explanation')->count(),
        ]);
    }
}
