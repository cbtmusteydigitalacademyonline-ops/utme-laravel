<?php

namespace App\Console\Commands;

use App\Models\Course;
use Illuminate\Console\Command;

class ImportCourses extends Command
{
    protected $signature = 'courses:import {path=database/data/utme_courses_from_brochure.csv}';

    protected $description = 'Import or update courses from a CSV (columns: course, subjects, notes, source)';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        if (!is_file($path)) {
            $this->error("File not found: $path");
            return self::FAILURE;
        }

        $h = fopen($path, 'r');
        $head = fgetcsv($h);
        $added = 0;
        $updated = 0;

        while (($r = fgetcsv($h)) !== false) {
            if (count($r) < 2) {
                continue;
            }
            $row = array_combine($head, array_pad($r, count($head), ''));
            $subjects = array_values(array_filter(array_map('trim', explode('|', $row['subjects']))));
            $course = Course::updateOrCreate(
                ['name' => trim($row['course'])],
                [
                    'subjects' => $subjects,
                    'notes' => trim($row['notes'] ?? '') ?: null,
                    'source' => trim($row['source'] ?? '') ?: 'JAMB brochure',
                ]
            );
            $course->wasRecentlyCreated ? $added++ : $updated++;
        }
        fclose($h);

        $this->info("Added $added, updated $updated.");
        return self::SUCCESS;
    }
}
