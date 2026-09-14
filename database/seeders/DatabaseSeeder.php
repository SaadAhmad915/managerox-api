<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a coherent demo dataset across the three objects.
 *
 * Figures are chosen so the dashboard reads like the product design — the deal
 * funnel lands on 1250 / 640 / 320 / 210 / 180 and attainment on 92 / 78 / 65 /
 * 58 % — while every number the API reports is genuinely derived from rows.
 */
class DatabaseSeeder extends Seeder
{
    private const TARGET = 10_000_000;

    /** name, email, role, % of TARGET won this month */
    private const TEAM = [
        ['Ali Khan', 'ali@managerox.com', 'Sales Manager', 92],
        ['Sara Ahmed', 'sara@managerox.com', 'Sales Executive', 78],
        ['Bilal Raza', 'bilal@managerox.com', 'Sales Executive', 65],
        ['Ayesha Malik', 'ayesha@managerox.com', 'Sales Executive', 58],
    ];

    /** Millions of PKR won in each of the five months before this one. */
    private const PRIOR_MONTHS = [8, 12, 16, 20, 25];

    private const FUNNEL = [
        'new' => 1250,
        'qualified' => 640,
        'proposal' => 320,
        'negotiation' => 210,
        'closed' => 180,
    ];

    private const PEOPLE = [
        ['Farhan Ali', 'Residential Plot – DHA Lahore', 'Zameen Group'],
        ['Sara Khan', 'Commercial – DHA Karachi', 'Al-Fatah'],
        ['Ahmad Malik', 'Villa – DHA Islamabad', 'Skyline Developers'],
        ['Nida Zahra', 'Apartment – DHA Multan', 'Pearl Estates'],
        ['Hassan Raza', 'Farmhouse – Bedian Road', 'Raza & Sons'],
        ['Mariam Sheikh', 'Office Floor – Gulberg', 'Sheikh Holdings'],
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

        // Named contacts, each converted from an enquiry.
        $contacts = collect(self::PEOPLE)->map(function (array $row, int $i) use ($users) {
            $lead = Lead::create([
                'name' => $row[0],
                'detail' => $row[1],
                'status' => 'converted',
                'converted_at' => now()->subDays($i + 1),
                'owner_id' => $users[$i % $users->count()]->id,
                'created_at' => now()->subDays($i + 3),
            ]);

            return Contact::create([
                'name' => $row[0],
                'email' => str($row[0])->lower()->replace(' ', '.').'@example.com',
                'phone' => '+9230012345'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'company' => $row[2],
                'notes' => $row[1],
                'owner_id' => $lead->owner_id,
                'lead_id' => $lead->id,
            ]);
        });

        // This month's won revenue, split so each rep's attainment is exact.
        foreach (self::TEAM as $i => $row) {
            Deal::create([
                'title' => "{$row[0]} — won this month",
                'contact_id' => $contacts->random()->id,
                'owner_id' => $users[$i]->id,
                'stage' => 'closed',
                'value' => (int) (self::TARGET * $row[3] / 100),
                'closed_at' => now()->startOfMonth()->addDays(3),
            ]);
        }

        // Earlier months, one aggregate deal each, for the revenue trend.
        foreach (self::PRIOR_MONTHS as $offset => $millions) {
            $month = now()->startOfMonth()->subMonths(count(self::PRIOR_MONTHS) - $offset);
            Deal::create([
                'title' => 'Won — '.$month->format('F Y'),
                'contact_id' => $contacts->random()->id,
                'owner_id' => $owner->id,
                'stage' => 'closed',
                'value' => $millions * 1_000_000,
                'closed_at' => $month->copy()->addDays(14),
            ]);
        }

        // Bulk deals so the funnel totals land exactly on self::FUNNEL.
        $already = ['closed' => count(self::TEAM) + count(self::PRIOR_MONTHS)];
        $weights = [10, 12, 14, 16, 18, 20];
        $weightTotal = array_sum($weights);

        foreach (self::FUNNEL as $stage => $target) {
            $remaining = $target - ($already[$stage] ?? 0);
            $rows = [];
            $cursor = 0;

            foreach ($weights as $index => $weight) {
                $share = $index === count($weights) - 1
                    ? $remaining - $cursor
                    : (int) round($remaining * $weight / $weightTotal);

                $monthStart = now()->startOfMonth()->subMonths(count($weights) - 1 - $index);

                for ($i = 1; $i <= $share; $i++) {
                    $createdAt = $monthStart->copy()
                        ->addDays(random_int(0, max(0, $monthStart->daysInMonth - 1)));
                    if ($createdAt->isFuture()) {
                        $createdAt = now()->subHours(random_int(1, 48));
                    }
                    $cursor++;
                    $rows[] = [
                        'title' => Deal::STAGES[$stage]." opportunity {$cursor}",
                        'contact_id' => $contacts->random()->id,
                        'owner_id' => $users->random()->id,
                        'stage' => $stage,
                        'value' => 0,
                        'closed_at' => $stage === 'closed' ? $createdAt->copy()->addDays(2) : null,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                Deal::insert($chunk);
            }
        }

        // Open enquiries still waiting to be worked.
        foreach ([
            ['Usman Tariq', 'Plot enquiry – Bahria Town', 'new', 12],
            ['Zainab Nasir', 'Shop – Emporium Mall', 'contacted', 90],
            ['Kashif Iqbal', '10 Marla – Askari 11', 'qualified', 260],
            ['Rabia Anwar', 'Studio – Gulberg Greens', 'new', 420],
        ] as $row) {
            Lead::create([
                'name' => $row[0],
                'detail' => $row[1],
                'status' => $row[2],
                'owner_id' => $users->random()->id,
                'created_at' => now()->subMinutes($row[3]),
            ]);
        }

        foreach ([
            ['Follow up with Ahmed (Phase 7 Plot)', now()->setTime(11, 0)],
            ['Send proposal to Zameen Group', now()->setTime(14, 0)],
            ['Call new lead from website', now()->addDay()->setTime(10, 0)],
            ['Prepare weekly sales report', now()->addDay()->setTime(16, 0)],
            ['Site visit with Mariam Sheikh', now()->addDays(2)->setTime(12, 30)],
        ] as $i => $row) {
            Task::create([
                'title' => $row[0],
                'due_at' => $row[1],
                'user_id' => $owner->id,
                'contact_id' => $contacts[$i % $contacts->count()]->id,
            ]);
        }
    }
}
