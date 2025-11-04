<?php

declare(strict_types=1);

namespace Elephant\Validation\Validation;

use Exception;
use Elephant\Validation\Contacts\Validation\Scene\SceneValidatable;
use Elephant\Validation\Contacts\Validation\Validatable;
use Elephant\Validation\Contacts\Validation\ValidateWhenScene;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator as ValidatorContacts;
use function data_get;

abstract class Validator extends FormRequest implements Validatable, ValidateWhenScene
{
    use ValidateWhenSceneTrait;

    protected readonly SceneValidatable $scene;

    private bool $autoValidate;

    public function __construct(
        SceneValidatable $scene,
        array            $query = [],
        array            $request = [],
        array            $attributes = [],
        array            $cookies = [],
        array            $files = [],
        array            $server = [],
        mixed            $content = null,
        bool             $autoValidate = false
    ) {

        $this->autoValidate = $autoValidate;

        $this->scene = $scene;

        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);
    }

    public function validatedData(array|int|string|null  $key = null, mixed $default = null): mixed
    {
        try {
            $validated = $this->resolveValidator()->validated();
        } catch (ValidationException $validationException) {
            $this->failedValidationException($validationException);
        }

        return data_get($validated, $key, $default);
    }

    final public function validateResolved(): void
    {
        if ($this->autoValidate) {
            $this->resolveValidator();
        }
    }

    private function resolveValidator(): ValidatorContacts
    {
        try {
            if (!$this->passesAuthorization()) {
                $this->failedAuthorization();
            }

            $instance = $this->getValidatorInstance();

            if ($instance->fails()) {
                $this->failedValidation($instance);
            }
        } catch (ValidationException $validationException) {
            $this->failedValidationException($validationException);
        } catch (AuthorizationException $e) {
            $this->failedValidationException($e);
        }

        return $instance;
    }

    private function failedValidationException(Exception $exception): never
    {
        throw new \Elephant\Validation\Exception\ValidationException(
            message: $exception instanceof ValidationException
                ? $exception->validator->errors()->first()
                : $exception->getMessage(),
            previous: $exception
        );
    }

    abstract public function rules(): array;
}
