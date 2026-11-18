<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator report: what users were charged, what Pixelforge charged us, and the profit, per day and per user.
 *
 * Days are UTC days on which the generation *completed*, the same way Pixelforge bills, so our provider
 * cost per day can be compared one-to-one with GET /v1/usage.
 */
class AdminReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $end = Carbon::parse($validated['end'] ?? now('UTC')->toDateString(), 'UTC')->endOfDay();
        $start = Carbon::parse($validated['start'] ?? $end->copy()->subDays(29)->toDateString(), 'UTC')->startOfDay();

        if ($start->diffInDays($end) > 92) {
            $start = $end->copy()->subDays(91)->startOfDay(); // Pixelforge's usage endpoint allows at most 92 days.
        }

        $settled = Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$start, $end]);

        $ours = (clone $settled)
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as generations, SUM(charged_nanos) as charged, SUM(provider_cost_nanos) as cost')
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        [$reported, $reportError] = $this->providerUsage($pixelforge, $start, $end);

        $days = [];
        for ($day = $end->copy()->startOfDay(); $day->gte($start); $day->subDay()) {
            $date = $day->toDateString();
            $row = $ours[$date] ?? null;
            $days[] = $this->row([
                'date' => $date,
                'generations' => (int) ($row->generations ?? 0),
                'charged' => (int) ($row->charged ?? 0),
                'cost' => (int) ($row->cost ?? 0),
            ], $reported[$date] ?? null);
        }

        $users = (clone $settled)
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->selectRaw('users.id, users.name, users.email, users.balance_nanos, COUNT(*) as generations, SUM(charged_nanos) as charged, SUM(provider_cost_nanos) as cost')
            ->groupBy('users.id', 'users.name', 'users.email', 'users.balance_nanos')
            ->orderByDesc('charged')
            ->get()
            ->map(fn ($u) => $this->row([
                'name' => $u->name,
                'email' => $u->email,
                'balance' => (int) $u->balance_nanos,
                'generations' => (int) $u->generations,
                'charged' => (int) $u->charged,
                'cost' => (int) $u->cost,
            ]));

        $total = $this->row([
            'generations' => array_sum(array_column($days, 'generations')),
            'charged' => array_sum(array_column($days, 'charged')),
            'cost' => array_sum(array_column($days, 'cost')),
        ], $reported === null ? null : [
            'generations' => array_sum(array_column($reported, 'generations')),
            'cost' => array_sum(array_column($reported, 'cost')),
        ]);

        return Inertia::render('Admin/Report', [
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'days' => $days,
            'users' => $users,
            'total' => $total,
            'reportError' => $reportError,
            'failedCount' => Generation::query()->where('status', GenerationStatus::Failed)
                ->whereBetween('created_at', [$start, $end])->count(),
        ]);
    }

    /** @return array{0: array<string, array{generations:int,cost:int}>|null, 1: string|null} */
    private function providerUsage(PixelforgeClient $pixelforge, Carbon $start, Carbon $end): array
    {
        try {
            $usage = Cache::remember("pixelforge.usage.{$start->toDateString()}.{$end->toDateString()}", 60,
                fn () => $pixelforge->usage($start->toDateString(), $end->toDateString()));
        } catch (PixelforgeException $e) {
            return [null, 'Could not load Pixelforge usage: '.$e->getMessage()];
        }

        $byDay = [];
        foreach ($usage['data'] as $day) {
            $byDay[$day['date']] = ['generations' => (int) $day['generations'], 'cost' => Money::parse($day['cost_usd'])];
        }

        return [$byDay, null];
    }

    private function row(array $row, ?array $reported = null): array
    {
        return [
            ...$row,
            'profit' => $row['charged'] - $row['cost'],
            'reported_cost' => $reported['cost'] ?? null,
            'reported_generations' => $reported['generations'] ?? null,
            'matches' => $reported === null ? null
                : $reported['cost'] === $row['cost'] && $reported['generations'] === $row['generations'],
        ];
    }
}
