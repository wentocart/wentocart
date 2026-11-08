<?php

namespace App\Domain\Ops;

use Illuminate\Database\Eloquent\Model;

class EntityObserver
{
    public function created(Model $model): void
    {
        $this->fire($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->fire($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->fire($model, 'deleted');
    }

    protected function fire(Model $model, string $action): void
    {
        $name = WebhookCatalog::eventFor($model::class, $action);
        if ($name === null) {
            return;
        }
        CommerceTopics::notify($name, WebhookPayload::record($model));
    }
}
