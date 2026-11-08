<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\PageDocument;

abstract class ModuleController
{
    public function __construct(protected PluginContext $ctx) {}

    public function index(ModuleRequest $request): array
    {
        return $this->page(['title' => $this->ctx->manifest()->displayName, 'blocks' => []]);
    }

    public function store(ModuleRequest $request): array
    {
        return $this->index($request);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    protected function page(array $document): array
    {
        return PageDocument::sanitize($document);
    }

    /**
     * Re-render the same page with red validation errors. Typed values stay on the form.
     *
     * @param  array<string, mixed>  $document
     * @param  array<int|string, mixed>|\Illuminate\Validation\ValidationException  $errors
     * @param  array<string, mixed>|ModuleRequest|null  $values  Submitted values; ModuleRequest uses `values` or the full payload
     */
    protected function invalid(array $document, array|\Illuminate\Validation\ValidationException $errors, array|ModuleRequest|null $values = null, string $placement = 'top'): array
    {
        if ($values instanceof ModuleRequest) {
            $payload = $values->all();
            $values = is_array($payload['values'] ?? null) ? $payload['values'] : $payload;
        }

        return $this->page(FormFeedback::apply($document, $errors, is_array($values) ? $values : null, $placement));
    }

    /**
     * Hosted list bulk POST: { list_action, ids: [...] }.
     *
     * @return array{action: string, ids: list<string>}
     */
    protected function selectedList(ModuleRequest $request): array
    {
        return [
            'action' => PageDocument::listActionKey($request->string('list_action')),
            'ids' => PageDocument::selectedIds($request->all()['ids'] ?? []),
        ];
    }

    protected function settings(): PluginSettings
    {
        return $this->ctx->settings();
    }

    protected function query(): ModuleQuery
    {
        return $this->ctx->query();
    }

    protected function http(): PluginHttp
    {
        return $this->ctx->http();
    }

    protected function manifest(): \App\Domain\Plugin\PluginManifest
    {
        return $this->ctx->manifest();
    }
}
