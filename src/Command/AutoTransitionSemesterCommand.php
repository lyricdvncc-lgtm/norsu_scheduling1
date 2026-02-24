<?php

namespace App\Command;

use App\Service\SystemSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:auto-transition-semester',
    description: 'Automatically transition to the next semester if the current one has expired. Designed to run as a daily cron job.',
)]
class AutoTransitionSemesterCommand extends Command
{
    public function __construct(
        private SystemSettingsService $systemSettingsService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only check — do not actually transition')
            ->setHelp(<<<'HELP'
This command checks if the current active semester's end date has passed.
If it has, it automatically transitions to the next semester.

Transition logic:
  1st Semester  → 2nd Semester  (same academic year)
  2nd Semester  → Summer        (same academic year)
  Summer        → 1st Semester  (next academic year — must exist)

Run this as a daily cron job:
  0 0 * * * php /path/to/bin/console app:auto-transition-semester

Or use --dry-run to preview without making changes:
  php bin/console app:auto-transition-semester --dry-run
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $io->title('Semester Auto-Transition Check');

        // Show current state
        $current = $this->systemSettingsService->getActiveSemesterDisplay();
        $io->writeln(sprintf('Current active semester: <info>%s</info>', $current));

        $status = $this->systemSettingsService->getAutoTransitionStatus();

        if (!$status['has_dates']) {
            $io->note('No end date configured for the current semester. Auto-transition is not possible.');
            $io->writeln('Set semester dates in System Settings to enable auto-transition.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Semester period: <info>%s</info> to <info>%s</info>', 
            $status['semester_start'] ?? 'N/A',
            $status['semester_end'] ?? 'N/A'
        ));

        if ($status['days_remaining'] !== null && $status['days_remaining'] >= 0) {
            $io->writeln(sprintf('Days remaining: <info>%d</info>', $status['days_remaining']));
        }

        if (!$status['is_expired']) {
            $io->success('Current semester has NOT expired. No transition needed.');
            return Command::SUCCESS;
        }

        $io->warning('Current semester has EXPIRED!');

        if ($status['next_semester']) {
            $io->writeln(sprintf('Next semester: <info>%s</info>', $status['next_semester']));
        }

        if ($dryRun) {
            $io->note('[DRY RUN] Would transition to: ' . ($status['next_semester'] ?? 'unknown'));
            return Command::SUCCESS;
        }

        // Perform the transition
        $io->section('Performing Auto-Transition');
        $result = $this->systemSettingsService->checkAndAutoTransition();

        if ($result === null) {
            $io->error('Auto-transition returned null — unexpected state.');
            return Command::FAILURE;
        }

        if ($result['transitioned']) {
            $io->success(sprintf(
                'Successfully transitioned from "%s" to "%s"%s',
                $result['from'],
                $result['to'],
                $result['crossed_year'] ? ' (new academic year)' : ''
            ));

            // Verify
            $newCurrent = $this->systemSettingsService->getActiveSemesterDisplay();
            $io->writeln(sprintf('Verified active semester: <info>%s</info>', $newCurrent));

            return Command::SUCCESS;
        }

        // Transition was needed but couldn't complete (e.g., next year missing)
        $io->error($result['reason'] ?? 'Could not complete auto-transition.');
        return Command::FAILURE;
    }
}
