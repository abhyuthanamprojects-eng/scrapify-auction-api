<?php
namespace App\Services;
use App\Models\Auction;
use App\Models\BusinessVerification;
use App\Models\EmdTransaction;
use App\Models\Vendor;
final class AuctionEligibilityService
{
    public function evaluate(Auction $auction, Vendor $vendor): array
    {
        $user = $vendor->user; $reasons = [];
        if (! $user || $user->status !== 'active') $reasons[] = 'ACCOUNT_INACTIVE';
        if ($auction->submitted_by && (int)$auction->submitted_by === (int)$user?->id) $reasons[] = 'AUCTION_OWNER_NOT_ALLOWED';
        if (! $vendor->canBid()) $reasons[] = $vendor->status === 'suspended' ? 'ACCOUNT_SUSPENDED' : 'KYC_REQUIRED';
        $kyb = $user ? BusinessVerification::where('user_id', $user->id)->first() : null;
        $kybRequired = GeneralSettings::bool('participant_kyb_required', true);
        if ($kybRequired && $kyb) {
            if ($kyb->overall_kyb_status === 'REVIEW_REQUIRED') $reasons[] = 'KYB_REVIEW_REQUIRED';
            elseif ($kyb->overall_kyb_status === 'REJECTED') $reasons[] = 'KYB_REJECTED';
            elseif ($kyb->overall_kyb_status === 'EXPIRED' || ($kyb->expires_at && $kyb->expires_at->isPast())) $reasons[] = 'KYB_EXPIRED';
            elseif ($kyb->overall_kyb_status !== 'VERIFIED') $reasons[] = 'KYB_REQUIRED';
        }
        $version = $auction->current_terms_version_id;
        if (! $version || ! $auction->termsAcceptances()->where('user_id',$user?->id)->where('terms_version_id',$version)->exists()) $reasons[] = 'TNC_VERSION_OUTDATED';
        $config = $auction->configSnapshot?->config ?? [];
        if (($config['rfq_required'] ?? false) && ! \App\Models\RfqSubmission::where('auction_id',$auction->id)->where('vendor_id',$vendor->id)->where('status','approved')->exists()) $reasons[] = 'RFQ_NOT_APPROVED';
        $emd = EmdTransaction::where('auction_id',$auction->id)->where('vendor_id',$vendor->id)->latest('id')->first();
        if (($config['emd_required'] ?? true) && ! $emd) $reasons[] = 'EMD_NOT_SUBMITTED';
        elseif (($config['emd_required'] ?? true) && $emd->status !== 'locked') $reasons[] = $emd->status === 'pending' ? 'EMD_PENDING' : 'EMD_NOT_VERIFIED';
        return ['eligible'=>empty($reasons),'reasons'=>$reasons,'vendor_id'=>$vendor->id,'emd_status'=>$emd?->status];
    }
    public function eligible(Auction $auction, Vendor $vendor): bool { return $this->evaluate($auction,$vendor)['eligible']; }
    public function count(Auction $auction): int { return (int) EmdTransaction::where('auction_id',$auction->id)->where('status','locked')->distinct('vendor_id')->count('vendor_id'); }
}
