<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductQuestion extends Model
{
    protected $table = 'product_questions';

    public const STATUSES = ['pending', 'published', 'rejected'];

    protected $fillable = [
        'product_id', 'client_id', 'asker_name',
        'question', 'answer', 'answered_by', 'answered_at', 'status',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'client_id' => 'integer',
        'answered_by' => 'integer',
        'answered_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /** Questions everyone may read on the product page. */
    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published');
    }

    public function isAnswered(): bool
    {
        return trim((string) $this->answer) !== '';
    }
}
