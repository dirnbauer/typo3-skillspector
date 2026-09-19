<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;
use Webconsulting\Skillspector\Domain\ExtensionSettings;
use Webconsulting\Skillspector\Domain\ScanSummary;
use Webconsulting\Skillspector\Service\AdvisoryNotifier;

final class AdvisoryNotifierTest extends TestCase
{
    public function testACleanRunSendsNoMailEvenWithRecipientsConfigured(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $this->notifier($mailer, 'ops@example.com')->notify(new ScanSummary(4, 0, 0, 0, []));
    }

    public function testActionMessagesWithoutRecipientsStayInTheLog(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $this->notifier($mailer, '')->notify(new ScanSummary(4, 1, 0, 0, ['a: danger finding(s).']));
    }

    public function testActionMessagesAreMailedToEveryValidRecipient(): void
    {
        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (RawMessage $message) use (&$sent): void {
                $sent = $message;
            });

        $this->notifier($mailer, 'ops@example.com, broken, security@example.com')
            ->notify(new ScanSummary(4, 1, 1, 0, ['a: danger finding(s).', 'b: warning findings require review.']));

        self::assertInstanceOf(Email::class, $sent);
        self::assertSame(
            ['ops@example.com', 'security@example.com'],
            array_map(static fn(object $address): string => $address->getAddress(), $sent->getTo()),
        );
        self::assertSame('Skills Inspector: 2 action item(s)', $sent->getSubject());
        self::assertStringContainsString('- a: danger finding(s).', (string)$sent->getTextBody());
        self::assertStringContainsString('- b: warning findings require review.', (string)$sent->getTextBody());
        // The sender is left to TYPO3's mailer, which fills in the system address.
        self::assertSame([], $sent->getFrom());
    }

    private function notifier(MailerInterface $mailer, string $recipients): AdvisoryNotifier
    {
        return new AdvisoryNotifier(
            ExtensionSettings::fromArray(['notificationRecipients' => $recipients]),
            $mailer,
            new NullLogger(),
        );
    }
}
