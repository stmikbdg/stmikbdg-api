<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_redirects_unauthenticated_visitors_to_login(): void
    {
        $this->get('/')->assertRedirect();
    }
}
