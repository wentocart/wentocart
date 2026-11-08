<?php

namespace App\Domain\Ai;

/**
 * Catalog of LLM companies merchants can plug in with their own API keys.
 * Cursor / IDE tools are not merchant APIs — use openai_compatible for custom endpoints.
 */
final class AiProviderCatalog
{
    /**
     * @return list<array{
     *   driver: string,
     *   label: string,
     *   company: string,
     *   docs_url: string,
     *   description: string,
     *   fields: list<array{name: string, label: string, type: string, required: bool, hint?: string, default?: string, options?: list<string>}>
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'driver' => 'openai',
                'label' => 'OpenAI (ChatGPT)',
                'company' => 'OpenAI',
                'docs_url' => 'https://platform.openai.com/api-keys',
                'description' => 'ChatGPT models via the OpenAI API.',
                'fields' => [
                    ['name' => 'label', 'label' => 'Connection name', 'type' => 'text', 'required' => true, 'hint' => 'Label for your identification'],
                    ['name' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true, 'hint' => 'Starts with sk-'],
                    ['name' => 'model', 'label' => 'Model', 'type' => 'select', 'required' => true, 'default' => 'gpt-4o-mini', 'options' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'o4-mini']],
                ],
            ],
            [
                'driver' => 'anthropic',
                'label' => 'Anthropic (Claude)',
                'company' => 'Anthropic',
                'docs_url' => 'https://console.anthropic.com/settings/keys',
                'description' => 'Claude models via the Anthropic API.',
                'fields' => [
                    ['name' => 'label', 'label' => 'Connection name', 'type' => 'text', 'required' => true, 'hint' => 'Label for your identification'],
                    ['name' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true, 'hint' => 'Starts with sk-ant-'],
                    ['name' => 'model', 'label' => 'Model', 'type' => 'select', 'required' => true, 'default' => 'claude-sonnet-4-20250514', 'options' => ['claude-sonnet-4-20250514', 'claude-3-5-haiku-latest', 'claude-3-5-sonnet-latest']],
                ],
            ],
            [
                'driver' => 'gemini',
                'label' => 'Google Gemini',
                'company' => 'Google',
                'docs_url' => 'https://aistudio.google.com/apikey',
                'description' => 'Gemini models via Google AI Studio / Gemini API.',
                'fields' => [
                    ['name' => 'label', 'label' => 'Connection name', 'type' => 'text', 'required' => true, 'hint' => 'Label for your identification'],
                    ['name' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true, 'hint' => 'Stored encrypted. Never committed to git.'],
                    ['name' => 'model', 'label' => 'Model', 'type' => 'select', 'required' => true, 'default' => 'gemini-2.0-flash', 'options' => ['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro']],
                ],
            ],
            [
                'driver' => 'openai_compatible',
                'label' => 'OpenAI-compatible (custom)',
                'company' => 'Custom / Groq / Mistral / Azure / others',
                'docs_url' => '',
                'description' => 'Any Chat Completions-compatible endpoint (Groq, Mistral, Azure OpenAI, local gateways). Needs API key + Base URL.',
                'fields' => [
                    ['name' => 'label', 'label' => 'Connection name', 'type' => 'text', 'required' => true, 'hint' => 'Label for your identification'],
                    ['name' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'required' => true, 'hint' => 'e.g. https://api.groq.com/openai/v1 — must end with /v1'],
                    ['name' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true, 'hint' => 'Stored encrypted. Never committed to git.'],
                    ['name' => 'model', 'label' => 'Model', 'type' => 'select', 'required' => true, 'default' => 'llama-3.3-70b-versatile', 'options' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768', 'gemma2-9b-it']],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function drivers(): array
    {
        return array_column(self::all(), 'driver');
    }

    /** @return array<string, mixed>|null */
    public static function find(string $driver): ?array
    {
        foreach (self::all() as $row) {
            if ($row['driver'] === $driver) {
                return $row;
            }
        }

        return null;
    }
}
