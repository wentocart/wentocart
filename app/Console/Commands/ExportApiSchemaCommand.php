<?php

namespace App\Console\Commands;

use App\Domain\Api\Graphql\AdminSchema;
use App\Domain\Api\Graphql\ShopSchema;
use App\Domain\Api\OpenApi\AdminOpenApiDocument;
use App\Domain\Api\OpenApi\ShopOpenApiDocument;
use GraphQL\Utils\SchemaPrinter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 */
class ExportApiSchemaCommand extends Command
{
    protected $signature = 'wento:export-api-schema
        {--path= : Output directory (default: storage/app/api-schemas)}';

    protected $description = 'Export Shop/Admin OpenAPI 3 JSON and GraphQL SDL for Postman/codegen (export-schema parity).';

    public function handle(): int
    {
        $dir = (string) ($this->option('path') ?: storage_path('app/api-schemas'));
        File::ensureDirectoryExists($dir);

        $base = rtrim((string) config('app.url', 'http://localhost'), '/');

        $shopOpenApi = ShopOpenApiDocument::build($base);
        $adminOpenApi = AdminOpenApiDocument::build($base);
        $shopSdl = SchemaPrinter::doPrint(ShopSchema::build());
        $adminSdl = SchemaPrinter::doPrint(AdminSchema::build());

        $files = [
            'shop-openapi.json' => json_encode($shopOpenApi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            'admin-openapi.json' => json_encode($adminOpenApi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            'shop.graphql' => $shopSdl.(str_ends_with($shopSdl, "\n") ? '' : "\n"),
            'admin.graphql' => $adminSdl.(str_ends_with($adminSdl, "\n") ? '' : "\n"),
        ];

        foreach ($files as $name => $contents) {
            $full = $dir.DIRECTORY_SEPARATOR.$name;
            File::put($full, $contents);
            $this->line('Wrote '.$full);
        }

        $collections = [
            'wento-shop.postman_collection.json',
            'wento-admin.postman_collection.json',
            'wento.postman_environment.json',
        ];
        foreach ($collections as $name) {
            $src = base_path('api-collections/'.$name);
            if (! is_file($src)) {
                continue;
            }
            $dest = $dir.DIRECTORY_SEPARATOR.$name;
            File::copy($src, $dest);
            $this->line('Wrote '.$dest);
        }

        $this->info('Exported '.count($files).' schema file(s) (+ Postman collections) to '.$dir);

        return self::SUCCESS;
    }
}
