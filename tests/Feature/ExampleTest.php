<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the dashboard returns a successful response', function (): void {
    $response = $this->get('/');

    $response->assertStatus(200);
});
