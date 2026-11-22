<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(<<<'JSON'
{
 "Use of English": [
  {
   "t": "Choose the word closest in meaning to ABUNDANT.",
   "o": [
    "Scarce",
    "Plentiful",
    "Tiny",
    "Rare"
   ],
   "a": 1
  },
  {
   "t": "Neither of the boys ___ present.",
   "o": [
    "are",
    "were",
    "is",
    "have"
   ],
   "a": 2
  },
  {
   "t": "Choose the opposite of GENEROUS.",
   "o": [
    "Stingy",
    "Kind",
    "Rich",
    "Noble"
   ],
   "a": 0
  },
  {
   "t": "The committee ___ its decision yesterday.",
   "o": [
    "announce",
    "announced",
    "announcing",
    "have announced"
   ],
   "a": 1
  }
 ],
 "Mathematics": [
  {
   "t": "Solve 2x + 5 = 17.",
   "o": [
    "x = 5",
    "x = 6",
    "x = 7",
    "x = 8"
   ],
   "a": 1
  },
  {
   "t": "What is 25% of 240?",
   "o": [
    "50",
    "60",
    "70",
    "80"
   ],
   "a": 1
  },
  {
   "t": "Find the square root of 144.",
   "o": [
    "10",
    "11",
    "12",
    "14"
   ],
   "a": 2
  },
  {
   "t": "Simplify 3² × 3³.",
   "o": [
    "81",
    "243",
    "729",
    "27"
   ],
   "a": 1
  }
 ],
 "Biology": [
  {
   "t": "Which organelle is the site of aerobic respiration?",
   "o": [
    "Nucleus",
    "Ribosome",
    "Mitochondrion",
    "Vacuole"
   ],
   "a": 2
  },
  {
   "t": "Plants make food by?",
   "o": [
    "Respiration",
    "Photosynthesis",
    "Digestion",
    "Excretion"
   ],
   "a": 1
  },
  {
   "t": "Which blood group is the universal donor?",
   "o": [
    "A",
    "B",
    "AB",
    "O"
   ],
   "a": 3
  },
  {
   "t": "Which organ filters blood to form urine?",
   "o": [
    "Liver",
    "Kidney",
    "Lung",
    "Heart"
   ],
   "a": 1
  }
 ],
 "Chemistry": [
  {
   "t": "The chemical symbol of sodium is?",
   "o": [
    "S",
    "So",
    "Na",
    "Sd"
   ],
   "a": 2
  },
  {
   "t": "The pH of a neutral solution at 25°C is?",
   "o": [
    "0",
    "7",
    "10",
    "14"
   ],
   "a": 1
  },
  {
   "t": "The atomic number of carbon is?",
   "o": [
    "4",
    "6",
    "8",
    "12"
   ],
   "a": 1
  },
  {
   "t": "Which is a noble gas?",
   "o": [
    "Oxygen",
    "Nitrogen",
    "Neon",
    "Chlorine"
   ],
   "a": 2
  }
 ],
 "Physics": [
  {
   "t": "The SI unit of force is?",
   "o": [
    "Joule",
    "Watt",
    "Newton",
    "Pascal"
   ],
   "a": 2
  },
  {
   "t": "Acceleration due to gravity on Earth is about?",
   "o": [
    "1 m/s²",
    "10 m/s²",
    "100 m/s²",
    "3 m/s²"
   ],
   "a": 1
  },
  {
   "t": "The unit of electrical resistance is?",
   "o": [
    "Ohm",
    "Volt",
    "Ampere",
    "Farad"
   ],
   "a": 0
  },
  {
   "t": "Light travels fastest in?",
   "o": [
    "Water",
    "Glass",
    "Air",
    "Vacuum"
   ],
   "a": 3
  }
 ],
 "Economics": [
  {
   "t": "Scarcity means?",
   "o": [
    "Too many goods",
    "Limited resources against unlimited wants",
    "Low prices",
    "No demand"
   ],
   "a": 1
  },
  {
   "t": "The law of demand says that when price rises, quantity demanded?",
   "o": [
    "Rises",
    "Falls",
    "Stays same",
    "Doubles"
   ],
   "a": 1
  },
  {
   "t": "GDP stands for?",
   "o": [
    "Gross Domestic Product",
    "General Debt Payment",
    "Gross Direct Profit",
    "Grand Domestic Price"
   ],
   "a": 0
  },
  {
   "t": "Opportunity cost is?",
   "o": [
    "Money spent",
    "The next best alternative given up",
    "Total cost",
    "Tax paid"
   ],
   "a": 1
  }
 ]
}

JSON, true);

        foreach ($data as $subject => $list) {
            $sid = DB::table('subjects')->where('name', $subject)->value('id')
                ?? DB::table('subjects')->insertGetId(['name' => $subject, 'created_at' => now(), 'updated_at' => now()]);

            foreach ($list as $q) {
                if (DB::table('questions')->where('subject_id', $sid)->where('text', $q['t'])->exists()) continue;
                DB::table('questions')->insert([
                    'subject_id' => $sid,
                    'text' => $q['t'],
                    'options' => json_encode($q['o']),
                    'answer' => $q['a'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            ['name' => 'Admin', 'password' => env('ADMIN_PASSWORD', 'admin1234'), 'role' => 'admin']
        );
    }
}
