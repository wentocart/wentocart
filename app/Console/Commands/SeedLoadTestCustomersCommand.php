<?php

namespace App\Console\Commands;

use App\Domain\Customer\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class SeedLoadTestCustomersCommand extends Command
{
    protected $signature = 'customers:seed-load-test
                            {--count=1000000 : How many customers to create (max 1000000)}
                            {--fresh : Delete previous load-test customers first}';

    protected $description = 'Create a large set of customers with photos for admin list/edit load testing';

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $count = min(1000000, max(1, (int) $this->option('count')));

        if ($this->option('fresh')) {
            $removed = Customer::query()->where('email', 'like', 'loadtest.%@example.test')->delete();
            $this->info("Removed {$removed} previous load-test customers.");
        }

        $dir = storage_path('app/public/customers/loadtest');
        File::ensureDirectoryExists($dir);
        $avatarCount = $this->makeAvatars($dir);

        $password = Hash::make('password');
        $groups = ['retailer', 'wholesale', 'vip'];
        $genders = ['male', 'female', 'other'];
        $firsts = ['Aarav', 'Priya', 'Rahul', 'Ananya', 'Vikram', 'Neha', 'Rohan', 'Isha', 'Arjun', 'Meera', 'John', 'Maria', 'David', 'Sara', 'James'];
        $lasts = ['Sharma', 'Patel', 'Singh', 'Reddy', 'Khan', 'Gupta', 'Iyer', 'Nair', 'Das', 'Joshi', 'Smith', 'Garcia', 'Chen', 'Kumar', 'Brown'];
        $now = now()->format('Y-m-d H:i:s');
        $batchSize = 1000;
        $batch = [];
        $created = 0;
        $offset = (int) Customer::query()->where('email', 'like', 'loadtest.%@example.test')->count();
        $firstCount = count($firsts);
        $lastCount = count($lasts);

        $this->info("Inserting {$count} customers (photos reused from {$avatarCount} avatars)...");
        $this->output->progressStart($count);

        for ($i = 1; $i <= $count; $i++) {
            $n = $offset + $i;
            $first = $firsts[$n % $firstCount];
            $last = $lasts[$n % $lastCount];
            $batch[] = [
                'name' => $first.' '.$last,
                'first_name' => $first,
                'last_name' => $last,
                'email' => 'loadtest.'.$n.'@example.test',
                'phone' => '+91 9'.str_pad((string) ($n % 100000000), 8, '0', STR_PAD_LEFT),
                'password' => $password,
                'is_enabled' => ($n % 8) !== 0,
                'customer_group' => $groups[$n % 3],
                'gender' => $genders[$n % 3],
                'newsletter' => ($n % 5) !== 0,
                'notes' => ($n % 7) === 0 ? 'Load-test customer notes.' : null,
                'avatar_path' => 'customers/loadtest/avatar-'.(($n % $avatarCount) + 1).'.png',
                'remember_token' => sprintf('%010d', $n),
                'email_verified_at' => $now,
                'created_at' => date('Y-m-d H:i:s', time() - (($n % 400) * 86400)),
                'updated_at' => $now,
            ];

            if (count($batch) === $batchSize || $i === $count) {
                Customer::query()->insert($batch);
                $created += count($batch);
                $this->output->progressAdvance(count($batch));
                $batch = [];
            }
        }

        $this->output->progressFinish();
        $this->info("Created {$created} customers with photos. Total load-test rows: ".($offset + $created));

        return self::SUCCESS;
    }

    protected function makeAvatars(string $dir): int
    {
        $total = 48;
        $colors = [
            [107, 70, 254], [5, 150, 105], [37, 99, 235], [217, 119, 6],
            [219, 39, 119], [13, 148, 136], [79, 70, 229], [185, 28, 28],
        ];

        for ($i = 1; $i <= $total; $i++) {
            $path = $dir.'/avatar-'.$i.'.png';
            if (is_file($path)) {
                continue;
            }
            [$r, $g, $b] = $colors[($i - 1) % count($colors)];
            $im = imagecreatetruecolor(96, 96);
            imagefilledrectangle($im, 0, 0, 96, 96, imagecolorallocate($im, $r, $g, $b));
            $white = imagecolorallocate($im, 255, 255, 255);
            imagestring($im, 5, 34, 40, strtoupper(chr(64 + (($i - 1) % 26) + 1)), $white);
            imagepng($im, $path);
            imagedestroy($im);
        }

        return $total;
    }
}
