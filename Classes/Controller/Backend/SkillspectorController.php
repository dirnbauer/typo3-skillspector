<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillspector\Domain\Security\LicenseStatus;
use Webconsulting\Skillspector\Domain\Security\ScanStatus;
use Webconsulting\Skillspector\Domain\Security\Severity;
use Webconsulting\Skillspector\Service\SkillInspectionService;
use Webconsulting\Skillspector\Support\Typed;

/**
 * System → Skills Inspector: the advisory report of every nr_llm skill, the
 * "check all" action and the explicit hide/unhide switch.
 */
#[AsController]
final readonly class SkillspectorController
{
    private const string ROUTE = 'skillspector';
    private const string DOMAIN = 'skillspector.messages';
    private const string TABLE = 'tx_nrllm_skill';

    /** Report level of a skill that was never checked. */
    private const string UNCHECKED = 'unchecked';

    /** Filter order, most urgent first. */
    private const array LEVELS = ['danger', 'warning', 'info', 'none', self::UNCHECKED];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private UriBuilder $uriBuilder,
        private SkillInspectionService $inspectionService,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->label('module.title'));
        // The module is declared `access: admin`, so the router already turns
        // non-administrators away; this is the second lock on a view that
        // shows every skill body verbatim.
        if (!$this->backendUser()->isAdmin()) {
            return $view->renderResponse('Backend/Skillspector/Denied');
        }

        $filter = $this->filterFrom(Typed::string($request->getQueryParams()['level'] ?? ''));
        if ($request->getMethod() === 'POST') {
            $body = Typed::stringKeyedArray($request->getParsedBody());
            match (Typed::string($body['action'] ?? '')) {
                'scanAll' => $this->scanAll($view),
                'toggleHidden' => $this->toggleHidden($body, $view),
                default => null,
            };

            // Post/redirect/get: a reload never repeats a scan or a state change.
            return new RedirectResponse($this->moduleUri(array_filter(['level' => $filter])), 303);
        }

        return $this->renderList($view, $filter);
    }

    private function scanAll(ModuleTemplate $view): void
    {
        $summary = $this->inspectionService->scanAll();
        $view->addFlashMessage(
            $this->label('flash.scan.message', [
                'checked' => $summary->checked,
                'danger' => $summary->danger,
                'warning' => $summary->warning,
                'info' => $summary->info,
            ]),
            $this->label('flash.scan.title'),
            $summary->danger > 0 ? ContextualFeedbackSeverity::WARNING : ContextualFeedbackSeverity::OK,
        );
    }

    /** @param array<string, mixed> $body */
    private function toggleHidden(array $body, ModuleTemplate $view): void
    {
        $uid = Typed::int($body['skill'] ?? 0);
        $hidden = Typed::int($body['hidden'] ?? 0) === 1;
        if ($uid <= 0) {
            return;
        }
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => [$uid => ['hidden' => $hidden ? 1 : 0]]], []);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            $view->addFlashMessage(
                implode(' | ', array_map(Typed::string(...), $dataHandler->errorLog)),
                $this->label('flash.toggle.failed'),
                ContextualFeedbackSeverity::ERROR,
            );

            return;
        }
        $view->addFlashMessage(
            $this->label($hidden ? 'flash.hidden.message' : 'flash.unhidden.message'),
            $this->label($hidden ? 'flash.hidden.title' : 'flash.unhidden.title'),
            ContextualFeedbackSeverity::OK,
        );
    }

    private function renderList(ModuleTemplate $view, string $filter): ResponseInterface
    {
        $view->getDocHeaderComponent()->setShortcutContext(self::ROUTE, $this->label('module.title'), array_filter(['level' => $filter]));
        $nrLlmSkillsUri = $this->nrLlmSkillsUri();
        if ($nrLlmSkillsUri !== '') {
            $view->addButtonToButtonBar(
                $this->componentFactory->createLinkButton()
                    ->setHref($nrLlmSkillsUri)
                    ->setTitle($this->label('nrllm.link'))
                    ->setShowLabelText(true)
                    ->setIcon($this->iconFactory->getIcon('module-nrllm-skill', IconSize::SMALL)),
                ButtonBar::BUTTON_POSITION_LEFT,
                1,
            );
        }

        $returnUri = $this->moduleUri(array_filter(['level' => $filter]));
        $skills = array_map(fn(array $row): array => $this->skillView($row, $returnUri), $this->inspectionService->findAll());
        $counts = array_fill_keys(self::LEVELS, 0);
        foreach ($skills as $skill) {
            $counts[$skill['level']]++;
        }
        $filters = [['level' => '', 'label' => $this->label('filter.all'), 'count' => count($skills), 'uri' => $this->moduleUri(), 'active' => $filter === '']];
        foreach (self::LEVELS as $level) {
            $filters[] = [
                'level' => $level,
                'label' => $this->label('level.' . $level),
                'count' => $counts[$level],
                'uri' => $this->moduleUri(['level' => $level]),
                'active' => $filter === $level,
            ];
        }

        $view->assignMultiple([
            'moduleUri' => $returnUri,
            'resetUri' => $this->moduleUri(),
            'nrLlmSkillsUri' => $nrLlmSkillsUri,
            'filter' => $filter,
            'filters' => $filters,
            'skillCount' => count($skills),
            'skills' => $filter === '' ? $skills : array_values(array_filter($skills, static fn(array $skill): bool => $skill['level'] === $filter)),
            'dateFormat' => self::systemSetting('ddmmyy', 'Y-m-d'),
            'timeFormat' => self::systemSetting('hhmm', 'H:i'),
        ]);

        return $view->renderResponse('Backend/Skillspector/List');
    }

    /**
     * One table row: the skill's state and its stored report, reshaped for
     * the template. A skill that was never checked has no report at all, so
     * every key is defaulted rather than conditionally assigned.
     *
     * @param array<string, mixed> $row
     * @return array{uid: int, name: string, identifier: string, enabled: bool, hidden: bool, orphaned: bool, level: string, levelLabel: string, levelBadge: string, findings: list<array<string, string>>, license: array<string, string>|null, skillspector: array<string, string|int>|null, checkedAt: int, editUri: string}
     */
    private function skillView(array $row, string $returnUri): array
    {
        $uid = Typed::int($row['uid'] ?? 0);
        $report = Typed::stringKeyedArray(json_decode(Typed::string($row['tx_skillspector_check_report'] ?? ''), true));
        $level = $report === [] ? self::UNCHECKED : (Severity::tryFrom(Typed::string($report['level'] ?? null)) ?? Severity::None)->value;

        return [
            'uid' => $uid,
            'name' => Typed::string($row['name'] ?? '') ?: '#' . $uid,
            'identifier' => Typed::string($row['identifier'] ?? ''),
            'enabled' => (bool)($row['enabled'] ?? false),
            'hidden' => (bool)($row['hidden'] ?? false),
            'orphaned' => (bool)($row['orphaned'] ?? false),
            'level' => $level,
            'levelLabel' => $this->label('level.' . $level),
            'levelBadge' => self::levelBadge($level),
            'findings' => array_map($this->findingView(...), array_values(array_filter(
                is_array($report['findings'] ?? null) ? $report['findings'] : [],
                is_array(...),
            ))),
            'license' => $this->licenseView(Typed::stringKeyedArray($report['license'] ?? null)),
            'skillspector' => $this->skillspectorView(Typed::stringKeyedArray($report['skillspector'] ?? null)),
            'checkedAt' => Typed::int($row['tx_skillspector_checked_at'] ?? 0),
            'editUri' => (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [self::TABLE => [$uid => 'edit']],
                'returnUrl' => $returnUri,
            ]),
        ];
    }

    /**
     * @param array<mixed> $finding
     * @return array<string, string>
     */
    private function findingView(array $finding): array
    {
        $finding = Typed::stringKeyedArray($finding);
        $severity = (Severity::tryFrom(Typed::string($finding['severity'] ?? null)) ?? Severity::Info)->value;

        return [
            'severity' => $severity,
            'severityLabel' => $this->label('level.' . $severity),
            'severityBadge' => self::levelBadge($severity),
            'category' => Typed::string($finding['category'] ?? ''),
            'location' => Typed::string($finding['location'] ?? ''),
            'evidence' => Typed::string($finding['evidence'] ?? ''),
            'whatToCheck' => Typed::string($finding['whatToCheck'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $license
     * @return array<string, string>|null
     */
    private function licenseView(array $license): ?array
    {
        if ($license === []) {
            return null;
        }
        $status = LicenseStatus::tryFrom(Typed::string($license['status'] ?? null)) ?? LicenseStatus::Unknown;

        return [
            // "unknown" repeats the status badge ("undeclared") of an undeclared license.
            'normalized' => $status === LicenseStatus::Unknown ? '' : Typed::string($license['normalized'] ?? ''),
            'statusLabel' => $this->label('license.' . $status->value),
            'statusBadge' => match ($status) {
                LicenseStatus::Compatible => 'success',
                LicenseStatus::Review => 'warning',
                LicenseStatus::Incompatible => 'danger',
                LicenseStatus::Unknown => 'default',
            },
            'message' => Typed::string($license['message'] ?? ''),
            'whatToCheck' => Typed::string($license['whatToCheck'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $scan
     * @return array<string, string|int>|null
     */
    private function skillspectorView(array $scan): ?array
    {
        if ($scan === []) {
            return null;
        }
        $status = ScanStatus::tryFrom(Typed::string($scan['status'] ?? null)) ?? ScanStatus::Error;

        return [
            'statusLabel' => $this->label('skillspector.' . $status->value),
            'statusBadge' => match ($status) {
                ScanStatus::Ok => 'success',
                ScanStatus::Unavailable => 'default',
                ScanStatus::Error => 'danger',
            },
            'score' => Typed::int($scan['score'] ?? -1),
            'recommendation' => Typed::string($scan['recommendation'] ?? ''),
            'note' => Typed::string($scan['note'] ?? ''),
        ];
    }

    private static function levelBadge(string $level): string
    {
        return match ($level) {
            'danger' => 'danger',
            'warning' => 'warning',
            'info' => 'info',
            'none' => 'success',
            default => 'default',
        };
    }

    /** A string from $GLOBALS['TYPO3_CONF_VARS']['SYS'], such as the backend date format. */
    private static function systemSetting(string $key, string $default): string
    {
        $system = Typed::stringKeyedArray(Typed::stringKeyedArray($GLOBALS['TYPO3_CONF_VARS'] ?? null)['SYS'] ?? null);

        return Typed::string($system[$key] ?? null) ?: $default;
    }

    private function filterFrom(string $level): string
    {
        return in_array($level, self::LEVELS, true) ? $level : '';
    }

    /** nr_llm's skill module, where sources are synced and skills enabled. */
    private function nrLlmSkillsUri(): string
    {
        try {
            return (string)$this->uriBuilder->buildUriFromRoute('nrllm_skills');
        } catch (RouteNotFoundException) {
            return '';
        }
    }

    /** @param array<string, string> $parameters */
    private function moduleUri(array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $parameters);
    }

    /** @param array<string, int|string> $arguments named arguments use ICU MessageFormat */
    private function label(string $key, array $arguments = []): string
    {
        return (string)($this->languageService()->translate($key, self::DOMAIN, $arguments) ?? $key);
    }

    private function backendUser(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user available', 1789776000);
        }

        return $user;
    }

    private function languageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            throw new \RuntimeException('No language service available', 1789776001);
        }

        return $languageService;
    }
}
