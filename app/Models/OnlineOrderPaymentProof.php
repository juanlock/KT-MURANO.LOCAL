<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof of an offline payment (GCash / bank transfer) submitted by the
 * shopper. Approving one is what flips the order's payment_status to paid.
 */
class OnlineOrderPaymentProof extends Model
{
    protected $table = 'online_order_payment_proofs';

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'order_id', 'payment_method', 'reference_number', 'amount', 'paid_at',
        'file_path', 'note', 'status', 'reviewed_by', 'reviewed_at', 'reject_reason',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'amount' => 'decimal:2',
        'paid_at' => 'date',
        'reviewed_at' => 'datetime',
        'reviewed_by' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class, 'order_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Public URL of the uploaded file, or null when nothing was attached. */
    public function fileUrl(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        // New: 'files/company_{id}/payment_proofs/...'; legacy: a bare name in images/payment_proofs.
        return url(str_contains($this->file_path, '/') ? '/'.ltrim($this->file_path, '/') : '/images/payment_proofs/'.$this->file_path);
    }
}
