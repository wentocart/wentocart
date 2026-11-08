<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\CompatibilityChecker;
use App\Domain\Plugin\DependencyResolver;
use App\Domain\Plugin\LocalExtensionCatalog;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\PluginInstaller;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Plugin\PageDocument;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\PluginRegistry;
use App\Domain\Plugin\Support\ExtensionUpload;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Store\Models\PluginStore;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppController extends Controller
{
    public function __construct(
        protected PluginRegistry $registry,
        protected PluginInstaller $installer,
        protected PluginKernel $kernel,
        protected CompatibilityChecker $compatibility,
        protected DependencyResolver $dependencies,
    ) {}

    public function index(): Response
    {
        $this->kernel->boot();
        $labels = Capabilities::labels();
        $installed = $this->dependencies->installedMap();
        $rows = InstalledPlugin::query()->orderBy('vendor')->orderBy('name')->get()->map(function (InstalledPlugin $row) use ($labels, $installed) {
            $manifest = $this->registry->find($row->vendor, $row->name);

            return $this->present($row, $manifest, $labels, $installed);
        });

        return Inertia::render('Apps/Index', [
            'apps' => $rows,
            'catalog' => app(LocalExtensionCatalog::class)->available(),
        ]);
    }

    public function installCatalog(string $vendor, string $name): RedirectResponse
    {
        try {
            $manifest = $this->installer->installFromSource($vendor, $name);
            PluginKernel::reset();

            return back()->with([
                'success' => $manifest->displayName.' is installed and enabled.',
                'toast' => true,
            ]);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Failed to install extension.';

            return back()->with([
                'error' => $msg,
                'toast' => true,
            ]);
        } catch (\Throwable $e) {
            return back()->with([
                'error' => 'Installation failed: '.$e->getMessage(),
                'toast' => true,
            ]);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $request->validate([
                'zip' => ['required', 'file', 'mimes:zip', 'max:10240'],
            ]);
            $result = $this->installer->receiveUpload($request->file('zip'));
            $manifest = $result['manifest'];
            PluginKernel::reset();

            if ($result['action'] === 'staged') {
                return back()->with([
                    'success' => $manifest->displayName.' v'.$result['pending_version'].' is ready. Click Upgrade on the extension to apply it. Settings will be kept.',
                    'toast' => true,
                ]);
            }
            if ($result['action'] === 'noop') {
                return back()->with([
                    'success' => $manifest->displayName.' is already installed (v'.$manifest->version.'). Nothing to change.',
                    'toast' => true,
                ]);
            }

            return back()->with([
                'success' => $manifest->displayName.' is installed and enabled.',
                'toast' => true,
            ]);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Failed to install extension.';

            return back()->with([
                'error' => $msg,
                'toast' => true,
            ]);
        } catch (\Throwable $e) {
            return back()->with([
                'error' => 'Installation failed: '.$e->getMessage(),
                'toast' => true,
            ]);
        }
    }

    public function upgrade(string $vendor, string $name): RedirectResponse
    {
        try {
            $manifest = $this->installer->applyStagedUpgrade($vendor, $name);
            PluginKernel::reset();

            return back()->with([
                'success' => $manifest->displayName.' was upgraded to v'.$manifest->version.'. Settings were kept.',
                'toast' => true,
            ]);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Failed to upgrade extension.';

            return back()->with([
                'error' => $msg,
                'toast' => true,
            ]);
        } catch (\Throwable $e) {
            return back()->with([
                'error' => 'Upgrade failed: '.$e->getMessage(),
                'toast' => true,
            ]);
        }
    }

    public function reset(string $vendor, string $name): RedirectResponse
    {
        try {
            $manifest = $this->installer->reset($vendor, $name);
            PluginKernel::reset();

            return back()->with([
                'success' => $manifest->displayName.' was reset and reinstalled (settings cleared).',
                'toast' => true,
            ]);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Failed to reset extension.';

            return back()->with([
                'error' => $msg,
                'toast' => true,
            ]);
        } catch (\Throwable $e) {
            return back()->with([
                'error' => 'Reset failed: '.$e->getMessage(),
                'toast' => true,
            ]);
        }
    }

    public function enable(Request $request, string $vendor, string $name): RedirectResponse
    {
        try {
            $row = InstalledPlugin::query()->where('vendor', $vendor)->where('name', $name)->firstOrFail();
            $enabled = $request->boolean('enabled');
            $manifest = $this->registry->find($vendor, $name);

            if ($enabled) {
                if (! $manifest) {
                    throw ValidationException::withMessages(['app' => 'Extension files are missing.']);
                }
                $compat = $this->compatibility->check($manifest);
                if (! $compat['ok']) {
                    throw ValidationException::withMessages(['app' => $compat['message']]);
                }
                $this->dependencies->assertEnableable($manifest);
            } else {
                $this->dependencies->assertRemovable($vendor, $name);
            }

            $row->update(['is_enabled' => $enabled]);
            \App\Domain\Plugin\Support\ExtensionRuntime::syncMethods($row, $enabled);
            $storeId = app(StoreContext::class)->id();
            if ($storeId) {
                PluginStore::query()->updateOrCreate(
                    ['store_id' => $storeId, 'plugin' => $row->idKey()],
                    ['is_enabled' => $enabled]
                );
            }
            PluginKernel::reset();

            return back()->with([
                'success' => $enabled
                    ? $row->display_name.' is enabled on this shop.'
                    : $row->display_name.' is disabled.',
                'toast' => true,
            ]);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Failed to update extension status.';

            return back()->with([
                'error' => $msg,
                'toast' => true,
            ]);
        } catch (\Throwable $e) {
            return back()->with([
                'error' => 'Failed to update extension: '.$e->getMessage(),
                'toast' => true,
            ]);
        }
    }

    public function show(string $vendor, string $name): Response|\Illuminate\Http\RedirectResponse
    {
        $this->kernel->boot();
        $row = InstalledPlugin::query()->with('settings')->where('vendor', $vendor)->where('name', $name)->firstOrFail();
        $manifest = $this->registry->find($vendor, $name);
        $plugin = $this->kernel->instance($vendor, $name);
        $schema = $plugin?->adminSchema() ?? ['title' => $row->display_name, 'fields' => []];
        $schema['fields'] = PageDocument::fields($schema['fields'] ?? []);
        $schema['tabs'] = PageDocument::tabs($schema['tabs'] ?? []);
        $schema['nav'] = PageDocument::nav($schema['nav'] ?? []);
        $schema['sidebar_actions'] = PageDocument::sidebarActions($schema['sidebar_actions'] ?? []);
        // Business modules (admin.pages) with no Host settings form → open their real Configure page.
        $fields = PageDocument::collectFields($schema);
        if ($fields === []) {
            $moduleHref = ModuleBuses::primaryAdminHref($vendor, $name);
            if (is_string($moduleHref) && $moduleHref !== '') {
                return redirect($moduleHref);
            }
        }
        $values = [];
        foreach ($row->settings as $setting) {
            $decoded = json_decode((string) $setting->value, true);
            $values[$setting->key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $setting->value;
        }
        foreach (PageDocument::collectFields($schema) as $field) {
            $key = $field['name'] ?? null;
            if (is_string($key) && ! array_key_exists($key, $values) && ($field['type'] ?? '') === 'toggle') {
                $values[$key] = true;
            }
            if (is_string($key) && ! array_key_exists($key, $values) && array_key_exists('default', $field)) {
                $values[$key] = $field['default'];
            }
            if (is_string($key) && ($field['type'] ?? '') === 'checkboxes') {
                $values[$key] = PageDocument::sanitizeSettingValue($field, $values[$key] ?? ($field['default'] ?? []));
            }
            if (is_string($key) && PageDocument::isLocalized($field)) {
                $values[$key] = PageDocument::localizedMap($values[$key] ?? ($field['default'] ?? []));
            }
            if (is_string($key) && ExtensionUpload::isField($field)) {
                $values[$key] = ExtensionUpload::present($values[$key] ?? [], $vendor, $name);
            }
            if (is_string($key) && ($field['type'] ?? '') === 'password') {
                $stored = (string) ($row->settings->firstWhere('key', $key)?->value ?? '');
                $decoded = json_decode($stored, true);
                $values[$key] = json_last_error() === JSON_ERROR_NONE && is_string($decoded) ? $decoded : $stored;
            }
        }
        $masked = [];
        foreach (PageDocument::collectFields($schema) as $field) {
            if (($field['type'] ?? '') !== 'password') {
                continue;
            }
            $key = $field['name'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $stored = $row->settings->firstWhere('key', $key)?->value;
            $masked[$key] = is_string($stored) && trim($stored) !== '' && trim($stored) !== '""';
        }

        return Inertia::render('Apps/Host', [
            'app' => [
                'vendor' => $row->vendor,
                'name' => $row->name,
                'display_name' => $manifest?->displayName ?: $row->display_name,
                'enabled' => $row->is_enabled,
                'type' => $row->type,
                'description' => ($manifest?->description !== null && $manifest->description !== '')
                    ? $manifest->description
                    : $row->description,
                'setup_steps' => $manifest?->setupSteps ?? [],
                'health_status' => $row->health_status,
                'last_error' => $row->last_error,
            ],
            'schema' => $schema,
            'values' => $values,
            'masked' => $masked,
        ]);
    }

    public function update(Request $request, string $vendor, string $name): RedirectResponse
    {
        $this->kernel->boot();
        $row = InstalledPlugin::query()->where('vendor', $vendor)->where('name', $name)->firstOrFail();
        $plugin = $this->kernel->instance($vendor, $name);
        $schema = $plugin?->adminSchema() ?? ['fields' => []];
        $fieldsByName = [];
        $passwordKeys = [];
        foreach (PageDocument::collectFields($schema) as $field) {
            $key = $field['name'] ?? null;
            if (! is_string($key) || $key === '' || ($field['type'] ?? '') === 'readonly') {
                continue;
            }
            $fieldsByName[$key] = $field;
            if (($field['type'] ?? '') === 'password') {
                $passwordKeys[] = $key;
            }
        }
        if (! $request->has('values') || ! is_array($request->input('values'))) {
            $json = $request->json('values');
            if (is_array($json)) {
                $request->merge(['values' => $json]);
            }
        }
        $data = $request->validate([
            'values' => ['required', 'array'],
        ]);
        $incoming = is_array($data['values']) ? $data['values'] : [];
        $current = [];
        foreach ($fieldsByName as $key => $field) {
            if (ExtensionUpload::isField($field)) {
                $current[$key] = $plugin?->context()->settings()->get($key, []);
            }
        }
        $incoming = ExtensionUpload::mergeIncoming($vendor, $name, array_values($fieldsByName), $incoming, $request, $current);
        foreach ($passwordKeys as $key) {
            if (! array_key_exists($key, $incoming) || trim((string) $incoming[$key]) === '') {
                unset($incoming[$key]);
            }
        }
        $filtered = PageDocument::sanitizeSettingsPayload(array_values($fieldsByName), $incoming);
        try {
            $plugin?->saveSettings($filtered);
        } catch (\Throwable $e) {
            if (is_object($e) && property_exists($e, 'errors') && is_array($e->errors) && $e->errors !== []) {
                throw ValidationException::withMessages($e->errors);
            }
            throw $e;
        }

        return back()->with([
            'success' => $row->display_name.' settings saved.',
            'toast' => true,
        ]);
    }

    public function destroy(string $vendor, string $name): RedirectResponse
    {
        try {
            $this->installer->uninstall($vendor, $name);
            PluginKernel::reset();

            return redirect('/admin/extensions')->with([
                'success' => 'Extension uninstalled. Files remain on disk so you can install it again.',
                'toast' => true,
            ]);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Failed to uninstall extension.';

            return back()->with([
                'error' => $msg,
                'toast' => true,
            ]);
        } catch (\Throwable $e) {
            return back()->with([
                'error' => 'Uninstall failed: '.$e->getMessage(),
                'toast' => true,
            ]);
        }
    }

    public function delete(string $vendor, string $name): RedirectResponse
    {
        try {
            $this->installer->deleteFromDisk($vendor, $name);
            PluginKernel::reset();

            return redirect('/admin/extensions')->with([
                'success' => 'Extension deleted from this shop.',
                'toast' => true,
            ]);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Failed to delete extension.';

            return back()->with([
                'error' => $msg,
                'toast' => true,
            ]);
        } catch (\Throwable $e) {
            return back()->with([
                'error' => 'Delete failed: '.$e->getMessage(),
                'toast' => true,
            ]);
        }
    }

    /**
     * Settings forms live on the extension host. Module pages are only the
     * Configure target when the extension has no settings fields (for example
     * a dedicated configure screen). Download and capture actions must not win,
     * or the button starts a file download and the page never changes.
     */
    protected function configureHref(InstalledPlugin $row): string
    {
        $settingsHref = '/admin/extensions/'.$row->vendor.'/'.$row->name;
        $plugin = $this->kernel->instance($row->vendor, $row->name);
        $schema = [];
        if ($plugin) {
            try {
                $schema = $plugin->adminSchema();
            } catch (\Throwable) {
                $schema = [];
            }
        }
        if (PageDocument::collectFields(is_array($schema) ? $schema : []) !== []) {
            return $settingsHref;
        }

        $moduleHref = ModuleBuses::primaryAdminHref($row->vendor, $row->name);

        return is_string($moduleHref) && $moduleHref !== '' ? $moduleHref : $settingsHref;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, array{version: string, enabled: bool}>  $installed
     * @return array<string, mixed>
     */
    protected function present(InstalledPlugin $row, ?PluginManifest $manifest, array $labels, array $installed): array
    {
        $compat = $manifest ? $this->compatibility->check($manifest) : ['ok' => false, 'message' => 'Extension files are missing.'];
        $depErrors = [];
        if ($manifest) {
            $depErrors = $row->is_enabled
                ? $this->dependencies->validateForEnable($manifest, $installed)
                : $this->dependencies->validateForInstallOrUpdate($manifest, $installed);
        }

        $dependencyStatus = 'ok';
        if ($depErrors !== []) {
            $dependencyStatus = 'error';
        } elseif ($manifest && $manifest->requires !== []) {
            $dependencyStatus = 'ok';
        }

        $id = $row->idKey();
        $pending = $this->installer->pendingStage($row->vendor, $row->name);
        $pendingVersion = $pending['version'] ?? null;
        $canUpgrade = is_string($pendingVersion) && version_compare($pendingVersion, (string) $row->version, '>');

        return [
            'vendor' => $row->vendor,
            'name' => $row->name,
            'id' => $id,
            'display_name' => $manifest?->displayName ?: $row->display_name,
            'version' => $manifest?->version ?: $row->version,
            'author' => $manifest?->author ?: $row->author,
            // Prefer live app.json so merchant-facing copy updates without a reinstall.
            'description' => $manifest?->description !== null && $manifest->description !== ''
                ? $manifest->description
                : $row->description,
            'type' => $row->type,
            'category' => $manifest?->category,
            'enabled' => $row->is_enabled,
            'bundled' => false,
            'capabilities' => collect($row->capabilities ?? [])->map(fn ($cap) => [
                'id' => $cap,
                'label' => $labels[$cap] ?? $cap,
            ])->values()->all(),
            'hooks' => $row->hooks ?? [],
            'requires' => $manifest?->requires ?? [],
            'conflicts' => $manifest?->conflicts ?? [],
            'setup_steps' => $manifest?->setupSteps ?? [],
            'compatibility' => [
                'ok' => $compat['ok'],
                'message' => $compat['message'],
                'min_app_version' => $manifest?->minAppVersion,
                'max_app_version' => $manifest?->maxAppVersion,
            ],
            'dependencies' => [
                'status' => $dependencyStatus,
                'errors' => $depErrors,
            ],
            'health_status' => $row->health_status ?: ($compat['ok'] && $depErrors === [] ? 'ok' : null),
            'last_error' => $row->last_error,
            'href' => $this->configureHref($row),
            'can_delete' => true,
            'can_uninstall' => true,
            'can_upgrade' => $canUpgrade,
            'pending_upgrade_version' => $canUpgrade ? $pendingVersion : null,
            'can_reset' => true,
            'preview' => $manifest?->previewPublicUrl(),
        ];
    }
}
