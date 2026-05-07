<?php

namespace UniversallyPanel\Panel;

/**
 * Sanitizes values using pipe-delimited rules.
 */
final class Sanitizer
{
    private array $rules = [];

    public function __construct()
    {
        $this->registerBuiltInRules();
    }

    private function registerBuiltInRules(): void
    {
        $this->rules = [
            'text_field' => fn($value, $params) => sanitize_text_field((string) $value),
            'textarea' => fn($value, $params) => sanitize_textarea_field((string) $value),
            'email' => fn($value, $params) => sanitize_email((string) $value),
            'url' => fn($value, $params) => esc_url_raw((string) $value),
            'key' => fn($value, $params) => sanitize_key((string) $value),
            'html' => fn($value, $params) => wp_kses_post((string) $value),
            'int' => fn($value, $params) => (int) $value,
            'float' => fn($value, $params) => (float) $value,
            'bool' => fn($value, $params) => (bool) $value,
            'trim' => fn($value, $params) => is_string($value) ? trim($value) : $value,
            'lowercase' => fn($value, $params) => is_string($value) ? strtolower($value) : $value,
            'uppercase' => fn($value, $params) => is_string($value) ? strtoupper($value) : $value,
        ];

        // Allow plugins to add custom rules
        $this->rules = apply_filters('universally_sanitization_rules', $this->rules);
    }

    /**
     * Sanitize a value using pipe-delimited rules.
     *
     * @param mixed $value The value to sanitize
     * @param string $rulesString Pipe-delimited rules (e.g., "trim|lowercase|email")
     * @return mixed Sanitized value
     */
    public function sanitize($value, string $rulesString)
    {
        if (empty($rulesString)) {
            return $value;
        }

        $rules = $this->parseRules($rulesString);

        foreach ($rules as $ruleName => $params) {
            if (!isset($this->rules[$ruleName])) {
                continue; // Unknown rule, skip
            }

            $value = ($this->rules[$ruleName])($value, $params);
        }

        return $value;
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
                $params = array_map('trim', explode(',', $paramString));
            } else {
                $name = $rule;
                $params = [];
            }

            $parsed[$name] = $params;
        }

        return $parsed;
    }
}
