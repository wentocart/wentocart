<?php

namespace App\Domain\Ops;

use Illuminate\Database\Eloquent\Model;

class StaffActivityObserver
{
    public function created(Model $model): void
    {
        app(StaffChangeJournal::class)->observe($model, 'create');
    }

    public function updated(Model $model): void
    {
        app(StaffChangeJournal::class)->observe($model, 'update');
    }

    public function deleted(Model $model): void
    {
        app(StaffChangeJournal::class)->observe($model, 'delete');
    }
}
