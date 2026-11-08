<?php

namespace App\Domain\Plugin\Support;

use Illuminate\Validation\ValidationException;

/**
 * Reusable validation feedback for hosted extension forms (and any core caller).
 * Errors render in red; submitted values stay on the form instead of reverting to saved settings.
 */
final class FormFeedback
{
    /**
     * @param  array<int|string, mixed>  $errors  Field map, list of strings, or ValidationException::errors()
     * @return array{messages: list<string>, fields: array<string, string>}
     */
    public static function normalize(array $errors): array
    {
        $messages = [];
        $fields = [];

        foreach ($errors as $key => $value) {
            $texts = self::messageList($value);
            if ($texts === []) {
                continue;
            }
            $first = $texts[0];
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_]*$/i', $key)) {
                $name = strtolower($key);
                $fields[$name] = $first;
            }
            foreach ($texts as $text) {
                $messages[] = $text;
            }
        }

        return [
            'messages' => array_values(array_unique($messages)),
            'fields' => $fields,
        ];
    }

    /**
     * Attach errors + typed values onto a page document before sanitize().
     *
     * @param  array<string, mixed>  $document
     * @param  array<int|string, mixed>|ValidationException  $errors
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>
     */
    public static function apply(array $document, array|ValidationException $errors, ?array $values = null, string $placement = 'top'): array
    {
        if ($errors instanceof ValidationException) {
            $errors = $errors->errors();
        }
        $bag = self::normalize($errors);
        $document['errors'] = $bag['messages'];
        $document['field_errors'] = $bag['fields'];
        $document['error_placement'] = $placement === 'bottom' ? 'bottom' : 'top';
        if (is_array($values)) {
            $document['values'] = $values;
        }

        return $document;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function messageList(mixed $value): array
    {
        if (is_string($value) || is_numeric($value)) {
            $text = trim((string) $value);

            return $text !== '' ? [$text] : [];
        }
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) || is_numeric($item)) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }

        return $out;
    }
}
