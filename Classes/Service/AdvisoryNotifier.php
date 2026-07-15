<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;
use Webconsulting\Skillspector\Domain\ScanSummary;
use Webconsulting\Skillspector\Support\Typed;

final class AdvisoryNotifier
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

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
        $recipients = $this->recipients();
        if ($recipients === []) {
            return;
        }
        $globalConfiguration = Typed::stringKeyedArray($GLOBALS['TYPO3_CONF_VARS'] ?? null);
        $mailConfiguration = Typed::stringKeyedArray($globalConfiguration['MAIL'] ?? null);
        $from = Typed::string($mailConfiguration['defaultMailFromAddress'] ?? '') ?: 'no-reply@localhost';
        $message = (new MailMessage())
            ->from(new Address($from, 'TYPO3 Skills Inspector'))
            ->to(...array_map(static fn(string $email): Address => new Address($email), $recipients))
            ->subject(sprintf('Skills Inspector: %d action item(s)', count($summary->messages)))
            ->text("Scheduled nr_llm skill inspection\n\n" . implode("\n", array_map(static fn(string $item): string => '- ' . $item, $summary->messages)));
        $this->mailer->send($message);
    }

    /** @return list<string> */
    private function recipients(): array
    {
        try {
            $configuration = Typed::stringKeyedArray($this->extensionConfiguration->get('skillspector'));
        } catch (\Throwable) {
            return [];
        }
        $values = array_map('trim', explode(',', Typed::string($configuration['notificationRecipients'] ?? '')));
        return array_values(array_filter($values, static fn(string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false));
    }
}

