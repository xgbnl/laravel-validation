<?php

namespace Elephant\Validation\Contacts\Validation;

interface Validatable
{
    public function rules(): array;

    public function hasRuleMethod(string $name): bool;

    public function validatedData(array|int|string|null  $key = null, mixed $default = null): mixed;
}
