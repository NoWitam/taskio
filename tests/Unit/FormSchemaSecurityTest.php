<?php

namespace Tests\Unit;

use App\Modules\Forms\Traits\InteractsWithFormSchema;
use Tests\TestCase;

class FormSchemaSecurityTest extends TestCase
{
    use InteractsWithFormSchema;

    public function test_safe_identifier_allows_valid_names(): void
    {
        // Should not throw
        $this->assertSafeIdentifier('name');
        $this->assertSafeIdentifier('field_1');
        $this->assertSafeIdentifier('section_name');
        $this->assertSafeIdentifier('CamelCase');
        $this->assertSafeIdentifier('a');

        $this->assertTrue(true); // Confirm we got here without exception
    }

    public function test_safe_identifier_rejects_sql_injection_attempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->assertSafeIdentifier("name'; DROP TABLE users; --");
    }

    public function test_safe_identifier_rejects_quotes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->assertSafeIdentifier("field'value");
    }

    public function test_safe_identifier_rejects_semicolons(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->assertSafeIdentifier("field;");
    }

    public function test_safe_identifier_rejects_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->assertSafeIdentifier('');
    }

    public function test_safe_identifier_rejects_starting_with_number(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->assertSafeIdentifier('1field');
    }

    public function test_safe_identifier_rejects_spaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->assertSafeIdentifier('field name');
    }

    public function test_sanitize_column_name_validates_result(): void
    {
        // Valid path should work
        $result = $this->sanitizeColumnName('section.field_name');
        $this->assertEquals('section_field_name', $result);
    }

    public function test_sanitize_column_name_rejects_malicious_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sanitizeColumnName("field'; DROP TABLE--");
    }
}
