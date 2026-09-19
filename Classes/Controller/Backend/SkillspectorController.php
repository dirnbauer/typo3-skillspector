<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillspector\Service\SkillInspectionService;
use Webconsulting\Skillspector\Support\Typed;

final class SkillspectorController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly SkillInspectionService $inspectionService,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('Skills Inspector');
        // The module is declared `access: admin`, so the router already turns
        // non-administrators away; this is the second lock on a view that
        // shows every skill body verbatim.
        if (!$this->backendUser()->isAdmin()) {
            return $moduleTemplate->renderResponse('Backend/Skillspector/Denied');
        }

        $body = Typed::stringKeyedArray($request->getParsedBody());
        if ($request->getMethod() === 'POST') {
            match (Typed::string($body['action'] ?? '')) {
                'scanAll' => $this->scanAll($moduleTemplate),
                'toggleHidden' => $this->toggleHidden($body, $moduleTemplate),
                default => null,
            };
        }

        return $this->renderList($moduleTemplate);
    }

    private function scanAll(ModuleTemplate $moduleTemplate): void
    {
        $summary = $this->inspectionService->scanAll();
        $moduleTemplate->addFlashMessage(
            sprintf('Checked %d skill(s): %d danger, %d warning, %d info. Nothing was hidden automatically.', $summary->checked, $summary->danger, $summary->warning, $summary->info),
            'Inspection finished',
            $summary->danger > 0 ? ContextualFeedbackSeverity::WARNING : ContextualFeedbackSeverity::OK,
        );
    }

    /** @param array<string, mixed> $body */
    private function toggleHidden(array $body, ModuleTemplate $moduleTemplate): void
    {
        $uid = Typed::int($body['skill'] ?? 0);
        $hidden = Typed::int($body['hidden'] ?? 0) === 1;
        if ($uid <= 0) {
            return;
        }
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tx_nrllm_skill' => [$uid => ['hidden' => $hidden ? 1 : 0]]], []);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            $moduleTemplate->addFlashMessage(implode(' | ', array_map(Typed::string(...), $dataHandler->errorLog)), 'State change failed', ContextualFeedbackSeverity::ERROR);

            return;
        }
        $moduleTemplate->addFlashMessage(
            $hidden ? 'The skill was hidden. nr_llm and Skillflow will not use it.' : 'The skill was unhidden. Its nr_llm enabled state still applies.',
            $hidden ? 'Skill hidden' : 'Skill unhidden',
            ContextualFeedbackSeverity::OK,
        );
    }

    private function renderList(ModuleTemplate $moduleTemplate): ResponseInterface
    {
        $returnUrl = (string)$this->uriBuilder->buildUriFromRoute('skillspector');
        $skills = array_map(
            fn(array $row): array => $row + [
                'editUri' => (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => ['tx_nrllm_skill' => [Typed::int($row['uid'] ?? 0) => 'edit']],
                    'returnUrl' => $returnUrl,
                ]),
                'review' => self::reviewView(Typed::string($row['tx_skillspector_check_report'] ?? '')),
                'checkedFormatted' => Typed::int($row['tx_skillspector_checked_at'] ?? 0) > 0
                    ? date('Y-m-d H:i', Typed::int($row['tx_skillspector_checked_at']))
                    : 'Never',
            ],
            $this->inspectionService->findAll(),
        );

        $moduleTemplate->assignMultiple([
            'moduleUri' => $returnUrl,
            'nrLlmSkillsUri' => (string)$this->uriBuilder->buildUriFromRoute('nrllm_skills'),
            'skills' => $skills,
        ]);

        return $moduleTemplate->renderResponse('Backend/Skillspector/List');
    }

    /**
     * The stored report, reshaped for the template. A skill that was never
     * checked has no report at all, so every key is defaulted rather than
     * conditionally assigned — the template can read all of them.
     *
     * @return array<string, mixed>
     */
    private static function reviewView(string $json): array
    {
        $report = Typed::stringKeyedArray(json_decode($json, true));

        return [
            'level' => Typed::string($report['level'] ?? null) ?: 'unchecked',
            'severityCounts' => Typed::stringKeyedArray($report['severityCounts'] ?? null),
            'findings' => array_values(Typed::stringKeyedArray($report['findings'] ?? null)),
            'license' => Typed::stringKeyedArray($report['license'] ?? null),
            'skillspector' => Typed::stringKeyedArray($report['skillspector'] ?? null),
        ];
    }

    private function backendUser(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user available', 1789776000);
        }

        return $user;
    }
}
