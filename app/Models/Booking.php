<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\Zone;


class Booking extends Model
{
    use HasFactory;

    public const STATUS_ASSIGNED    = 'Assigned';
    public const STATUS_COMPLETED   = 'Completed';
    public const STATUS_PAID        = 'Paid';
    public const STATUS_CASH_DRIVER = 'CashDriver';
    public const STATUS_CANCELLED   = 'Cancelled';

    protected static array $allowedTransitions = [
        self::STATUS_ASSIGNED => [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_COMPLETED => [
            self::STATUS_PAID,
            self::STATUS_CASH_DRIVER,
        ],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'cso_id',
        'driver_id',
        'zone_id',
        'price',
        'status',
    ];

    /**
     * Relasi ke User (CSO)
     */
    public function cso(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cso_id');
    }

    /**
     * Relasi ke User (Driver)
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * Relasi ke Zona Tujuan
     */
    public function zoneTo(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $current = $this->status;

        return isset(self::$allowedTransitions[$current])
            && in_array($newStatus, self::$allowedTransitions[$current], true);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Invalid booking status transition: {$this->status} → {$newStatus}"
            );
        }

        $this->update(['status' => $newStatus]);
    }

    public function canBePaid(): bool
    {
        return in_array($this->status, ['Assigned']);
    }

    public function markAsPaid(): void
    {
        if (! $this->canBePaid()) {
            throw new DomainException(
                "Booking already paid or invalid state ({$this->status})"
            );
        }

        $this->update(['status' => 'Paid']);
    }

    public function transaction()
    {
        return $this->hasOne(Transaction::class);
    }

  
}
