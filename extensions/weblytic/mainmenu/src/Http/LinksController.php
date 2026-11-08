<?php

namespace Modules\Weblytic\Mainmenu\Http;

use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;
use Modules\Weblytic\Mainmenu\Support\CatalogData;
use Modules\Weblytic\Mainmenu\Support\LinkStore;
use Modules\Weblytic\Mainmenu\Support\MenuConfig;

class LinksController extends ModuleController
{
    public function index(ModuleRequest $request): array
    {
        if ($request->string('new') === '1') {
            return $this->formPage(null, [
                'id' => $this->linkStore()->nextId(),
                'label' => '',
                'url' => '/',
                'new_window' => false,
                'enabled' => true,
            ]);
        }

        $id = trim($request->string('id'));
        if ($id !== '') {
            $link = $this->linkStore()->find($id);
            if (! $link) {
                return $this->invalid($this->listPage(), ['Custom link was not found.']);
            }

            return $this->formPage($id, $link);
        }

        return $this->listPage();
    }

    public function store(ModuleRequest $request): array
    {
        $bulk = $this->selectedList($request);
        if ($bulk['action'] !== '') {
            if ($bulk['ids'] === []) {
                return $this->invalid($this->listPage(), ['Select at least one link.']);
            }
            if ($bulk['action'] === 'delete') {
                $this->linkStore()->delete($bulk['ids']);
                $this->detachMenuItems($bulk['ids']);
            }
            if ($bulk['action'] === 'enable') {
                $this->setLinksEnabled($bulk['ids'], true);
            }
            if ($bulk['action'] === 'disable') {
                $this->setLinksEnabled($bulk['ids'], false);
            }

            return $this->listPage();
        }

        $values = $request->all()['values'] ?? [];
        if (! is_array($values)) {
            $values = [];
        }

        $id = trim((string) ($values['id'] ?? $request->string('id')));
        $url = trim((string) ($values['url'] ?? ''));
        $labels = $this->collectLabels($values);
        $label = trim((string) ($values['label'] ?? ''));
        if ($label === '' && $labels !== []) {
            $defaultLocale = $this->defaultLocale();
            $label = (string) ($labels[$defaultLocale] ?? reset($labels) ?: '');
        }
        $errors = [];
        if ($id === '') {
            $errors['id'] = 'Reference code is required.';
        }
        if ($label === '') {
            $errors['labels'] = 'Menu text is required for at least one language.';
        }
        if ($url === '') {
            $errors['url'] = 'Destination is required.';
        }
        if ($errors !== []) {
            return $this->invalid($this->formPage($id !== '' ? $id : null, $values), $errors, $values);
        }

        $saved = $this->linkStore()->save([
            'id' => $id,
            'label' => $label,
            'url' => $url,
            'labels' => $labels,
            'new_window' => ! empty($values['new_window']),
            'enabled' => array_key_exists('enabled', $values) ? (bool) $values['enabled'] : true,
        ]);
        if ($saved === null) {
            return $this->invalid($this->formPage($id, $values), [
                'url' => 'Enter a shop path like /help, or a full https:// address.',
            ], $values);
        }

        return $this->listPage();
    }

    /**
     * @return array<string, mixed>
     */
    protected function listPage(): array
    {
        $rows = [];
        foreach ($this->linkStore()->all() as $link) {
            $rows[] = [
                'id' => (string) $link['id'],
                'label' => (string) $link['label'],
                'url' => (string) $link['url'],
                'enabled' => ! empty($link['enabled']) ? 'Yes' : 'No',
                'window' => ! empty($link['new_window']) ? 'New' : 'Same',
                'href' => '/admin/modules/weblytic/mainmenu/links?id='.rawurlencode((string) $link['id']),
            ];
        }

        return $this->page([
            'title' => 'Custom links',
            'title_key' => 'module.weblytic.mainmenu.links.title',
            'hint' => '',
            'list' => [
                'selectable' => true,
                'columns' => [
                    ['key' => 'id', 'label' => 'Code', 'label_key' => 'module.weblytic.mainmenu.links.col.id'],
                    ['key' => 'label', 'label' => 'Menu text', 'label_key' => 'module.weblytic.mainmenu.links.col.label'],
                    ['key' => 'url', 'label' => 'Goes to', 'label_key' => 'module.weblytic.mainmenu.links.col.url'],
                    ['key' => 'enabled', 'label' => 'Shown', 'label_key' => 'module.weblytic.mainmenu.links.col.enabled'],
                    ['key' => 'window', 'label' => 'Opens', 'label_key' => 'module.weblytic.mainmenu.links.col.window'],
                ],
                'rows' => $rows,
                'bulk' => [
                    ['key' => 'enable', 'label' => 'Enable', 'label_key' => 'module.weblytic.mainmenu.links.bulk.enable'],
                    ['key' => 'disable', 'label' => 'Disable', 'label_key' => 'module.weblytic.mainmenu.links.bulk.disable'],
                    ['key' => 'delete', 'label' => 'Delete', 'label_key' => 'module.weblytic.mainmenu.links.bulk.delete', 'icon' => 'trash'],
                ],
                'empty' => 'No custom links yet.',
                'empty_key' => 'module.weblytic.mainmenu.links.empty',
                'delete_confirm' => 'Delete the selected custom links?',
                'delete_confirm_key' => 'module.weblytic.mainmenu.links.delete_confirm',
                'create' => [
                    'href' => '/admin/modules/weblytic/mainmenu/links?new=1',
                    'label' => 'Add link',
                    'label_key' => 'module.weblytic.mainmenu.links.create',
                    'icon' => 'plus',
                ],
            ],
            'blocks' => [
                [
                    'type' => 'link',
                    'href' => '/admin/modules/weblytic/mainmenu/configure',
                    'label' => 'Back to Configure',
                    'label_key' => 'module.weblytic.mainmenu.links.back_configure',
                    'icon' => 'arrow-left',
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function formPage(?string $id, array $values): array
    {
        $labelValues = is_array($values['labels'] ?? null) ? $values['labels'] : [];
        if ($labelValues === [] && trim((string) ($values['label'] ?? '')) !== '') {
            $labelValues[$this->defaultLocale()] = trim((string) $values['label']);
        }
        $values['labels'] = $labelValues;

        $fields = [
            [
                'name' => 'id',
                'type' => $id ? 'readonly' : 'text',
                'inline' => true,
                'label' => 'Reference code',
                'label_key' => 'module.weblytic.mainmenu.links.field.id',
                'hint' => 'Internal name for this link (letters, numbers, - _). Shoppers never see this.',
                'hint_key' => 'module.weblytic.mainmenu.links.field.id_hint',
            ],
            [
                'name' => 'labels',
                'type' => 'text',
                'localized' => true,
                'inline' => true,
                'label' => 'Menu text',
                'label_key' => 'module.weblytic.mainmenu.links.field.label',
                'hint' => 'What shoppers see in the header. Switch language to set translations.',
                'hint_key' => 'module.weblytic.mainmenu.links.field.label_hint',
            ],
            [
                'name' => 'url',
                'type' => 'text',
                'inline' => true,
                'label' => 'Goes to',
                'label_key' => 'module.weblytic.mainmenu.links.field.url',
                'hint' => 'Shop page path (/help) or full website address (https://…).',
                'hint_key' => 'module.weblytic.mainmenu.links.field.url_hint',
            ],
            [
                'name' => 'new_window',
                'type' => 'toggle',
                'inline' => true,
                'label' => 'Open in a new tab',
                'label_key' => 'module.weblytic.mainmenu.links.field.new_window',
            ],
            [
                'name' => 'enabled',
                'type' => 'toggle',
                'inline' => true,
                'label' => 'Show this link',
                'label_key' => 'module.weblytic.mainmenu.links.field.enabled',
            ],
        ];

        return $this->page([
            'title' => $id ? 'Edit custom link' : 'Add custom link',
            'title_key' => $id ? 'module.weblytic.mainmenu.links.form.edit_title' : 'module.weblytic.mainmenu.links.form.add_title',
            'layout' => 'config',
            'form_title' => 'Link details',
            'form_title_key' => 'module.weblytic.mainmenu.links.form_title',
            'save_label' => 'Save link',
            'save_label_key' => 'module.weblytic.mainmenu.links.save',
            'back' => [
                'href' => '/admin/modules/weblytic/mainmenu/links',
                'label' => 'Back to links',
                'label_key' => 'module.weblytic.mainmenu.links.form.back',
                'icon' => 'arrow-left',
            ],
            'fields' => $fields,
            'values' => array_merge([
                'id' => $id ?: $this->linkStore()->nextId(),
                'labels' => [],
                'label' => '',
                'url' => '/',
                'new_window' => false,
                'enabled' => true,
            ], $values),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    protected function collectLabels(array $values): array
    {
        $labels = [];
        if (is_array($values['labels'] ?? null)) {
            foreach ($values['labels'] as $locale => $text) {
                $code = strtolower(trim((string) $locale));
                $clean = trim(strip_tags((string) $text));
                if ($code !== '' && $clean !== '') {
                    $labels[$code] = mb_substr($clean, 0, 120);
                }
            }
        }
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'label_')) {
                continue;
            }
            $locale = strtolower(substr($key, 6));
            $text = trim(strip_tags((string) $value));
            if ($locale !== '' && $text !== '') {
                $labels[$locale] = mb_substr($text, 0, 120);
            }
        }

        return $labels;
    }

    protected function defaultLocale(): string
    {
        foreach ($this->query()->rows('languages', ['code', 'name', 'is_enabled', 'is_default'], 50) as $row) {
            if ((int) ($row['is_default'] ?? 0) === 1) {
                return strtolower((string) $row['code']);
            }
        }

        return 'en';
    }

    /**
     * @param  list<string>  $ids
     */
    protected function setLinksEnabled(array $ids, bool $enabled): void
    {
        foreach ($ids as $id) {
            $link = $this->linkStore()->find($id);
            if (! $link) {
                continue;
            }
            $link['enabled'] = $enabled;
            $this->linkStore()->save($link);
        }
    }

    /**
     * @param  list<string>  $ids
     */
    protected function detachMenuItems(array $ids): void
    {
        $config = new MenuConfig($this->settings());
        $keep = array_values(array_filter(
            $config->items(),
            fn ($item) => ! ((string) ($item['type'] ?? '') === 'link' && in_array((string) ($item['ref'] ?? ''), $ids, true))
        ));
        $config->setItems($keep);
    }

    protected function linkStore(): LinkStore
    {
        return new LinkStore($this->settings());
    }
}
