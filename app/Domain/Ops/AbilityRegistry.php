<?php

namespace App\Domain\Ops;

use App\Domain\Ops\Abilities\CatalogImportAbility;
use App\Domain\Ops\Abilities\CustomerUpdateAbility;
use App\Domain\Ops\Abilities\OrderStatusAbility;
use App\Domain\Ops\Abilities\ProductBulkPriceAbility;
use App\Domain\Ops\Abilities\ProductBulkStatusAbility;
use App\Domain\Ops\Abilities\ProductUpdateAbility;
use App\Domain\Ops\Abilities\SeoHealthFixAbility;
use App\Domain\Ops\Abilities\SettingsBulkAbility;
use App\Domain\Ops\Abilities\SettingsPutAbility;
use App\Domain\Ops\Abilities\StaffEntityChangeAbility;
use InvalidArgumentException;

final class AbilityRegistry
{
    /** @var array<string, Ability> */
    protected array $abilities = [];

    public function __construct()
    {
        $this->register(new ProductUpdateAbility);
        $this->register(new ProductBulkPriceAbility);
        $this->register(new ProductBulkStatusAbility);
        $this->register(new CatalogImportAbility);
        $this->register(new CustomerUpdateAbility);
        $this->register(new OrderStatusAbility);
        $this->register(new SettingsPutAbility);
        $this->register(new SettingsBulkAbility);
        $this->register(new SeoHealthFixAbility);
        $this->register(new StaffEntityChangeAbility);
    }

    public function register(Ability $ability): void
    {
        $this->abilities[$ability->code()] = $ability;
    }

    public function get(string $code): Ability
    {
        if (! isset($this->abilities[$code])) {
            throw new InvalidArgumentException('Unknown ability: '.$code);
        }

        return $this->abilities[$code];
    }

    /**
     * @return list<Ability>
     */
    public function all(): array
    {
        return array_values($this->abilities);
    }

    /**
     * @return list<array{code: string, label: string, permission: string}>
     */
    public function catalog(): array
    {
        return array_map(fn (Ability $ability) => [
            'code' => $ability->code(),
            'label' => $ability->label(),
            'permission' => $ability->permission(),
        ], $this->all());
    }
}
