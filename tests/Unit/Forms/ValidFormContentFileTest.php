<?php

namespace Tests\Unit\Forms;

use App\Modules\Forms\Rules\ValidFormContent;
use PHPUnit\Framework\TestCase;

/**
 * The file input's builder-side config guard (R1-B4). The input is single-file, so there is
 * deliberately no maxFiles; acceptedTypes and maxSize are optional but, when present, must be
 * the right shape so the builder cannot persist config the fill UI would choke on.
 */
class ValidFormContentFileTest extends TestCase
{
    /** @return array<int, string> the validation errors a content array produces */
    private function errorsFor(array $content): array
    {
        $errors = [];
        (new ValidFormContent)->validate('content', $content, function (string $message) use (&$errors) {
            $errors[] = $message;
        });

        return $errors;
    }

    private function fileElement(array $config): array
    {
        return [['id' => 'attachment', 'type' => 'image', 'config' => $config + ['label' => 'Attachment']]];
    }

    public function test_a_bare_file_input_is_valid(): void
    {
        $this->assertSame([], $this->errorsFor($this->fileElement([])));
    }

    public function test_optional_accepted_types_and_max_size_are_valid(): void
    {
        $errors = $this->errorsFor($this->fileElement([
            'acceptedTypes' => ['image/png', 'application/pdf'],
            'maxSize' => 5_000_000,
        ]));

        $this->assertSame([], $errors);
    }

    public function test_accepted_types_must_be_an_array_of_strings(): void
    {
        $this->assertNotEmpty($this->errorsFor($this->fileElement(['acceptedTypes' => 'image/png'])));
        $this->assertNotEmpty($this->errorsFor($this->fileElement(['acceptedTypes' => [123]])));
    }

    public function test_max_size_must_be_a_positive_number(): void
    {
        $this->assertNotEmpty($this->errorsFor($this->fileElement(['maxSize' => 0])));
        $this->assertNotEmpty($this->errorsFor($this->fileElement(['maxSize' => -10])));
        $this->assertNotEmpty($this->errorsFor($this->fileElement(['maxSize' => 'big'])));
    }
}
