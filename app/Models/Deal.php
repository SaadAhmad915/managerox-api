<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title', 'contact_id', 'owner_id', 'lead_id', 'stage', 'value',
    'expected_close_on', 'closed_at', 'lost_at',
])]
class Deal extends Model
{
    use HasFactory;

    /**
     * Pipeline stages in order. The order is meaningful — it drives the
     * dashboard funnel, so keep it earliest to latest.
     */
    public const STAGES = [
        'new' => 'New Leads',
        'qualified' => 'Qualified',
        'proposal' => 'Proposal',
        'negotiation' => 'Negotiation',
        'closed' => 'Closed',
    ];

    /** Stages where the deal is still being worked. */
    public const OPEN_STAGES = ['new', 'qualified', 'proposal', 'negotiation'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'expected_close_on' => 'date',
            'closed_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** Lost deals keep their stage for history but leave the funnel. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lost_at');
    }

    public function scopeWon(Builder $query): Builder
    {
        return $query->where('stage', 'closed')->whereNull('lost_at');
    }

    public function isWon(): bool
    {
        return $this->stage === 'closed' && $this->lost_at === null;
    }
}
