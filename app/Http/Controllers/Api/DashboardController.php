<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Builds the dashboard payload.
 *
 * The response shape mirrors `DashboardData` in the CRM frontend
 * (managerox-app/app/lib/types.ts). Keep the two in step: the frontend reads
 * these keys directly, so a rename here is a breaking change there.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $monthStart = now()->startOfMonth();
        $prevStart = $monthStart->copy()->subMonth();

        return response()->json([
            'user' => [
                'firstName' => explode(' ', $user->name)[0],
                'fullName' => $user->name,
                'role' => $user->role,
            ],
            'stats' => $this->stats($monthStart, $prevStart),
            'pipeline' => $this->pipeline(),
            'revenue' => $this->revenue($monthStart, $prevStart),
            'tasks' => $this->tasks(),
            'recentLeads' => $this->recentLeads(),
            'team' => $this->team($monthStart),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function stats(Carbon $monthStart, Carbon $prevStart): array
    {
        $between = fn (string $model, string $column, Carbon $from, ?Carbon $to) => $model::query()
            ->where($column, '>=', $from)
            ->when($to, fn ($q) => $q->where($column, '<', $to))
            ->count();

        $wonValue = fn (Carbon $from, ?Carbon $to) => (int) Deal::won()
            ->where('closed_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('closed_at', '<', $to))
            ->sum('value');

        $openDeals = Deal::active()->whereIn('stage', Deal::OPEN_STAGES)->count();
        $revenue = $wonValue($monthStart, null);

        return [
            $this->stat(
                'leads', 'Total Leads', Lead::count(),
                $between(Lead::class, 'created_at', $monthStart, null),
                $between(Lead::class, 'created_at', $prevStart, $monthStart),
            ),
            $this->stat(
                'deals', 'Active Deals', $openDeals,
                Deal::active()->whereIn('stage', Deal::OPEN_STAGES)
                    ->where('created_at', '>=', $monthStart)->count(),
                Deal::active()->whereIn('stage', Deal::OPEN_STAGES)
                    ->whereBetween('created_at', [$prevStart, $monthStart])->count(),
            ),
            $this->stat(
                'customers', 'Customers', Contact::count(),
                $between(Contact::class, 'created_at', $monthStart, null),
                $between(Contact::class, 'created_at', $prevStart, $monthStart),
            ),
            $this->stat(
                'revenue', 'Revenue (PKR)', $revenue,
                $revenue, $wonValue($prevStart, $monthStart), compact: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function stat(string $key, string $label, int $value, int $current, int $previous, bool $compact = false): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'display' => $compact ? $this->compact($value) : number_format($value),
            'value' => $value,
            'trend' => [
                'changePct' => $previous > 0 ? (int) round((($current - $previous) / $previous) * 100) : 0,
                'comparisonLabel' => 'vs last month',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function pipeline(): array
    {
        $counts = Deal::active()->selectRaw('stage, count(*) as aggregate')
            ->groupBy('stage')->pluck('aggregate', 'stage');

        return collect(Deal::STAGES)
            ->map(fn (string $label, string $stage) => [
                'id' => $stage,
                'label' => $label,
                'count' => (int) ($counts[$stage] ?? 0),
            ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function revenue(Carbon $monthStart, Carbon $prevStart): array
    {
        $points = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = $monthStart->copy()->subMonths($i);
            $points[] = [
                'month' => $start->format('M'),
                'value' => (int) Deal::won()
                    ->whereBetween('closed_at', [$start, $start->copy()->endOfMonth()])
                    ->sum('value'),
            ];
        }

        $total = (int) Deal::won()->where('closed_at', '>=', $monthStart)->sum('value');
        $previous = (int) Deal::won()
            ->whereBetween('closed_at', [$prevStart, $monthStart])->sum('value');

        return [
            'currency' => 'PKR',
            'total' => $total,
            'totalDisplay' => 'PKR '.number_format($total),
            'trend' => [
                'changePct' => $previous > 0 ? (int) round((($total - $previous) / $previous) * 100) : 0,
                'comparisonLabel' => 'vs last month',
            ],
            'points' => $points,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function tasks(): array
    {
        return Task::where('done', false)->orderBy('due_at')->limit(4)->get()
            ->map(fn (Task $task) => [
                'id' => (string) $task->id,
                'title' => $task->title,
                'dueLabel' => $this->dueLabel($task->due_at),
                'priority' => $task->due_at->isToday() || $task->due_at->isPast() ? 'urgent' : 'normal',
                'done' => $task->done,
            ])->all();
    }

    private function dueLabel(Carbon $due): string
    {
        $day = match (true) {
            $due->isToday() => 'Today',
            $due->isTomorrow() => 'Tomorrow',
            default => $due->format('D j M'),
        };

        return $day.', '.$due->format('g:i A');
    }

    /** @return array<int, array<string, mixed>> */
    private function recentLeads(): array
    {
        return Lead::whereIn('status', Lead::OPEN_STATUSES)
            ->latest('created_at')->limit(4)->get()
            ->map(fn (Lead $lead) => [
                'id' => (string) $lead->id,
                'name' => $lead->name,
                'initials' => $lead->initials(),
                'detail' => $lead->detail ?? '',
                'receivedLabel' => $lead->created_at->diffForHumans(short: true),
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function team(Carbon $monthStart): array
    {
        return User::orderBy('id')->get()->map(function (User $user) use ($monthStart) {
            $closed = (int) Deal::won()->where('owner_id', $user->id)
                ->where('closed_at', '>=', $monthStart)->sum('value');

            return [
                'id' => (string) $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'attainment' => $user->monthly_target > 0
                    ? (int) round(($closed / $user->monthly_target) * 100)
                    : 0,
            ];
        })->all();
    }

    /** 52_000_000 -> "52M" */
    private function compact(int $value): string
    {
        return match (true) {
            $value >= 1_000_000_000 => round($value / 1_000_000_000, 1).'B',
            $value >= 1_000_000 => round($value / 1_000_000, 1).'M',
            $value >= 1_000 => round($value / 1_000).'K',
            default => (string) $value,
        };
    }
}
