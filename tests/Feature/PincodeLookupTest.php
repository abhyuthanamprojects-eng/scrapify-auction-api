<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PincodeLookupTest extends TestCase
{
    public function test_pincode_lookup_returns_normalized_postal_data(): void
    {
        Cache::forget('pincode:110020');
        Http::fake([
            'https://api.postalpincode.in/pincode/110020' => Http::response([[
                'Status' => 'Success',
                'PostOffice' => [[
                    'Name' => 'Okhla Industrial Area Phase-i',
                    'District' => 'South Delhi',
                    'State' => 'Delhi',
                    'Country' => 'India',
                    'BranchType' => 'Sub Post Office',
                    'DeliveryStatus' => 'Delivery',
                ]],
            ]], 200),
        ]);

        $this->getJson('/api/v1/pincode/110020')
            ->assertOk()
            ->assertJsonPath('pincode', '110020')
            ->assertJsonPath('city', 'South Delhi')
            ->assertJsonPath('state', 'Delhi')
            ->assertJsonPath('post_offices.0.name', 'Okhla Industrial Area Phase-i');
    }

    public function test_pincode_provider_connection_failure_is_controlled(): void
    {
        Cache::forget('pincode:110021');
        Http::fake([
            'https://api.postalpincode.in/pincode/110021' => Http::failedConnection('postal provider unavailable'),
        ]);

        $this->getJson('/api/v1/pincode/110021')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'PINCODE_PROVIDER_UNAVAILABLE')
            ->assertHeader('Retry-After', '30');
    }
}
