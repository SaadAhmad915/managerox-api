<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'email', 'phone', 'detail', 'stage', 'value', 'owner_id', 'closed_at',
    'source', 'external_id', 'form_id', 'page_id', 'payload',
])]
class Lead extends Model
{
    use HasFactory;

    /**
     * Pipeline stages, in order. The order is meaningful: it drives the funnel
     * on the dashboard, so keep it sorted from earliest to latest.
     */
    public const STAGES = [
        'new' => 'New Leads',
        'qualified' => 'Qualified',
        'proposal' => 'Proposal',
        'negotiation' => 'Negotiation',
        'closed' => 'Closed',
    ];

    /** Stages that count as an open deal being actively worked. */
    public const ACTIVE_STAGES = ['qualified', 'proposal', 'negotiation'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'closed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))
            ->filter()
            ->map(fn (string $part) => mb_substr($part, 0, 1))
            ->take(2)
            ->implode('');
    }
}
