<?php


/**
 * Validação declarativa de body JSON.
 * Sintaxe: 'tipo|required|min:X|max:Y|email|in:a,b,c|regex:/.../|nullable|default:valor'
 */

class PerformaticValidationError extends Exception
{
    public array $errors;

    public function __construct(array $errors)
    {
        parent::__construct('Validação falhou');
        $this->errors = $errors;
    }
}

function validate_body(string $schemaName, bool $throw = true): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (get_schemas() as $s) {
            $cache[$s['name']] = $s;
        }
    }

    if (!isset($cache[$schemaName])) {
        throw new RuntimeException("Schema '$schemaName' não encontrado");
    }

    $schema = $cache[$schemaName];
    $body = body();
    $errors = [];
    $validated = [];

    foreach ($schema['fields'] as $field => $rules) {
        $value = $body[$field] ?? null;
        $result = validate_field($field, $value, $rules, $errors);
        if ($result['has_value']) {
            $validated[$field] = $result['value'];
        }
    }

    if (!empty($errors)) {
        if ($throw) {
            throw new PerformaticValidationError($errors);
        }
        return ['__valid' => false, '__errors' => $errors];
    }

    return $validated;
}

function validate_field(string $field, mixed $value, string $rules, array &$errors): array
{
    $parts = explode('|', $rules);
    $type = $parts[0];
    $constraints = [];
    $required = false;
    $nullable = false;
    $default = null;
    $hasDefault = false;

    foreach (array_slice($parts, 1) as $part) {
        if ($part === 'required') $required = true;
        elseif ($part === 'nullable') $nullable = true;
        elseif (str_starts_with($part, 'default:')) {
            $default = substr($part, 8);
            $hasDefault = true;
        } else {
            $constraints[] = $part;
        }
    }

    // Ausente ou null
    if ($value === null) {
        if ($required) {
            $errors[$field] = 'obrigatório';
            return ['has_value' => false, 'value' => null];
        }
        if ($hasDefault) {
            return ['has_value' => true, 'value' => $default];
        }
        return ['has_value' => false, 'value' => null];
    }

    // Coerção + validação de tipo
    switch ($type) {
        case 'int':
            if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
                $errors[$field] = 'deve ser inteiro';
                return ['has_value' => false, 'value' => null];
            }
            $value = (int)$value;
            break;

        case 'float':
            if (!is_numeric($value)) {
                $errors[$field] = 'deve ser número';
                return ['has_value' => false, 'value' => null];
            }
            $value = (float)$value;
            break;

        case 'bool':
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                $errors[$field] = 'deve ser booleano';
                return ['has_value' => false, 'value' => null];
            }
            $value = $parsed;
            break;

        case 'string':
            if (!is_string($value)) {
                $errors[$field] = 'deve ser string';
                return ['has_value' => false, 'value' => null];
            }
            break;

        case 'array':
            if (!is_array($value)) {
                $errors[$field] = 'deve ser array';
                return ['has_value' => false, 'value' => null];
            }
            break;

        case 'email':
            if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = 'email inválido';
                return ['has_value' => false, 'value' => null];
            }
            break;

        case 'datetime':
            if (!is_string($value) || strtotime($value) === false) {
                $errors[$field] = 'data inválida';
                return ['has_value' => false, 'value' => null];
            }
            break;

        default:
            $errors[$field] = "tipo '$type' desconhecido";
            return ['has_value' => false, 'value' => null];
    }

    // Constraints
    foreach ($constraints as $c) {
        [$name, $arg] = array_pad(explode(':', $c, 2), 2, null);

        switch ($name) {
            case 'min':
                if (is_string($value) && mb_strlen($value) < (int)$arg)
                    $errors[$field] = "mínimo $arg caracteres";
                elseif (is_numeric($value) && $value < (float)$arg)
                    $errors[$field] = "mínimo $arg";
                elseif (is_array($value) && count($value) < (int)$arg)
                    $errors[$field] = "mínimo $arg itens";
                break;

            case 'max':
                if (is_string($value) && mb_strlen($value) > (int)$arg)
                    $errors[$field] = "máximo $arg caracteres";
                elseif (is_numeric($value) && $value > (float)$arg)
                    $errors[$field] = "máximo $arg";
                elseif (is_array($value) && count($value) > (int)$arg)
                    $errors[$field] = "máximo $arg itens";
                break;

            case 'in':
                if (!in_array((string)$value, explode(',', $arg), true))
                    $errors[$field] = "deve ser um de: $arg";
                break;

            case 'regex':
                if (!preg_match($arg, (string)$value))
                    $errors[$field] = "formato inválido";
                break;
        }
    }

    return ['has_value' => true, 'value' => $value];
}
