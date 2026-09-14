<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An enquiry. Once qualified it is converted exactly once, producing a Contact
 * (the person) and a Deal (the opportunity). The lead is kept afterwards as the
 * record of where that business came from.
 */
#[Fillable([
    'name', 'email', 'phone', 'detail', 'status', 'owner_id', 'converted_at',
    'source', 'external_id', 'form_id', 'page_id', 'payload',
])]
class Lead extends Model
{
    use HasFactory;

    public const STATUSES = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'unqualified' => 'Unqualified',
        'converted' => 'Converted',
    ];

    /** Statuses where the lead still needs working. */
    public const OPEN_STATUSES = ['new', 'contacted', 'qualified'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'converted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasOne<Contact, $this> */
    public function contact(): HasOne
    {
        return $this->hasOne(Contact::class);
    }

    /** @return HasOne<Deal, $this> */
    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class);
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
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
