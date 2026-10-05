<?php

namespace App\Models;

use App\Observers\BookingObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::observe(BookingObserver::class);
    }

    protected $dates = ['booking_date', 'deleted_at'];

    protected $fillable = [
        'Ref',
        'customer_id',
        'product_id',
        'tray_id',
        // Location of the appointment (and of its invoice).
        'warehouse_id',
        // Appointment mode (Settings -> Booking -> Appointment mode):
        // office | remote (meeting_link) | onsite (site_address).
        'mode',
        'meeting_link',
        'site_address',
        'sales_person_id',
        'product_name',
        'product_description',
        'price',
        'booking_date',
        'booking_time',
        'booking_end_time',
        'delivery_date',
        'delivery_time',
        'delivery_address',
        'amount',
        'status',
        'notes',
        'google_calendar_event_id',
    ];

    // sale_id (the booking's sale invoice) is set only by BookingSaleService,
    // never mass-assigned from the form.

    protected $casts = [
        'customer_id' => 'integer',
        'product_id' => 'integer',
        'tray_id' => 'integer',
        'sales_person_id' => 'integer',
        'sale_id' => 'integer',
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Client::class, 'customer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function tray()
    {
        return $this->belongsTo(Tray::class, 'tray_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function salesPerson()
    {
        return $this->belongsTo(User::class, 'sales_person_id');
    }
}












