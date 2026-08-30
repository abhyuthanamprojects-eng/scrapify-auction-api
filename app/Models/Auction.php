<?php

namespace App\Models;

use App\Support\GeneratesCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Auction extends Model
{
    use GeneratesCode;

    protected $guarded = [];

    protected $casts = [
        'submitted_at' => 'datetime',
        'schedule_start' => 'datetime',
        'schedule_end' => 'datetime',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
        'publish_channels' => 'array',
        'reserve_na' => 'boolean',
        'reserve_price' => 'decimal:2',
        'starting_price' => 'decimal:2',
        'bid_increment' => 'decimal:2',
        'emd_amount' => 'decimal:2',
        'current_highest' => 'decimal:2',
        'final_price' => 'decimal:2',
    ];

    protected static int $codePad = 4;

    /** Auction codes carry the year: AUC-2026-0031. */
    protected static function codePrefix(): string
    {
        return 'AUC-'.now()->year.'-';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(AuctionPhoto::class)->orderBy('sort_order');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(AuctionExtension::class);
    }

    public function interestedBidders(): HasMany
    {
        return $this->hasMany(InterestedBidder::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(AccessToken::class);
    }

    public function emdTransactions(): HasMany
    {
        return $this->hasMany(EmdTransaction::class);
    }

    /** Auctions any unauthenticated visitor may see. */
    public function scopePublic(Builder $q): Builder
    {
        return $q->whereIn('status', ['published', 'live', 'closed']);
    }

    public function isLotWise(): bool
    {
        return $this->lot_type === 'lot_wise';
    }

    public function isReverse(): bool
    {
        return $this->direction === 'reverse';
    }

    public function rfxPackages(): HasMany
    {
        return $this->hasMany(RfxPackage::class);
    }

    public function inspectionBookings(): HasMany
    {
        return $this->hasMany(InspectionBooking::class);
    }

    public function clarifications(): HasMany
    {
        return $this->hasMany(Clarification::class);
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(AuctionAddendum::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function awards(): HasMany
    {
        return $this->hasMany(Award::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /** The user account behind the winning vendor, for order ownership. */
    public function winnerVendorUserId(): ?int
    {
        return $this->winner_vendor_id
            ? Vendor::whereKey($this->winner_vendor_id)->value('user_id')
            : null;
    }
}
