<?php

namespace Tests\Unit;

use App\Support\SiteUrl;
use PHPUnit\Framework\TestCase;

class SiteUrlTest extends TestCase
{
    public function test_trailing_slash_variants_match(): void
    {
        $this->assertTrue(SiteUrl::matches('HTTPS://Example.COM/app', 'https://example.com/app/'));
        $this->assertTrue(SiteUrl::matches('https://example.com', 'https://example.com/'));
    }

    public function test_prefix_suffix_and_different_semantics_do_not_match(): void
    {
        $allowed = 'https://example.com/app';

        $this->assertFalse(SiteUrl::matches($allowed, 'https://example.com/app.evil'));
        $this->assertFalse(SiteUrl::matches($allowed, 'https://evil.example.com/app'));
        $this->assertFalse(SiteUrl::matches($allowed, 'https://example.com/app/extra'));
        $this->assertFalse(SiteUrl::matches($allowed, 'https://example.com:8443/app'));
        $this->assertFalse(SiteUrl::matches($allowed.'?role=user', $allowed.'?role=admin'));
    }
}
