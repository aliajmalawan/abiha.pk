<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** Admin → Settings shows the brand logo and the favicon as two separate cards, each with its own preview, upload and Save button. */
class BrandingCardsTest extends TestCase
{
    use BuildsUsers, RefreshDatabase;

    public function test_logo_and_favicon_are_two_separate_cards(): void
    {
        $this->seedRoles();
        $html = $this->actingAs($this->userWithRole('admin'))->get(route('admin.settings.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('id="g-brand-logo"', $html);
        $this->assertStringContainsString('id="g-favicon-icon"', $html);
        $this->assertStringContainsString('name="s__site__logo"', $html);
        $this->assertStringContainsString('name="s__site__favicon"', $html);
        $this->assertStringContainsString('Save brand logo', $html);
        $this->assertStringContainsString('Save favicon icon', $html);
        $this->assertStringContainsString('Default favicon', $html, 'the favicon card previews the default icon when none is uploaded');
    }
}
