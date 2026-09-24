<?php
// Source: anonymized production Laravel project
use Tests\TestCase;

final class ExampleFeatureTest extends TestCase
{
    public function test_example(): void { $this->get('/')->assertOk(); }
}
