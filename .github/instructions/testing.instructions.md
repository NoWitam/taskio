---
description: "Use when writing, editing, or reviewing PHP tests. Covers PHPUnit conventions, factories, assertions, and test structure."
applyTo: "tests/**"
---

# Testing Conventions

## Framework

- PHPUnit (not Pest). Test classes extend `Tests\TestCase`.
- Use `Illuminate\Foundation\Testing\RefreshDatabase` trait in every test class.
- Test files live in `tests/Feature/` (integration) and `tests/Unit/` (isolated logic).

## Naming

- Method naming: `test_description_of_behavior` with `public function` and `: void` return type.
- Class naming: `{Feature}Test.php` — e.g., `FormEnableTest`, `FormSubmissionCreateTest`.
- Test what the behavior *is*, not implementation details: `test_can_enable_form_with_input_fields`.

## Test Structure

```php
public function test_behavior_description(): void
{
    // Arrange
    $user = User::factory()->create();
    $form = Form::factory()->create([
        'creator_id' => $user->id,
        ...
    ]);

    // Act
    $response = $this->actingAs($user)
        ->postJson("/api/forms/{$form->id}/enable");

    // Assert
    $response->assertOk()
        ->assertJsonPath('data.is_enabled', true);

    $this->assertNotNull($form->fresh()->enabled_at);
}
```

## Factories

- Located in `database/factories/`.
- Use factory states for common configurations: `->enabled()`, `->disabled()`, `->indexed()`, `->anonymous()`.
- Use Enum cases in factory data (not strings) when the field is cast to an Enum.
- Pass overrides directly: `Form::factory()->create(['creator_id' => $user->id])`.

## Before Writing Tests

1. Check the database schema — know which columns have defaults, which are nullable.
2. Read the Model file — confirm exact relationship method names, not assumed from column names.
3. Check existing factories — use existing states rather than manually overriding fields.

## Assertions

- HTTP status: `->assertOk()`, `->assertCreated()`, `->assertUnprocessable()`, `->assertForbidden()`, `->assertNotFound()`.
- JSON structure: `->assertJsonPath('data.field', value)`, `->assertJsonStructure([...])`.
- Validation errors: `->assertJsonValidationErrors(['field'])`.
- Database state: `$this->assertDatabaseHas('table', [...])`, `$this->assertNotNull($model->fresh()->field)`.

## Coverage Requirements

Every feature should test:
- Happy path (success scenario).
- Authorization (non-owner gets 403).
- Validation (invalid input returns 422 with specific errors).
- Idempotency (repeated action doesn't break anything).
- Edge cases (empty content, boundary values, state transitions).
