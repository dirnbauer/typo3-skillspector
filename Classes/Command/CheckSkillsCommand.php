<?php

declare(strict_types=1);

namespace Webconsulting\Skillspector\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\Skillspector\Service\AdvisoryNotifier;
use Webconsulting\Skillspector\Service\SkillInspectionService;

#[AsCommand(name: 'skillspector:check', description: 'Check nr_llm skills and send advisory action messages; never hides skills')]
final class CheckSkillsCommand extends Command
{
    public function __construct(
        private readonly SkillInspectionService $inspectionService,
        private readonly AdvisoryNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('notify', null, InputOption::VALUE_NEGATABLE, 'Send configured advisory notifications', true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $summary = $this->inspectionService->scanAll();
        $io->success(sprintf('Checked %d skill(s): %d danger, %d warning, %d info. No skill state was changed.', $summary->checked, $summary->danger, $summary->warning, $summary->info));
        foreach ($summary->messages as $message) {
            $io->warning($message);
        }
        if ($input->getOption('notify') !== false) {
            $this->notifier->notify($summary);
        }
        return Command::SUCCESS;
    }
}

