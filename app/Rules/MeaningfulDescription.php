<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Requires a real, meaningful description - at least 2 complete sentences
 * and a minimum word count - so an amenity can never be saved with an
 * empty, one-word, or placeholder description (System Administrator
 * Amenities module requirement). "Complete sentence" is approximated by
 * counting sentence-ending punctuation (./!/?) followed by whitespace or
 * end of string; this is a heuristic, not a grammar checker, but reliably
 * rejects the failure cases that actually matter here (blank text, a
 * single word, a sentence fragment with no punctuation at all).
 */
class MeaningfulDescription implements ValidationRule
{
    private const MIN_WORDS = 12;
    private const MIN_SENTENCES = 2;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = trim((string) $value);

        if ($text === '') {
            $fail('The :attribute is required and must explain what the amenity provides.');

            return;
        }

        if (str_word_count($text) < self::MIN_WORDS) {
            $fail('The :attribute must be a real description (at least 2-3 complete sentences), not a single word or placeholder text.');

            return;
        }

        $sentenceCount = preg_match_all('/[.!?](\s|$)/', $text);
        if ($sentenceCount < self::MIN_SENTENCES) {
            $fail('The :attribute must contain at least 2-3 complete sentences explaining what the amenity provides, what the guest can expect, and any conditions or limitations.');
        }
    }
}
