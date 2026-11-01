<?php

namespace App\Rules;

use App\Models\Country;
use App\Support\PhoneNumbers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valid phone number for the given country, or in international format when no country is known.
 */
class PhoneNumber implements ValidationRule
{
    public function __construct(private readonly ?Country $country) {}

    public static function forCountryId(mixed $countryId): self
    {
        return new self(is_numeric($countryId) ? Country::query()->find((int) $countryId) : null);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $error = $this->error($value);
        if ($error !== null) {
            $fail($error);
        }
    }

    /** The validation message for $value (with an :attribute placeholder), or null when it is valid. */
    public function error(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return 'The :attribute must be a phone number.';
        }

        if (PhoneNumbers::toE164((string) $value, $this->country?->iso2) !== null) {
            return null;
        }

        return $this->country
            ? "The :attribute is not a valid {$this->country->name} phone number."
            : 'The :attribute must be a valid phone number in international format, e.g. +92 300 1234567.';
    }
}
