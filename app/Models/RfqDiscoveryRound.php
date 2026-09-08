<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class RfqDiscoveryRound extends Model { protected $guarded=[]; protected $casts=['start_at'=>'datetime','end_at'=>'datetime','closed_at'=>'datetime']; public function auction(): BelongsTo{return $this->belongsTo(Auction::class);} public function submissions(): HasMany{return $this->hasMany(RfqDiscoverySubmission::class,'round_id');} }
