<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExamController extends Controller
{
    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function find(Request $request, $id): ?object
    {
        return DB::table('exams')->where('id', $id)->where('user_id', $request->user()->id)->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'Exam not found'], 404);
    }

    private function view(object $exam, bool $withAnswers): array
    {
        $rows = DB::table('exam_questions as eq')
            ->join('questions as q', 'q.id', '=', 'eq.question_id')
            ->join('subjects as s', 's.id', '=', 'q.subject_id')
            ->where('eq.exam_id', $exam->id)
            ->orderBy('eq.position')
            ->get(['q.id', 's.name as subject', 'q.text', 'q.options', 'q.answer', 'q.explanation', 'eq.selected']);

        return [
            'id' => $exam->id,
            'subjects' => json_decode($exam->subjects, true),
            'startedAt' => (int) $exam->started_at,
            'durationSec' => (int) $exam->duration_sec,
            'endsAt' => (int) $exam->started_at + (int) $exam->duration_sec * 1000,
            'submitted' => $exam->submitted_at !== null,
            'score' => $exam->score,
            'total' => (int) $exam->total,
            'questions' => $rows->map(function ($q) use ($withAnswers) {
                $item = [
                    'id' => $q->id,
                    'subject' => $q->subject,
                    'text' => $q->text,
                    'options' => json_decode($q->options, true),
                    'selected' => $q->selected,
                ];
                if ($withAnswers) {
                    $item['answer'] = (int) $q->answer;
                    $item['explanation'] = $q->explanation;
                }
                return $item;
            })->values()->all(),
        ];
    }

    // body: { subjects: ["Mathematics"], perSubject: 10, minutes: 15 }
    public function store(Request $request): JsonResponse
    {
        $subjects = $request->input('subjects', []);
        if (!is_array($subjects) || count($subjects) < 1 || count($subjects) > 4) {
            return response()->json(['error' => 'Choose 1 to 4 subjects'], 400);
        }
        $per = max(1, min(60, (int) $request->input('perSubject', 10)));
        $minutes = max(1, min(120, (int) $request->input('minutes', 15)));

        $picked = [];
        foreach ($subjects as $name) {
            $ids = DB::table('questions as q')
                ->join('subjects as s', 's.id', '=', 'q.subject_id')
                ->where('s.name', (string) $name)
                ->inRandomOrder()
                ->limit($per)
                ->pluck('q.id')
                ->all();
            if (!$ids) {
                return response()->json(['error' => "No questions for {$name}"], 400);
            }
            array_push($picked, ...$ids);
        }

        $examId = DB::transaction(function () use ($request, $subjects, $minutes, $picked) {
            $id = DB::table('exams')->insertGetId([
                'user_id' => $request->user()->id,
                'subjects' => json_encode(array_values($subjects)),
                'started_at' => $this->nowMs(),
                'duration_sec' => $minutes * 60,
                'total' => count($picked),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($picked as $i => $qid) {
                DB::table('exam_questions')->insert(['exam_id' => $id, 'question_id' => $qid, 'position' => $i]);
            }
            return $id;
        });

        return response()->json($this->view(DB::table('exams')->find($examId), false), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('exams')->where('user_id', $request->user()->id)->orderByDesc('id')->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'subjects' => json_decode($e->subjects, true),
                'startedAt' => (int) $e->started_at,
                'score' => $e->score,
                'total' => (int) $e->total,
                'submitted' => $e->submitted_at !== null,
            ]);

        return response()->json($rows->values());
    }

    public function show(Request $request, $id): JsonResponse
    {
        $exam = $this->find($request, $id);
        if (!$exam) return $this->notFound();

        // correct answers are only revealed after submit
        return response()->json($this->view($exam, $exam->submitted_at !== null));
    }

    public function answer(Request $request, $id): JsonResponse
    {
        $exam = $this->find($request, $id);
        if (!$exam) return $this->notFound();
        if ($exam->submitted_at !== null) {
            return response()->json(['error' => 'Already submitted'], 409);
        }
        if ($this->nowMs() > (int) $exam->started_at + (int) $exam->duration_sec * 1000 + 3000) {
            return response()->json(['error' => 'Time is up'], 409);
        }

        $qid = (int) $request->input('questionId');
        $selected = $request->input('selected');

        $row = DB::table('exam_questions')->where('exam_id', $exam->id)->where('question_id', $qid);
        if (!$row->exists()) {
            return response()->json(['error' => 'Question not in this exam'], 404);
        }
        $row->update(['selected' => $selected === null ? null : (int) $selected]);

        return response()->json(['ok' => true]);
    }

    public function submit(Request $request, $id): JsonResponse
    {
        $exam = $this->find($request, $id);
        if (!$exam) return $this->notFound();

        if ($exam->submitted_at === null) {
            $score = DB::table('exam_questions as eq')
                ->join('questions as q', 'q.id', '=', 'eq.question_id')
                ->where('eq.exam_id', $exam->id)
                ->whereColumn('eq.selected', 'q.answer')
                ->count();
            DB::table('exams')->where('id', $exam->id)->update([
                'submitted_at' => $this->nowMs(),
                'score' => $score,
                'updated_at' => now(),
            ]);
            $exam = DB::table('exams')->find($exam->id);
        }

        return response()->json($this->view($exam, true));
    }
}
