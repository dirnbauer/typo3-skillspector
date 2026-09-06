<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Tests\Unit\Service\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillspector\Domain\ParsedSkill;
use Webconsulting\Skillspector\Service\Security\NrLlmScanCredentials;
use Webconsulting\Skillspector\Service\Security\SkillspectorScanner;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SkillspectorScannerTest extends TestCase
{
    private string $varPath;

    protected function setUp(): void
    {
        $this->varPath = sys_get_temp_dir() . '/skillspector-test-' . bin2hex(random_bytes(8));
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] = '0700';
        Environment::initialize(new ApplicationContext('Testing'), true, true, $this->varPath, $this->varPath . '/public', $this->varPath, $this->varPath . '/config', __FILE__, 'UNIX');
        GeneralUtility::mkdir_deep($this->varPath . '/transient/skillspector');
        file_put_contents($this->varPath . '/transient/skillspector/keep.txt', 'Another scan owns this file.');
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->varPath, true);
    }

    #[DataProvider('skills')]
    public function testScanStaysIsolatedAndCleansUpAfterSuccessOrFailure(string $name, string $body, string $status, string $folderMask = '0700'): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] = $folderMask;
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn([
            'skillspectorEnabled' => 1,
            'skillspectorUseLlm' => 0,
            'skillspectorBinary' => __DIR__ . '/../../../Fixtures/skillspector.php',
        ]);
        $scanner = new SkillspectorScanner($configuration, new NrLlmScanCredentials());
        $skill = new ParsedSkill($name, 'Example skill', $body, '', []);

        $report = $scanner->scan($skill);

        self::assertNotNull($report);
        self::assertSame($status, $report->status, $report->note);
        self::assertSame([$this->varPath . '/transient/skillspector/keep.txt'], glob($this->varPath . '/transient/skillspector/*'));
        self::assertSame('Another scan owns this file.', file_get_contents($this->varPath . '/transient/skillspector/keep.txt'));
    }

    public static function skills(): iterable
    {
        yield 'normal name' => ['example', 'Harmless instructions.', 'ok'];
        yield 'dotted name' => ['example.skill', 'Harmless instructions.', 'ok'];
        yield 'current directory' => ['.', 'Harmless instructions.', 'ok'];
        yield 'parent directory' => ['..', 'Harmless instructions.', 'ok'];
        yield 'empty name' => ['', 'Harmless instructions.', 'ok'];
        yield 'process failure' => ['example', 'FAIL_SCAN', 'error'];
        yield 'preparation failure' => ['example', 'Harmless instructions.', 'error', '0500'];
    }
}
