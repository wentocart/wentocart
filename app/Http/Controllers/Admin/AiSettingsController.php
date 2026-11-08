<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ai\AiProviderCatalog;
use App\Domain\Ai\AiProviderFactory;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\ContentWriter;
use App\Domain\Security\SafeUrl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AiSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $tab = $request->query('tab', 'options');
        if (! in_array($tab, ['options', 'assistant', 'storefront', 'connections'], true)) {
            $tab = 'options';
        }

        return Inertia::render('Settings/Ai', [
            'ai' => AiSettings::forAdmin(),
            'catalog' => AiProviderCatalog::all(),
            'cmsPages' => \App\Http\Controllers\Admin\AiCustomerAssistantController::cmsPageOptions(),
            'tab' => $tab,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'content_enabled' => ['required', 'boolean'],
        ]);

        AiSettings::saveFlags($data);

        return back()->with('success', 'AI settings saved.');
    }

    public function storeConnection(Request $request): RedirectResponse
    {
        $data = $this->validatedConnection($request, creating: true);

        try {
            AiSettings::upsertConnection(null, $data);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['driver' => $e->getMessage()]);
        }

        return back()->with('success', 'AI connection added.');
    }

    public function updateConnection(Request $request, string $connection): RedirectResponse
    {
        $data = $this->validatedConnection($request, creating: false);

        try {
            AiSettings::upsertConnection($connection, $data);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['driver' => $e->getMessage()]);
        }

        return back()->with('success', 'AI connection saved.');
    }

    public function destroyConnection(string $connection): RedirectResponse
    {
        AiSettings::deleteConnection($connection);

        return back()->with('success', 'AI connection removed.');
    }

    public function destroyConnections(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string'],
        ]);

        foreach ($data['ids'] as $id) {
            AiSettings::deleteConnection($id);
        }

        return back()->with('success', 'AI connections removed.');
    }

    public function activateConnection(string $connection): RedirectResponse
    {
        try {
            AiSettings::activate($connection);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['active_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Active AI provider updated. Only this connection is used until you switch.');
    }

    public function test(Request $request): JsonResponse
    {
        $id = $request->input('connection_id') ?: AiSettings::activeId();
        if (! $id) {
            return response()->json(['message' => 'Add and activate a connection first.'], 422);
        }

        try {
            AiSettings::activate((string) $id);
            $active = AiSettings::activeConnection();
            if ($active === null) {
                return response()->json(['message' => 'Connection has no API key.'], 422);
            }
            $text = AiProviderFactory::make($active)->complete(
                'Reply with exactly: ok',
                ['model' => $active['model'], 'max_tokens' => 16, 'temperature' => 0]
            );

            return response()->json([
                'ok' => true,
                'preview' => mb_substr($text, 0, 120),
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function content(Request $request, ContentWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(ContentWriter::ACTIONS)],
            'field' => ['required', 'string', 'max:80'],
            'text' => ['nullable', 'string', 'max:8000'],
            'locale' => ['nullable', 'string', 'max:12'],
            'context' => ['nullable', 'array'],
            'context.*' => ['nullable'],
        ]);

        try {
            return response()->json(['text' => $writer->write($data)]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array{driver: string, label: string, api_key?: ?string, model?: ?string, base_url?: ?string, clear_api_key?: bool}
     */
    protected function validatedConnection(Request $request, bool $creating): array
    {
        $drivers = AiProviderCatalog::drivers();
        $data = $request->validate([
            'driver' => ['required', 'string', Rule::in($drivers)],
            'label' => ['required', 'string', 'max:120'],
            'api_key' => [$creating ? 'required' : 'nullable', 'string', 'max:500'],
            'model' => ['required', 'string', 'max:120'],
            'base_url' => ['nullable', 'string', 'max:500'],
            'clear_api_key' => ['sometimes', 'boolean'],
        ]);

        if ($data['driver'] === 'openai_compatible') {
            $request->validate([
                'base_url' => [
                    'required',
                    'url',
                    'max:500',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        try {
                            // SSRF guard: server-side calls go to this URL, so it must be
                            // a public http(s) host — no localhost, LAN, or metadata IPs.
                            SafeUrl::assertPublicHttp((string) $value, false);
                        } catch (\InvalidArgumentException) {
                            $fail('The base URL must be a publicly reachable http(s) URL.');
                        }
                    },
                ],
            ]);
            $data['base_url'] = $request->input('base_url');
        }

        return $data;
    }
}
