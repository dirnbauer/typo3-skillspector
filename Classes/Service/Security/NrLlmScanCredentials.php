<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service\Security;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sources credentials for SkillSpector's LLM-assisted analysis from the LLM
 * connection already configured in nr_llm (the "LLM" backend module) — the same
 * provider, model and vault-stored key the extension's nr-llm runner uses. This
 * lets the SkillSpector scan reuse an existing OpenAI/Anthropic connection
 * instead of requiring separate SKILLSPECTOR_* env vars.
 *
 * The resolved values are returned as the environment variables SkillSpector
 * reads (SKILLSPECTOR_PROVIDER + the provider's key/base-url + optional
 * SKILLSPECTOR_MODEL); SkillspectorScanner passes them to the scan subprocess.
 * The decrypted key lives only in that returned array for the duration of one
 * scan — it is never persisted, logged or written into a report.
 *
 * Soft dependency: nr_llm is resolved lazily behind class_exists(), so
 * skillflow still works when nr_llm is absent (resolve() returns null and the
 * scanner falls back to static-only analysis).
 */
final class NrLlmScanCredentials
{
    /**
     * nr_llm adapter types that speak the OpenAI wire protocol and therefore
     * map onto SkillSpector's `openai` provider (with the endpoint as
     * OPENAI_BASE_URL). Mirrors Netresearch\NrLlm\Domain\Model\AdapterType —
     * kept as plain strings so this class stays testable without nr_llm.
     *
     * @var list<string>
     */
    private const OPENAI_COMPATIBLE = [
        'openai', 'openrouter', 'mistral', 'groq', 'together',
        'fireworks', 'perplexity', 'ollama', 'azure_openai', 'custom',
    ];

    /**
     * @return array<string, string>|null environment for a SkillSpector LLM scan,
     *   or null when nr_llm is unavailable / has no usable HTTP provider + key
     */
    public function resolve(): ?array
    {
        if (!class_exists(LlmConfigurationRepository::class)) {
            return null;
        }
        try {
            $default = GeneralUtility::makeInstance(LlmConfigurationRepository::class)->findDefault();
            $model = $default?->getLlmModel();
            $provider = $model?->getProvider();
            if ($provider === null) {
                return null;
            }
            $apiKey = $provider->getDecryptedApiKey();
            if ($apiKey === '') {
                return null;
            }
            return self::mapEnv(
                $provider->getAdapterType(),
                $apiKey,
                $model?->getModelId() ?? '',
                $provider->getEndpointUrl(),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Maps an nr_llm adapter type to the environment variables SkillSpector
     * reads. OpenAI-compatible adapters all use SkillSpector's `openai`
     * provider with the endpoint as OPENAI_BASE_URL; adapters SkillSpector has
     * no HTTP provider for (e.g. Gemini in this build) yield null → static-only.
     *
     * Pure and side-effect free so it is unit-testable without nr_llm.
     *
     * @return array<string, string>|null
     */
    public static function mapEnv(string $adapterType, string $apiKey, string $modelId, string $endpointUrl): ?array
    {
        if ($apiKey === '') {
            return null;
        }

        if ($adapterType === 'anthropic') {
            $env = [
                'SKILLSPECTOR_PROVIDER' => 'anthropic',
                'ANTHROPIC_API_KEY' => $apiKey,
            ];
        } elseif (in_array($adapterType, self::OPENAI_COMPATIBLE, true)) {
            $env = [
                'SKILLSPECTOR_PROVIDER' => 'openai',
                'OPENAI_API_KEY' => $apiKey,
            ];
            if ($endpointUrl !== '') {
                $env['OPENAI_BASE_URL'] = $endpointUrl;
            }
        } else {
            // No SkillSpector HTTP provider for this adapter — stay static.
            return null;
        }

        if ($modelId !== '') {
            $env['SKILLSPECTOR_MODEL'] = $modelId;
        }
        return $env;
    }
}


