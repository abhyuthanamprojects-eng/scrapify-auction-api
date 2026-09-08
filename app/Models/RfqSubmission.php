<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class RfqSubmission extends Model
{
    protected $guarded = [];
    protected $casts = ['submitted_at'=>'datetime','reviewed_at'=>'datetime','extracted_amount'=>'decimal:2','normalized_amount'=>'decimal:2','verified_rfq_value'=>'decimal:2','quantity'=>'float','unit_amount'=>'decimal:2','calculated_total'=>'decimal:2','confidence'=>'float','extraction_data'=>'array','validation_warnings'=>'array'];
    public function auction(): BelongsTo { return $this->belongsTo(Auction::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
