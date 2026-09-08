<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class RfqDiscoverySubmission extends Model { protected $guarded=[]; protected $casts=['submitted_value'=>'decimal:2','server_received_at'=>'datetime']; public function round(): BelongsTo{return $this->belongsTo(RfqDiscoveryRound::class,'round_id');} public function vendor(): BelongsTo{return $this->belongsTo(Vendor::class);} }
