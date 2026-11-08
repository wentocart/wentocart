<?php

namespace App\Domain\Plugin;

/**
 * Published before/after actions. Apps listen with hook.listen and the
 * matching action.* hooks in app.json. They cannot abort core writes.
 */
final class ActionCatalog
{
    public const NAMES = [
        'catalog.product.save',
        'catalog.product.delete',
        'customer.save',
        'customer.delete',
        'order.create',
        'order.status',
        'checkout.place',
    ];

    /** @return list<string> */
    public static function hooks(): array
    {
        $out = [];
        foreach (self::NAMES as $name) {
            $out[] = 'action.'.$name.'.before';
            $out[] = 'action.'.$name.'.after';
        }

        return $out;
    }

    public static function allows(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}
