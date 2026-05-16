<?php

namespace UniversallyPanel\Panel;

/**
 * Validates values using Laravel-style pipe-delimited rules.
 */
final class Validator
{
    private array $rules = [];
    private array $errors = [];
    private string $attribute = '';
    /** @var mixed */
    private $value = null;

    public function __construct()
    {
        $this->registerBuiltInRules();
    }

    private function registerBuiltInRules(): void
    {
        $this->rules = [
            'required' => function ($value, $params) {
                if ($value === null || $value === '' || $value === []) {
                    return __('This field is required', 'universally-language-translation-multilingual-tool');
                }
                return true;
            },
            'nullable' => function ($value, $params) {
                return true; // Handled specially in validate()
            },
            'string' => function ($value, $params) {
                if ($value !== null && $value !== '' && !is_string($value)) {
                    return __('Must be a string', 'universally-language-translation-multilingual-tool');
                }
                return true;
            },
            'numeric' => function ($value, $params) {
                if ($value !== null && $value !== '' && !is_numeric($value)) {
                    return __('Must be a number', 'universally-language-translation-multilingual-tool');
                }
                return true;
            },
            'email' => function ($value, $params) {
                if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return __('Must be a valid email address', 'universally-language-translation-multilingual-tool');
                }
                return true;
            },
            'url' => function ($value, $params) {
                if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                    return __('Must be a valid URL', 'universally-language-translation-multilingual-tool');
                }
                return true;
            },
            'min' => function ($value, $params) {
                $min = (int) ($params[0] ?? 0);
                if ($value !== null && $value !== '' && strlen((string) $value) < $min) {
                    /* translators: %s: minimum number of characters */
                    return sprintf(__('Must be at least %s characters', 'universally-language-translation-multilingual-tool'), $params[0] ?? '0');
                }
                return true;
            },
            'max' => function ($value, $params) {
                $max = (int) ($params[0] ?? 0);
                if ($value !== null && $value !== '' && strlen((string) $value) > $max) {
                    /* translators: %s: maximum number of characters */
                    return sprintf(__('Must not exceed %s characters', 'universally-language-translation-multilingual-tool'), $params[0] ?? '0');
                }
                return true;
            },
            'in' => function ($value, $params) {
                if ($value !== null && $value !== '' && !in_array($value, $params, true)) {
                    /* translators: %s: list of allowed values */
                    return sprintf(__('Must be one of: %s', 'universally-language-translation-multilingual-tool'), implode(', ', $params));
                }
                return true;
            },
            'regex' => function ($value, $params) {
                $pattern = $params[0] ?? '';
                if ($value !== null && $value !== '' && !empty($pattern)) {
                    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional: silences "invalid pattern" warning when validating a user-supplied regex.
                    $result = @preg_match($pattern, (string) $value);
                    if ($result === false || $result === 0) {
                        return __('Invalid format', 'universally-language-translation-multilingual-tool');
                    }
                }
                return true;
            },
        ];

        // Allow plugins to add custom rules
        $this->rules = apply_filters('universally_validation_rules', $this->rules);
    }

    /**
     * Validate a value against rules.
     *
     * @param mixed $value The value to validate
     * @param string $rulesString Pipe-delimited rules (e.g., "required|email|max:255")
     * @param string $attribute Field label for error messages
     * @return bool|\WP_Error True if valid, WP_Error if invalid
     */
    public function validate($value, string $rulesString, string $attribute = 'field')
    {
        $this->attribute = $attribute;
        $this->value = $value;
        $this->errors = [];

        if (empty($rulesString)) {
            return true;
        }

        $rules = $this->parseRules($rulesString);

        // Check for nullable - if value is empty and nullable, skip other rules
        $isNullable = isset($rules['nullable']);
        $isEmpty = $value === null || $value === '' || $value === [];

        if ($isNullable && $isEmpty) {
            return true;
        }

        foreach ($rules as $ruleName => $params) {
            if ($ruleName === 'nullable') {
                continue;
            }

            if (!isset($this->rules[$ruleName])) {
                continue; // Unknown rule, skip
            }

            $result = ($this->rules[$ruleName])($value, $params);

            if ($result !== true) {
                $message = $this->replacePlaceholders($result, $params);
                return new \WP_Error('validation_failed', $message);
            }
        }

        return true;
    }

    /**
     * Parse pipe-delimited rules string into array.
     */
    private function parseRules(string $rulesString): array
    {
        $parsed = [];
        $rules = explode('|', $rulesString);

        foreach ($rules as $rule) {
            $rule = trim($rule);
            if (empty($rule)) {
                continue;
            }

            if (strpos($rule, ':') !== false) {
                [$name, $paramString] = explode(':', $rule, 2);
                // Handle regex specially (may contain colons)
                if ($name === 'regex') {
                    $params = [$paramString];
                } else {
                    $params = array_map('trim', explode(',', $paramString));
                }
            } else {
                $name = $rule;
                $params = [];
            }

            $parsed[$name] = $params;
        }

        return $parsed;
    }

    /**
     * Replace placeholders in error message.
     */
    private function replacePlaceholders(string $message, array $params): string
    {
        $replacements = [
            ':attribute' => $this->attribute,
            ':value' => is_scalar($this->value) ? (string) $this->value : '',
            ':params' => implode(', ', $params),
        ];

        // Add individual param placeholders
        foreach ($params as $i => $param) {
            $replacements[':param' . ($i + 1)] = $param;
        }

        return strtr($message, $replacements);
    }
}
