<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Declarative server-side validation with Laravel's rule names and Laravel 12's default English messages, so ported
 * forms show exactly the same texts as the old site.
 *
 *   $data = Validator::make($request->post, [
 *       'name'  => 'required|string|min:2|max:120',
 *       'email' => ['required', 'email', 'max:190', 'unique:users,email'],
 *       'qty'   => 'required|integer|min:1|max:99',
 *   ], $messages = ['email.unique' => 'Custom text'], $attributes = ['qty' => 'quantity'])->validated();
 *   // throws ValidationException (field => first message), returns only the validated keys.
 *
 * Input handling like Laravel's middleware: strings are trimmed and "" becomes null. A field that is empty and not
 * "required" is skipped (validated value null). Typed output: integer -> int, boolean -> bool, money -> int (whole PKR).
 *
 * Rules: required, nullable, sometimes, string, email, integer (int), numeric, boolean (bool), array, min:n, max:n,
 * between:a,b, size:n (string length / number / array count), in:a,b, not_in:a,b, regex:/x/, not_regex:/x/, confirmed,
 * same:field, different:field, url, date, date_format:F, after_or_equal:field|date, before_or_equal:field|date, digits:n,
 * digits_between:a,b, alpha_dash, lowercase, accepted, unique:table,column[,exceptId[,idColumn]], exists:table,column,
 * money (whole rupees >= 0), pk_phone (Pakistani phone), password_letters, password_numbers, slug, hex (colour), link
 * (a site path "/x" or an http(s) URL).
 */
final class Validator
{
    /** @var array<string,string> field => first error */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $clean = [];

    private const NUMERIC_RULES = ['integer', 'int', 'numeric', 'money'];

    private function __construct(
        private readonly array $input,
        private readonly array $rules,
        private readonly array $messages,
        private readonly array $attributes,
    ) {
        $this->run();
    }

    public static function make(array $input, array $rules, array $messages = [], array $attributes = []): self
    {
        return new self($input, $rules, $messages, $attributes);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        if ($this->errors) {
            throw new ValidationException($this->errors);
        }
        return $this->clean;
    }

    private function label(string $field): string
    {
        return $this->attributes[$field] ?? str_replace('_', ' ', $field);
    }

    private function value(string $field): mixed
    {
        $v = $this->input;
        foreach (explode('.', $field) as $seg) {
            if (!is_array($v) || !array_key_exists($seg, $v)) {
                return null;
            }
            $v = $v[$seg];
        }
        if (is_string($v)) {
            $v = trim($v);
            return $v === '' ? null : $v;
        }
        return $v;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $spec) {
            $rules = is_array($spec) ? $spec : explode('|', (string) $spec);
            $rules = array_values(array_filter(array_map('strval', $rules), static fn ($r) => $r !== ''));
            $names = array_map(static fn ($r) => explode(':', $r, 2)[0], $rules);
            $value = $this->value($field);
            $present = $this->present($field);

            if (in_array('sometimes', $names, true) && !$present) {
                continue;
            }
            $isBool = in_array('boolean', $names, true) || in_array('bool', $names, true);
            $empty = $value === null || $value === [] ;

            if ($empty) {
                if (in_array('required', $names, true)) {
                    $this->fail($field, 'required');
                } elseif (in_array('accepted', $names, true)) {
                    $this->fail($field, 'accepted');
                } else {
                    $this->clean[$field] = $isBool ? false : null;
                }
                continue;
            }

            $numeric = (bool) array_intersect($names, self::NUMERIC_RULES);
            $typed = $value;
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = $this->check($field, $name, $arg, $value, $numeric, $typed);
                if ($error !== null) {
                    $this->fail($field, $name, $error);
                    continue 2;
                }
            }
            $this->clean[$field] = $typed;
        }
    }

    private function present(string $field): bool
    {
        $v = $this->input;
        foreach (explode('.', $field) as $seg) {
            if (!is_array($v) || !array_key_exists($seg, $v)) {
                return false;
            }
            $v = $v[$seg];
        }
        return true;
    }

    private function fail(string $field, string $rule, ?string $default = null): void
    {
        $label = $this->label($field);
        $msg = $this->messages[$field . '.' . $rule] ?? $this->messages[$rule] ?? $default ?? match ($rule) {
            'required' => "The {$label} field is required.",
            'accepted' => "The {$label} field must be accepted.",
            default => "The {$label} field is invalid.",
        };
        $this->errors[$field] = str_replace(':attribute', $label, $msg);
    }

    /** Returns an error message, or null when the rule passes. May convert $typed. */
    private function check(string $field, string $name, ?string $arg, mixed $value, bool $numeric, mixed &$typed): ?string
    {
        $l = $this->label($field);
        $isArray = is_array($value);
        $str = is_scalar($value) ? (string) $value : '';
        if (!$isArray && is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            return "The {$l} field must be a string.";
        }
        switch ($name) {
            case 'required': case 'nullable': case 'sometimes': case 'bail':
                return null;
            case 'string':
                return is_string($value) ? null : "The {$l} field must be a string.";
            case 'array':
                return $isArray ? null : "The {$l} field must be an array.";
            case 'email':
                return !$isArray && filter_var($str, FILTER_VALIDATE_EMAIL) ? null : "The {$l} field must be a valid email address.";
            case 'integer': case 'int':
                if ($isArray || !preg_match('/^-?\d{1,18}$/', $str)) {
                    return "The {$l} field must be an integer.";
                }
                $typed = (int) $str;
                return null;
            case 'numeric':
                return !$isArray && is_numeric($str) ? null : "The {$l} field must be a number.";
            case 'money':
                $m = $isArray ? null : Money::parse($str);
                if ($m === null) {
                    return "The {$l} field must be a whole number of rupees.";
                }
                $typed = $m;
                return null;
            case 'boolean': case 'bool':
                if (!in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true)) {
                    return "The {$l} field must be true or false.";
                }
                $typed = in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
                return null;
            case 'accepted':
                return in_array($value, ['yes', 'on', '1', 1, true, 'true'], true) ? null : "The {$l} field must be accepted.";
            case 'min':
                return $this->sizeOf($value, $numeric) < (float) $arg ? $this->sizeMessage($l, 'at least', $arg, $value, $numeric) : null;
            case 'max':
                return $this->sizeOf($value, $numeric) > (float) $arg ? $this->sizeMessage($l, 'not be greater than', $arg, $value, $numeric) : null;
            case 'size':
                if ($this->sizeOf($value, $numeric) != (float) $arg) {
                    return $isArray ? "The {$l} field must contain {$arg} items." : ($numeric ? "The {$l} field must be {$arg}." : "The {$l} field must be {$arg} characters.");
                }
                return null;
            case 'between':
                [$a, $b] = array_map('trim', explode(',', (string) $arg) + [1 => '0']);
                $s = $this->sizeOf($value, $numeric);
                if ($s < (float) $a || $s > (float) $b) {
                    return $isArray ? "The {$l} field must have between {$a} and {$b} items." : ($numeric ? "The {$l} field must be between {$a} and {$b}." : "The {$l} field must be between {$a} and {$b} characters.");
                }
                return null;
            case 'in':
                return !$isArray && in_array($str, explode(',', (string) $arg), true) ? null : "The selected {$l} is invalid.";
            case 'not_in':
                return !$isArray && !in_array($str, explode(',', (string) $arg), true) ? null : "The selected {$l} is invalid.";
            case 'regex':
                return !$isArray && preg_match((string) $arg, $str) ? null : "The {$l} field format is invalid.";
            case 'not_regex':
                return !$isArray && !preg_match((string) $arg, $str) ? null : "The {$l} field format is invalid.";
            case 'confirmed':
                return $this->value($field . '_confirmation') === $value ? null : "The {$l} field confirmation does not match.";
            case 'same':
                return $this->value((string) $arg) === $value ? null : "The {$l} field must match " . $this->label((string) $arg) . '.';
            case 'different':
                return $this->value((string) $arg) !== $value ? null : "The {$l} field and " . $this->label((string) $arg) . ' must be different.';
            case 'url':
                return !$isArray && filter_var($str, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $str) ? null : "The {$l} field must be a valid URL.";
            case 'link':
                return !$isArray && (preg_match('#^/(?!/)\S*$#', $str) || (filter_var($str, FILTER_VALIDATE_URL) && preg_match('#^https?://\S+$#i', $str))) ? null : "The {$l} field must be a site path (starting with /) or a full http(s) address.";
            case 'date':
                return !$isArray && strtotime($str) !== false ? null : "The {$l} field must be a valid date.";
            case 'date_format':
                $d = \DateTime::createFromFormat((string) $arg, $str);
                return $d && $d->format((string) $arg) === $str ? null : "The {$l} field must match the format {$arg}.";
            case 'after_or_equal': case 'before_or_equal':
                $other = $this->value((string) $arg);
                $otherTime = strtotime((string) ($other ?? $arg));
                $mine = strtotime($str);
                if ($otherTime === false || $other === null && $this->present((string) $arg)) {
                    return null;                               // nothing to compare with (the other field is empty)
                }
                $ok = $mine !== false && ($name === 'after_or_equal' ? $mine >= $otherTime : $mine <= $otherTime);
                $what = $this->present((string) $arg) || isset($this->rules[(string) $arg]) ? $this->label((string) $arg) : (string) $arg;
                return $ok ? null : "The {$l} field must be a date " . ($name === 'after_or_equal' ? 'after' : 'before') . " or equal to {$what}.";
            case 'digits':
                return !$isArray && preg_match('/^\d+$/', $str) && strlen($str) === (int) $arg ? null : "The {$l} field must be {$arg} digits.";
            case 'digits_between':
                [$a, $b] = array_map('intval', explode(',', (string) $arg) + [1 => 0]);
                return !$isArray && preg_match('/^\d+$/', $str) && strlen($str) >= $a && strlen($str) <= $b ? null : "The {$l} field must be between {$a} and {$b} digits.";
            case 'alpha_dash':
                return !$isArray && preg_match('/^[\pL\pM\pN_-]+$/u', $str) ? null : "The {$l} field must only contain letters, numbers, dashes, and underscores.";
            case 'lowercase':
                return mb_strtolower($str) === $str ? null : "The {$l} field must be lowercase.";
            case 'slug':
                return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $str) ? null : "The {$l} field must only contain lowercase letters, numbers and dashes.";
            case 'hex':
                return preg_match('/^#[0-9a-fA-F]{6}$/', $str) ? null : "The {$l} field must be a colour like #101010.";
            case 'pk_phone':
                return self::pakistaniPhone($str) ? null : 'Enter a valid Pakistani phone number, for example 0300 1234567 or +92 300 1234567.';
            case 'password_letters':
                return preg_match('/\pL/u', $str) ? null : "The {$l} field must contain at least one letter.";
            case 'password_numbers':
                return preg_match('/\pN/u', $str) ? null : "The {$l} field must contain at least one number.";
            case 'unique':
                $p = array_map('trim', explode(',', (string) $arg));
                [$table, $column] = [$p[0] ?? '', $p[1] ?? $field];
                self::assertIdent($table, $column, $p[3] ?? 'id');
                $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?";
                $params = [$str];
                if (isset($p[2]) && $p[2] !== '' && strtoupper($p[2]) !== 'NULL') {
                    $sql .= ' AND `' . ($p[3] ?? 'id') . '` <> ?';
                    $params[] = $p[2];
                }
                return (int) Database::get()->value($sql, $params) === 0 ? null : "The {$l} has already been taken.";
            case 'exists':
                $p = array_map('trim', explode(',', (string) $arg));
                [$table, $column] = [$p[0] ?? '', $p[1] ?? $field];
                self::assertIdent($table, $column);
                return (int) Database::get()->value("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?", [$str]) > 0 ? null : "The selected {$l} is invalid.";
            default:
                throw new \LogicException("Unknown validation rule: {$name}");
        }
    }

    private function sizeOf(mixed $value, bool $numeric): float
    {
        if (is_array($value)) {
            return count($value);
        }
        if ($numeric && is_numeric($value)) {
            return (float) $value;
        }
        return mb_strlen((string) $value);
    }

    private function sizeMessage(string $l, string $op, ?string $arg, mixed $value, bool $numeric): string
    {
        $prefix = "The {$l} field must " . ($op === 'at least' ? 'be at least' : 'not be greater than') . " {$arg}";
        if (is_array($value)) {
            return $op === 'at least' ? "The {$l} field must have at least {$arg} items." : "The {$l} field must not have more than {$arg} items.";
        }
        return $numeric ? $prefix . '.' : $prefix . ' characters.';
    }

    private static function assertIdent(string ...$names): void
    {
        foreach ($names as $n) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $n)) {
                throw new \LogicException("Invalid identifier in validation rule: {$n}");
            }
        }
    }

    /**
     * Pakistani numbers (port of the old App\Rules\PakistaniPhone): mobile 03XX XXXXXXX (also +92 / 0092 / 92 3XX...)
     * or a landline with area code (0 + 2-3 digit area code + 6-8 digit number, 10-11 digits in total).
     */
    public static function pakistaniPhone(mixed $value): bool
    {
        $d = self::normalizePhone($value);
        if ($d === null) {
            return false;
        }
        if (str_starts_with($d, '03')) {
            return preg_match('/^03\d{9}$/', $d) === 1;
        }
        return preg_match('/^0[2-9]\d{8,9}$/', $d) === 1;
    }

    /** Strip spaces, dashes and brackets, and turn +92 / 0092 / 92 into the local leading 0. Digits only, or null. */
    public static function normalizePhone(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $d = (string) preg_replace('/[\s\-().]/', '', $value);
        if (!preg_match('/^\+?\d+$/', $d)) {
            return null;
        }
        $d = ltrim($d, '+');
        if (str_starts_with($d, '0092')) {
            $d = '0' . substr($d, 4);
        } elseif (str_starts_with($d, '92')) {
            $d = '0' . substr($d, 2);
        }
        return $d;
    }
}
