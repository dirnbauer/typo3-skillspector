<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Tests\Unit\Service\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\Skillspector\Service\Security\NrLlmScanCredentials;

final class NrLlmScanCredentialsTest extends TestCase
{
    public function testOpenAiAdapterMapsToOpenAiProviderWithModelAndBaseUrl(): void
    {
        $env = NrLlmScanCredentials::mapEnv('openai', 'sk-test-123', 'gpt-5.6-terra', 'https://api.openai.com/v1');

        self::assertSame([
            'SKILLSPECTOR_PROVIDER' => 'openai',
            'OPENAI_API_KEY' => 'sk-test-123',
            'OPENAI_BASE_URL' => 'https://api.openai.com/v1',
            'SKILLSPECTOR_MODEL' => 'gpt-5.6-terra',
        ], $env);
    }

    public function testAnthropicAdapterMapsToAnthropicProviderWithoutBaseUrl(): void
    {
        $env = NrLlmScanCredentials::mapEnv('anthropic', 'sk-ant-xyz', 'claude-sonnet-4-6', 'https://api.anthropic.com');

        self::assertSame([
            'SKILLSPECTOR_PROVIDER' => 'anthropic',
            'ANTHROPIC_API_KEY' => 'sk-ant-xyz',
            'SKILLSPECTOR_MODEL' => 'claude-sonnet-4-6',
        ], $env);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function openAiCompatibleAdapters(): array
    {
        return [
            'openrouter' => ['openrouter'],
            'groq' => ['groq'],
            'mistral' => ['mistral'],
            'together' => ['together'],
            'fireworks' => ['fireworks'],
            'perplexity' => ['perplexity'],
            'ollama' => ['ollama'],
            'azure_openai' => ['azure_openai'],
            'custom' => ['custom'],
        ];
    }

    #[DataProvider('openAiCompatibleAdapters')]
    public function testOpenAiCompatibleAdaptersUseTheOpenAiProviderWithTheirEndpoint(string $adapter): void
    {
        $env = NrLlmScanCredentials::mapEnv($adapter, 'key', 'some-model', 'https://host.example/v1');

        self::assertSame('openai', $env['SKILLSPECTOR_PROVIDER'] ?? null);
        self::assertSame('key', $env['OPENAI_API_KEY'] ?? null);
        self::assertSame('https://host.example/v1', $env['OPENAI_BASE_URL'] ?? null);
    }

    public function testEndpointOmittedWhenEmpty(): void
    {
        $env = NrLlmScanCredentials::mapEnv('openai', 'key', 'm', '');

        self::assertArrayNotHasKey('OPENAI_BASE_URL', (array)$env);
    }

    public function testModelOmittedWhenEmpty(): void
    {
        $env = NrLlmScanCredentials::mapEnv('openai', 'key', '', 'https://api.openai.com/v1');

        self::assertArrayNotHasKey('SKILLSPECTOR_MODEL', (array)$env);
    }

    public function testUnknownAdapterYieldsNull(): void
    {
        // Gemini has no HTTP provider in this SkillSpector build → static-only.
        self::assertNull(NrLlmScanCredentials::mapEnv('gemini', 'key', 'gemini-2', ''));
        self::assertNull(NrLlmScanCredentials::mapEnv('', 'key', 'm', ''));
    }

    public function testEmptyApiKeyYieldsNull(): void
    {
        self::assertNull(NrLlmScanCredentials::mapEnv('openai', '', 'm', 'https://api.openai.com/v1'));
    }
}


