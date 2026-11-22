<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseAdminController extends Controller
{
    private function deny(Request $request): ?JsonResponse
    {
        return $request->user()->role === 'admin' ? null : response()->json(['error' => 'Admins only'], 403);
    }

    /** Trims, drops blanks and duplicates. Returns [list|null, error|null]. */
    private function cleanSubjects($subjects): array
    {
        $out = [];
        foreach ((array) $subjects as $s) {
            $s = trim((string) $s);
            if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
        }
        if (count($out) < 2 || count($out) > 10) {
            return [null, 'A course needs 2 to 10 subjects (Use of English first)'];
        }
        return [$out, null];
    }

    public function store(Request $request): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        $name = trim((string) $request->input('name'));
        [$subjects, $err] = $this->cleanSubjects($request->input('subjects'));
        if ($name === '' || $err) return response()->json(['error' => $err ?? 'Course name is required'], 400);
        if (Course::where('name', $name)->exists()) {
            return response()->json(['error' => 'A course with this name already exists'], 409);
        }

        $note = trim((string) $request->input('notes'));
        $source = trim((string) $request->input('source')) ?: 'JAMB brochure';

        $course = Course::create([
            'name' => $name,
            'subjects' => $subjects,
            'notes' => $note !== '' ? $note : null,
            'source' => $source,
        ]);

        return response()->json(['id' => $course->id], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        $course = Course::find($id);
        if (!$course) return response()->json(['error' => 'Not found'], 404);

        $name = trim((string) $request->input('name'));
        [$subjects, $err] = $this->cleanSubjects($request->input('subjects'));
        if ($name === '' || $err) return response()->json(['error' => $err ?? 'Course name is required'], 400);
        if (Course::where('name', $name)->where('id', '!=', $course->id)->exists()) {
            return response()->json(['error' => 'Another course already has this name'], 409);
        }

        $note = trim((string) $request->input('notes'));
        $course->update([
            'name' => $name,
            'subjects' => $subjects,
            'notes' => $note !== '' ? $note : null,
            'source' => trim((string) $request->input('source')) ?: 'JAMB brochure',
        ]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        $course = Course::find($id);
        if (!$course) return response()->json(['error' => 'Not found'], 404);
        $course->delete();

        return response()->json(['ok' => true]);
    }

    // body: { courses: [{ name, subjects: [...], notes, source }] }, up to 1000. Same name = updated.
    public function import(Request $request): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;

        $items = $request->input('courses');
        if (!is_array($items) || !count($items) || count($items) > 1000) {
            return response()->json(['error' => 'Send between 1 and 1000 courses'], 400);
        }

        $added = 0;
        $updated = 0;
        $errors = [];

        DB::transaction(function () use ($items, &$added, &$updated, &$errors) {
            foreach ($items as $n => $it) {
                $row = $n + 1;
                $name = trim((string) ($it['name'] ?? ''));
                [$subjects, $err] = $this->cleanSubjects($it['subjects'] ?? []);
                if ($name === '' || $err) {
                    $errors[] = "Row {$row}" . ($name !== '' ? " ({$name})" : '') . ': ' . ($err ?? 'course name is missing');
                    continue;
                }
                $note = trim((string) ($it['notes'] ?? ''));
                $course = Course::firstOrNew(['name' => $name]);
                $isNew = !$course->exists;
                $course->subjects = $subjects;
                $course->notes = $note !== '' ? $note : null;
                $course->source = trim((string) ($it['source'] ?? '')) ?: 'JAMB brochure';
                $course->save();
                $isNew ? $added++ : $updated++;
            }
        });

        return response()->json(['added' => $added, 'updated' => $updated, 'errors' => array_slice($errors, 0, 50)]);
    }
}
