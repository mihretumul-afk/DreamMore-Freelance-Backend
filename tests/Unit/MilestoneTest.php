<?php

namespace Tests\Unit;

use App\Models\Milestone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\Concerns\ContractTestHelpers;
use Tests\TestCase;

class MilestoneTest extends TestCase
{
    use RefreshDatabase;
    use ContractTestHelpers;

    public function test_new_milestone_instance_defaults_to_awaiting_funding(): void
    {
        $milestone = new Milestone();

        $this->assertEquals('awaiting_funding', $milestone->status);
    }

    public function test_created_milestone_defaults_to_awaiting_funding_status(): void
    {
        $data = $this->createContract();

        $milestone = Milestone::create([
            'contract_id' => $data['contract']->id,
            'title' => 'Phase 1: UI Design',
            'amount' => 15000,
        ]);

        // The in-memory model carries the default so API resources serialize it.
        $this->assertEquals('awaiting_funding', $milestone->status);

        // The persisted record stores the default explicitly.
        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'awaiting_funding',
        ]);
    }
}
