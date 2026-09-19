<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use Webconsulting\Skillspector\Domain\ExtensionSettings;
use Webconsulting\Skillspector\Domain\ScanSummary;

/**
 * Reports what a scheduled check found. Always to the log; additionally by
 * mail when recipients are configured. Nothing here changes a skill.
 */
final class AdvisoryNotifier
{
    public function __construct(
        private readonly ExtensionSettings $settings,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function notify(ScanSummary $summary): void
    {
        if ($summary->messages === []) {
            $this->logger->info('Skills Inspector scheduled check: no action required.', ['checked' => $summary->checked]);

            return;
        }

        $this->logger->warning('Skills Inspector scheduled check requires action.', [
            'checked' => $summary->checked,
            'messages' => $summary->messages,
        ]);

        $recipients = $this->settings->notificationRecipients;
        if ($recipients === []) {
            return;
        }

        // No sender is set: TYPO3's mailer fills in the installation's
        // configured system address.
        $this->mailer->send(
            (new MailMessage())
                ->to(...array_map(static fn(string $email): Address => new Address($email), $recipients))
                ->subject(sprintf('Skills Inspector: %d action item(s)', count($summary->messages)))
                ->text(
                    "Scheduled nr_llm skill inspection\n\n"
                    . implode("\n", array_map(static fn(string $item): string => '- ' . $item, $summary->messages))
                )
        );
    }
}
