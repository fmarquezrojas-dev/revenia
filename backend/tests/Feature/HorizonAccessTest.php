<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\DatabaseTestCase;

class HorizonAccessTest extends DatabaseTestCase
{
    public function test_global_queue_dashboard_is_not_public_even_in_local_environment(): void
    {
        $this->app->instance('env', 'local');
        $this->get('/horizon')->assertForbidden();
        $this->getJson('/horizon/api/stats')->assertForbidden();
    }

    public function test_tenant_owner_cannot_read_global_jobs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'web');
        $this->app->instance('env', 'local');
        $this->getJson('/horizon/api/stats')->assertForbidden();
    }
}
