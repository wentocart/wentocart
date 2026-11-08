<?php

namespace App\Console\Commands;

use App\Domain\Installer\MillionDataService;
use Illuminate\Console\Command;

class MillionStoreCommand extends Command
{
    protected $signature = 'wento:million-store
        {--products=1000000 : Target SCALE- product rows}
        {--customers=1000000 : Target scale*@scale.load.test customers}
        {--orders=1000000 : Target SC- orders}
        {--reviews=1000000 : Target review rows}
        {--wishlists=1000000 : Target wishlist rows}
        {--chunk=1000 : Rows per INSERT}
        {--force : Skip confirmation}';

    protected $description = 'Insert millions of products, customers, orders, and related rows for admin scale tests.';

    public function handle(MillionDataService $service): int
    {
        $products = max(0, (int) $this->option('products'));
        $customers = max(0, (int) $this->option('customers'));
        $orders = max(0, (int) $this->option('orders'));
        $reviews = max(0, (int) $this->option('reviews'));
        $wishlists = max(0, (int) $this->option('wishlists'));
        $chunk = max(50, min(1000, (int) $this->option('chunk')));

        if (! $this->option('force') && ! $this->confirm('Insert up to '.$products.' products, '.$customers.' customers, and '.$orders.' orders? Existing SCALE rows are kept.')) {
            $this->warn('Cancelled.');

            return self::FAILURE;
        }

        $this->info('Seeding scale dataset. This can take a long time at 1M+ rows.');

        $lastPrinted = -1;
        $result = $service->install($products, $customers, $orders, $reviews, $wishlists, $chunk, function (string $phase, int $done, int $target) use (&$lastPrinted) {
            if ($done !== $target && $done !== 0 && $done % 5000 !== 0 && $done - $lastPrinted < 5000) {
                return;
            }
            $lastPrinted = $done;
            $this->output->writeln(sprintf('  %s %s / %s', $phase, number_format($done), number_format($target)));
        });

        $this->info('Products: '.number_format($result['products']));
        $this->info('Customers: '.number_format($result['customers']));
        $this->info('Orders: '.number_format($result['orders']));
        $this->info('Reviews: '.number_format($result['reviews']));
        $this->info('Wishlists: '.number_format($result['wishlists']));

        return self::SUCCESS;
    }
}
