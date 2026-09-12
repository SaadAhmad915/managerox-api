<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a coherent demo dataset.
 *
 * The figures are chosen so the dashboard reads like the product design —
 * the funnel lands on 1250 / 640 / 320 / 210 / 180 and team attainment on
 * 92 / 78 / 65 / 58 % — while every number the API reports is genuinely
 * derived from these rows rather than hardcoded.
 */
class DatabaseSeeder extends Seeder
{
    private const TARGET = 10_000_000;

    /** name, email, role, attainment % of TARGET closed this month */
    private const TEAM = [
        ['Ali Khan', 'ali@managerox.com', 'Sales Manager', 92],
        ['Sara Ahmed', 'sara@managerox.com', 'Sales Executive', 78],
        ['Bilal Raza', 'bilal@managerox.com', 'Sales Executive', 65],
        ['Ayesha Malik', 'ayesha@managerox.com', 'Sales Executive', 58],
    ];

    /** Millions of PKR closed in each of the five months before this one. */
    private const PRIOR_MONTHS = [8, 12, 16, 20, 25];

    /** Target funnel size per stage, including every lead this seeder makes. */
    private const FUNNEL = [
        'new' => 1250,
        'qualified' => 640,
        'proposal' => 320,
        'negotiation' => 210,
        'closed' => 180,
    ];

    private const RECENT = [
        ['Farhan Ali', 'Residential Plot – DHA Lahore', 10],
        ['Sara Khan', 'Commercial – DHA Karachi', 60],
        ['Ahmad Malik', 'Villa – DHA Islamabad', 180],
        ['Nida Zahra', 'Apartment – DHA Multan', 300],
    ];

    public function run(): void
    {
        $users = collect(self::TEAM)->map(fn (array $row) => User::create([
            'name' => $row[0],
            'email' => $row[1],
            'role' => $row[2],
            'password' => 'password',
            'monthly_target' => self::TARGET,
        ]));

        $owner = $users->first();

        // This month's closed revenue, split so each rep's attainment is exact.
        foreach (self::TEAM as $i => $row) {
            Lead::create([
                'name' => "{$row[0]} — closed this month",
                'detail' => 'Aggregate closed revenue',
                'stage' => 'closed',
                'value' => (int) (self::TARGET * $row[3] / 100),
                'owner_id' => $users[$i]->id,
                'closed_at' => now()->startOfMonth()->addDays(3),
            ]);
        }

        // Earlier months, one aggregate row each, for the revenue trend.
        foreach (self::PRIOR_MONTHS as $offset => $millions) {
            $month = now()->startOfMonth()->subMonths(count(self::PRIOR_MONTHS) - $offset);
            Lead::create([
                'name' => 'Closed — '.$month->format('F Y'),
                'detail' => 'Aggregate closed revenue',
                'stage' => 'closed',
                'value' => $millions * 1_000_000,
                'owner_id' => $owner->id,
                'closed_at' => $month->copy()->addDays(14),
            ]);
        }

        // Named recent leads shown on the dashboard.
        foreach (self::RECENT as $row) {
            Lead::create([
                'name' => $row[0],
                'detail' => $row[1],
                'stage' => 'new',
                'owner_id' => $owner->id,
                'created_at' => now()->subMinutes($row[2]),
            ]);
        }

        // Bulk filler, reduced by the rows already created in each stage, so
        // the funnel totals land exactly on self::FUNNEL.
        $already = [
            'new' => count(self::RECENT),
            'closed' => count(self::TEAM) + count(self::PRIOR_MONTHS),
        ];

        // Spread creation across the last six months on a rising curve, so
        // month-over-month trends read as steady growth rather than noise.
        $weights = [10, 12, 14, 16, 18, 20];
        $weightTotal = array_sum($weights);

        foreach (self::FUNNEL as $stage => $target) {
            $remaining = $target - ($already[$stage] ?? 0);
            $rows = [];
            $cursor = 0;
            foreach ($weights as $monthsAgo => $weight) {
                $share = (int) round($remaining * $weight / $weightTotal);
                if ($monthsAgo === count($weights) - 1) {
                    $share = $remaining - $cursor;   // last bucket takes the remainder
                }
                $monthStart = now()->startOfMonth()->subMonths(count($weights) - 1 - $monthsAgo);
                for ($i = 1; $i <= $share; $i++) {
                    $createdAt = $monthStart->copy()->addDays(random_int(0, max(0, $monthStart->daysInMonth - 1)));
                    if ($createdAt->isFuture()) {
                        $createdAt = now()->subHours(random_int(1, 48));
                    }
                    $cursor++;
                    $rows[] = [
                        'name' => ucfirst($stage)." Lead {$cursor}",
                        'detail' => 'Residential Plot – DHA Lahore',
                        'stage' => $stage,
                        'value' => 0,
                        'owner_id' => $users->random()->id,
                        'closed_at' => $stage === 'closed' ? $createdAt->copy()->addDays(2) : null,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                Lead::insert($chunk);
            }
        }

        foreach ([
            ['Follow up with Ahmed (Phase 7 Plot)', now()->setTime(11, 0)],
            ['Send proposal to Zameen Group', now()->setTime(14, 0)],
            ['Call new lead from website', now()->addDay()->setTime(10, 0)],
            ['Prepare weekly sales report', now()->addDay()->setTime(16, 0)],
        ] as $row) {
            Task::create(['title' => $row[0], 'due_at' => $row[1], 'user_id' => $owner->id]);
        }
    }
}
