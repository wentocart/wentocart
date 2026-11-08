<?php

namespace Modules\Wento\SampleKit\Http;

use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;

class CustomerInformationController extends ModuleController
{
    public function index(ModuleRequest $request): array
    {
        $id = trim($request->string('id'));
        if ($request->string('new') === '1') {
            return $this->formPage(null, [
                'name' => '',
                'email' => '',
                'status' => 'active',
            ]);
        }
        if ($id !== '') {
            $row = $this->findContact($id);
            if (! $row) {
                return $this->invalid($this->listPage(), ['Contact was not found.']);
            }

            return $this->formPage($id, $row);
        }

        return $this->listPage();
    }

    public function store(ModuleRequest $request): array
    {
        $bulk = $this->selectedList($request);
        if ($bulk['action'] !== '') {
            if ($bulk['ids'] === []) {
                return $this->invalid($this->listPage(), ['Select at least one row.']);
            }
            $this->applyBulk($bulk['action'], $bulk['ids']);

            return $this->listPage();
        }

        $values = $request->all()['values'] ?? [];
        if (! is_array($values)) {
            $values = [];
        }
        $id = trim((string) ($values['id'] ?? $request->string('id')));
        $name = trim((string) ($values['name'] ?? ''));
        $email = trim((string) ($values['email'] ?? ''));
        if ($name === '' || $email === '') {
            $errors = [];
            if ($name === '') {
                $errors['name'] = 'Name is required.';
            }
            if ($email === '') {
                $errors['email'] = 'Email is required.';
            }

            return $this->invalid($this->formPage($id !== '' ? $id : null, $values), $errors, $values);
        }
        $row = [
            'id' => $id !== '' ? $id : $this->nextId(),
            'name' => $name,
            'email' => $email,
            'status' => (($values['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active',
        ];
        $contacts = $this->contacts();
        $found = false;
        foreach ($contacts as $index => $contact) {
            if ((string) ($contact['id'] ?? '') === (string) $row['id']) {
                $contacts[$index] = $row;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $contacts[] = $row;
        }
        $this->settings()->set('contacts', array_values($contacts));

        return $this->listPage();
    }

    /**
     * @return array<string, mixed>
     */
    protected function listPage(): array
    {
        $rows = [];
        foreach ($this->contacts() as $contact) {
            $id = (string) ($contact['id'] ?? '');
            $rows[] = [
                'id' => $id,
                'name' => (string) ($contact['name'] ?? ''),
                'email' => (string) ($contact['email'] ?? ''),
                'status' => (($contact['status'] ?? '') === 'inactive') ? 'Inactive' : 'Active',
                'href' => '/admin/modules/wento/sample-kit/customer-information?id='.$id,
            ];
        }

        return $this->page([
            'title' => 'Customer Information',
            'hint' => 'Hosted list: core draws Catalog checkboxes and Bulk actions. This extension only returns rows and handles POST list_action + ids in store().',
            'list' => [
                'columns' => [
                    ['key' => 'id', 'label' => 'ID'],
                    ['key' => 'name', 'label' => 'Name'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'status', 'label' => 'Status'],
                ],
                'rows' => $rows,
                'bulk' => 'catalog',
                'empty' => 'No contacts yet. Use Add contact.',
                'delete_confirm' => 'Delete the selected contacts from this extension?',
                'create' => [
                    'href' => '/admin/modules/wento/sample-kit/customer-information?new=1',
                    'label' => 'Add contact',
                    'icon' => 'plus',
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function formPage(?string $id, array $row): array
    {
        return $this->page([
            'title' => $id ? 'Edit contact' : 'Add contact',
            'back' => [
                'href' => '/admin/modules/wento/sample-kit/customer-information',
                'label' => 'Back to list',
                'icon' => 'arrow-left',
            ],
            'fields' => array_values(array_filter([
                $id ? [
                    'name' => 'id',
                    'type' => 'readonly',
                    'label' => 'ID',
                    'value' => $id,
                ] : null,
                [
                    'name' => 'name',
                    'type' => 'text',
                    'label' => 'Name',
                ],
                [
                    'name' => 'email',
                    'type' => 'text',
                    'label' => 'Email',
                ],
                [
                    'name' => 'status',
                    'type' => 'select',
                    'label' => 'Status',
                    'options' => [
                        ['value' => 'active', 'label' => 'Active'],
                        ['value' => 'inactive', 'label' => 'Inactive'],
                    ],
                ],
            ])),
            'values' => [
                'id' => $id ?? '',
                'name' => (string) ($row['name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'status' => (($row['status'] ?? '') === 'inactive') ? 'inactive' : 'active',
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function contacts(): array
    {
        $rows = $this->settings()->get('contacts', null);
        if (is_array($rows) && $rows !== []) {
            return array_values($rows);
        }
        $seed = [
            ['id' => '1', 'name' => 'Ada West', 'email' => 'ada@example.com', 'status' => 'active'],
            ['id' => '2', 'name' => 'Ben Cole', 'email' => 'ben@example.com', 'status' => 'inactive'],
        ];
        $this->settings()->set('contacts', $seed);

        return $seed;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findContact(string $id): ?array
    {
        foreach ($this->contacts() as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        return null;
    }

    protected function nextId(): string
    {
        $max = 0;
        foreach ($this->contacts() as $row) {
            $max = max($max, (int) ($row['id'] ?? 0));
        }

        return (string) ($max + 1);
    }

    /**
     * @param  list<string>  $ids
     */
    protected function applyBulk(string $action, array $ids): void
    {
        $contacts = $this->contacts();
        if ($action === 'delete') {
            $contacts = array_values(array_filter(
                $contacts,
                fn ($row) => ! in_array((string) ($row['id'] ?? ''), $ids, true)
            ));
        } elseif ($action === 'enable' || $action === 'disable') {
            $status = $action === 'enable' ? 'active' : 'inactive';
            foreach ($contacts as $index => $row) {
                if (in_array((string) ($row['id'] ?? ''), $ids, true)) {
                    $contacts[$index]['status'] = $status;
                }
            }
        } elseif ($action === 'duplicate') {
            foreach ($this->contacts() as $row) {
                if (! in_array((string) ($row['id'] ?? ''), $ids, true)) {
                    continue;
                }
                $copy = $row;
                $copy['id'] = $this->nextIdFrom($contacts);
                $copy['name'] = trim((string) ($copy['name'] ?? '')).' copy';
                $contacts[] = $copy;
            }
        }
        $this->settings()->set('contacts', array_values($contacts));
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     */
    protected function nextIdFrom(array $contacts): string
    {
        $max = 0;
        foreach ($contacts as $row) {
            $max = max($max, (int) ($row['id'] ?? 0));
        }

        return (string) ($max + 1);
    }
}
