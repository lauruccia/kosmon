<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_page_is_publicly_accessible(): void
    {
        $this->get(route('legal.contract'))
            ->assertOk();
    }

    public function test_aml_kyc_page_is_publicly_accessible(): void
    {
        $this->get(route('legal.aml-kyc'))
            ->assertOk();
    }

    public function test_limits_page_is_publicly_accessible(): void
    {
        $this->get(route('legal.limits'))
            ->assertOk();
    }

    public function test_complaints_page_is_publicly_accessible(): void
    {
        $this->get(route('legal.complaints'))
            ->assertOk();
    }

    public function test_privacy_page_is_publicly_accessible(): void
    {
        $this->get(route('legal.privacy'))
            ->assertOk();
    }

    public function test_terms_page_is_publicly_accessible(): void
    {
        $this->get(route('legal.terms'))
            ->assertOk();
    }

    public function test_cookie_policy_page_is_publicly_accessible(): void
    {
        $this->get(route('legal.cookies'))
            ->assertOk();
    }
}
