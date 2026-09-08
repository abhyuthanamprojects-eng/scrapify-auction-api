<?php
namespace Tests\Feature;
use App\Models\Auction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class RfqSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_rfq_template_contains_auction_and_version_fields(): void
    {
        $auction = Auction::create(['title' => 'Laptop RFQ', 'company' => 'Scrapify', 'status' => 'draft', 'quantity' => '10', 'uom' => 'Nos']);
        $response = $this->get("/api/v1/auctions/{$auction->code}/rfq-template");
        $response->assertOk()->assertHeader('content-type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('TEMPLATE_VERSION: 1', $response->getContent());
        $this->assertStringContainsString("AUCTION_CODE: {$auction->code}", $response->getContent());
    }
}
