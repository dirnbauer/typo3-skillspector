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
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('Skills Inspector');
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
        $skills = $this->inspectionService->findAll();
        $returnUrl = (string)$this->uriBuilder->buildUriFromRoute('skillspector');
        foreach ($skills as &$skill) {
            $skill['editUri'] = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => ['tx_nrllm_skill' => [Typed::int($skill['uid'] ?? 0) => 'edit']],
                'returnUrl' => $returnUrl,
            ]);
            $skill['review'] = $this->reviewView(Typed::string($skill['tx_skillspector_check_report'] ?? ''));
            $skill['checkedFormatted'] = Typed::int($skill['tx_skillspector_checked_at'] ?? 0) > 0
                ? date('Y-m-d H:i', Typed::int($skill['tx_skillspector_checked_at']))
                : 'Never';
        }
        unset($skill);
        $moduleTemplate->assignMultiple([
            'moduleUri' => $returnUrl,
            'nrLlmSkillsUri' => (string)$this->uriBuilder->buildUriFromRoute('nrllm_skills'),
            'skills' => $skills,
        ]);
        return $moduleTemplate->renderResponse('Backend/Skillspector/List');
    }

    /** @return array<string, mixed> */
    private function reviewView(string $json): array
    {
        $report = json_decode($json, true);
        if (!is_array($report)) {
            return ['level' => 'unchecked', 'findings' => [], 'license' => [], 'skillspector' => []];
        }
        return [
            'level' => Typed::string($report['level'] ?? 'unchecked'),
            'findings' => is_array($report['findings'] ?? null) ? $report['findings'] : [],
            'license' => is_array($report['license'] ?? null) ? $report['license'] : [],
            'skillspector' => is_array($report['skillspector'] ?? null) ? $report['skillspector'] : [],
            'severityCounts' => is_array($report['severityCounts'] ?? null) ? $report['severityCounts'] : [],
        ];
    }

    private function backendUser(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user available');
        }
        return $user;
    }
}

