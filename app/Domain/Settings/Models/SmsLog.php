<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['to_phone', 'body', 'status', 'purpose', 'error'])]
class SmsLog extends Model
{
}
