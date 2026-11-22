<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    private const TZ = 'Africa/Lagos';
    private const MIN_QUESTIONS = 20;   // a leaderboard entry needs at least this many answered questions

    /** Current and best run of consecutive days with a finished exam. $days = sorted unique 'Y-m-d'. */
    private function streak(array $days, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $set = array_flip($days);

        $d = $now->setTime(0, 0);
        $doneToday = isset($set[$d->format('Y-m-d')]);
        if (!$doneToday) $d = $d->modify('-1 day');       // today is not over yet, so yesterday still counts
        $current = 0;
        while (isset($set[$d->format('Y-m-d')])) {
            $current++;
            $d = $d->modify('-1 day');
        }

        $best = 0;
        $run = 0;
        $prev = null;
        foreach ($days as $day) {
            $run = ($prev !== null && (new \DateTimeImmutable($prev))->modify('+1 day')->format('Y-m-d') === $day) ? $run + 1 : 1;
            $best = max($best, $run);
            $prev = $day;
        }

        return ['current' => $current, 'best' => $best, 'doneToday' => $doneToday];
    }

    // GET /api/stats/progress : score history, per-subject accuracy and streak
    public function progress(Request $request): JsonResponse
    {
        $uid = $request->user()->id;

        $exams = DB::table('exams')
            ->where('user_id', $uid)->whereNotNull('submitted_at')
            ->orderByDesc('id')->limit(30)
            ->get(['id', 'started_at', 'score', 'total', 'mode'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'startedAt' => (int) $e->started_at,
                'score' => (int) $e->score,
                'total' => (int) $e->total,
                'pct' => $e->total ? (int) round($e->score / $e->total * 100) : 0,
                'mode' => $e->mode ?? 'exam',
            ])->reverse()->values();

        $subjects = DB::table('exam_questions as eq')
            ->join('exams as e', 'e.id', '=', 'eq.exam_id')
            ->join('questions as q', 'q.id', '=', 'eq.question_id')
            ->join('subjects as s', 's.id', '=', 'q.subject_id')
            ->where('e.user_id', $uid)->whereNotNull('e.submitted_at')
            ->groupBy('s.name')
            ->get(['s.name as subject', DB::raw('COUNT(*) as total'), DB::raw('SUM(CASE WHEN eq.selected = q.answer THEN 1 ELSE 0 END) as correct')])
            ->map(fn ($r) => [
                'subject' => $r->subject,
                'total' => (int) $r->total,
                'correct' => (int) $r->correct,
                'pct' => $r->total ? (int) round($r->correct / $r->total * 100) : 0,
            ])->sortBy('pct')->values();

        $tz = new \DateTimeZone(self::TZ);
        $days = DB::table('exams')->where('user_id', $uid)->whereNotNull('submitted_at')->pluck('submitted_at')
            ->map(fn ($ms) => (new \DateTimeImmutable('@' . intdiv((int) $ms, 1000)))->setTimezone($tz)->format('Y-m-d'))
            ->unique()->sort()->values()->all();

        return response()->json(['exams' => $exams, 'subjects' => $subjects, 'streak' => $this->streak($days)]);
    }

    private function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $first = $parts[0] ?? 'Student';
        return count($parts) > 1 ? $first . ' ' . mb_substr(end($parts), 0, 1) . '.' : $first;
    }

    // GET /api/leaderboard?period=week|all : accuracy in timed exams, minimum 20 answered questions
    public function leaderboard(Request $request): JsonResponse
    {
        $period = $request->query('period') === 'all' ? 'all' : 'week';

        $q = DB::table('exam_questions as eq')
            ->join('exams as e', 'e.id', '=', 'eq.exam_id')
            ->join('questions as q', 'q.id', '=', 'eq.question_id')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->whereNotNull('e.submitted_at')
            ->where('e.mode', 'exam');                       // study sessions do not count
        if ($period === 'week') {
            $q->where('e.submitted_at', '>=', (int) (now()->subDays(7)->timestamp * 1000));
        }

        $ranked = $q->groupBy('u.id', 'u.name')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_QUESTIONS])
            ->get(['u.id', 'u.name', DB::raw('COUNT(*) as total'), DB::raw('SUM(CASE WHEN eq.selected = q.answer THEN 1 ELSE 0 END) as correct')])
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $this->shortName($r->name),
                'total' => (int) $r->total,
                'pct' => round($r->correct / $r->total * 100, 1),
            ])
            ->sort(fn ($a, $b) => [$b['pct'], $b['total']] <=> [$a['pct'], $a['total']])
            ->values()
            ->map(fn ($r, $i) => $r + ['rank' => $i + 1]);

        $uid = $request->user()->id;
        $me = $ranked->firstWhere('id', $uid);

        return response()->json([
            'period' => $period,
            'minQuestions' => self::MIN_QUESTIONS,
            'top' => $ranked->take(10)->map(fn ($r) => ['rank' => $r['rank'], 'name' => $r['name'], 'pct' => $r['pct'], 'total' => $r['total'], 'me' => $r['id'] === $uid])->values(),
            'me' => $me ? ['rank' => $me['rank'], 'pct' => $me['pct'], 'total' => $me['total']] : null,
            'participants' => $ranked->count(),
        ]);
    }
}
