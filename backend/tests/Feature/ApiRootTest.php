<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiRootTest extends TestCase
{
    public function test_the_root_address_points_to_the_api(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJsonPath('name', config('app.name'))
            ->assertJsonPath('api', url('/api/v1'));
    }
}
