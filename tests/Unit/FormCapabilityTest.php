<?php

namespace Tests\Unit;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Traits\InteractsWithFormSchema;
use Tests\TestCase;

class FormCapabilityTest extends TestCase
{
    public function test_enabled_form_can_be_assigned(): void
    {
        $form = new Form(['enabled_at' => now()]);

        $this->assertTrue($form->canBeAssigned());
    }

    public function test_disabled_form_cannot_be_assigned(): void
    {
        $form = new Form(['enabled_at' => null]);

        $this->assertFalse($form->canBeAssigned());
    }

    public function test_disabled_form_is_draft(): void
    {
        $form = new Form(['enabled_at' => null]);

        $this->assertTrue($form->isDraft());
    }

    public function test_enabled_form_is_not_draft(): void
    {
        $form = new Form(['enabled_at' => now()]);

        $this->assertFalse($form->isDraft());
    }

    public function test_form_always_can_be_edited(): void
    {
        $enabled = new Form(['enabled_at' => now()]);
        $disabled = new Form(['enabled_at' => null]);

        $this->assertTrue($enabled->canBeEdited());
        $this->assertTrue($disabled->canBeEdited());
    }

    public function test_only_enabled_form_can_be_filled(): void
    {
        $enabled = new Form(['enabled_at' => now()]);
        $disabled = new Form(['enabled_at' => null]);

        $this->assertTrue($enabled->canBeFilled());
        $this->assertFalse($disabled->canBeFilled());
    }

    public function test_anonymous_form_cannot_be_disabled(): void
    {
        $form = new Form([
            'enabled_at' => now(),
            'is_anonymous' => true,
        ]);

        $this->assertFalse($form->canBeDisabled());
    }

    public function test_disabled_indexed_is_valid_combination(): void
    {
        $form = new Form([
            'enabled_at' => null,
            'indexed_at' => now(),
        ]);

        $this->assertTrue($form->isDisabled());
        $this->assertTrue($form->isIndexed());
    }

    public function test_indexing_form_cannot_be_indexed_again(): void
    {
        $form = new Form([
            'enabled_at' => now(),
            'indexed_at' => null,
            'indexing_started_at' => now(),
        ]);

        $this->assertFalse($form->canBeIndexed());
        $this->assertTrue($form->isIndexing());
    }

    public function test_unindexed_form_has_basic_reporting_mode(): void
    {
        $form = new Form(['indexed_at' => null]);

        $this->assertEquals('basic', $form->getReportingMode());
    }

    public function test_indexed_form_has_advanced_reporting_mode(): void
    {
        $form = new Form(['indexed_at' => now()]);

        $this->assertEquals('advanced', $form->getReportingMode());
    }

    public function test_indexed_form_has_advanced_filters(): void
    {
        $form = new Form(['indexed_at' => now()]);

        $filters = $form->getAvailableFilters();

        $this->assertContains('field_values', $filters);
        $this->assertContains('advanced_search', $filters);
        $this->assertContains('aggregate_stats', $filters);
    }

    public function test_unindexed_form_has_only_basic_filters(): void
    {
        $form = new Form(['indexed_at' => null]);

        $filters = $form->getAvailableFilters();

        $this->assertNotContains('field_values', $filters);
        $this->assertNotContains('advanced_search', $filters);
    }
}
