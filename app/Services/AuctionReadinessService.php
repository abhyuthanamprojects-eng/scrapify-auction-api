<?php
namespace App\Services;
use App\Models\Auction;
final class AuctionReadinessService
{
    public function check(Auction $auction): array
    {
        $minimum=(int)$auction->frozenConfig('minimum_participants',GeneralSettings::int('minimum_participants',3));
        $eligible=app(AuctionEligibilityService::class)->count($auction);
        $checks=['status'=>in_array($auction->status,['published','approved'],true),'config_snapshot'=>(bool)$auction->config_snapshot_id,'terms_version'=>(bool)$auction->current_terms_version_id,'terms_snapshot_match'=>(bool)($auction->currentTermsVersion?->rules['config_snapshot_id'] ?? null) && (($auction->currentTermsVersion?->rules['config_snapshot_id'] ?? null) === $auction->config_snapshot_id),'rfq_finalized'=>!($auction->frozenConfig('rfq_required',false)) || (bool)$auction->final_rfq_value,'minimum_participants'=>$eligible >= $minimum,'schedule'=>(bool)$auction->schedule_start,'not_cancelled'=>$auction->status !== 'cancelled'];
        $reasons=[]; foreach($checks as $key=>$ok) if(!$ok) $reasons[] = strtoupper($key);
        return ['ready'=>empty($reasons),'checks'=>$checks,'reasons'=>$reasons,'eligible_participants'=>$eligible,'minimum_participants'=>$minimum];
    }
}
