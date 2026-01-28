<?php

namespace App\Command;

use App\Repository\ScheduleRepository;
use App\Service\ScheduleConflictDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:scan-block-section-conflicts',
    description: 'Scan all active schedules for block sectioning conflicts',
)]
class ScanBlockSectionConflictsCommand extends Command
{
    private ScheduleRepository $scheduleRepository;
    private ScheduleConflictDetector $conflictDetector;
    private EntityManagerInterface $entityManager;

    public function __construct(
        ScheduleRepository $scheduleRepository,
        ScheduleConflictDetector $conflictDetector,
        EntityManagerInterface $entityManager
    ) {
        parent::__construct();
        $this->scheduleRepository = $scheduleRepository;
        $this->conflictDetector = $conflictDetector;
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Block Sectioning Conflict Scanner');

        // Get all active schedules
        $schedules = $this->scheduleRepository->findBy(['status' => 'active']);
        
        if (empty($schedules)) {
            $io->warning('No active schedules found in the database.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Scanning %d active schedules...', count($schedules)));
        $io->newLine();

        $conflictCount = 0;
        $scheduleWithConflicts = [];
        $allConflicts = [];

        foreach ($schedules as $schedule) {
            $io->writeln(sprintf('Checking schedule ID %d: %s Section %s', 
                $schedule->getId(),
                $schedule->getSubject()->getCode(),
                $schedule->getSection()
            ));
            
            // Detect conflicts for this schedule
            $conflicts = $this->conflictDetector->detectConflicts($schedule, true);
            
            $io->writeln(sprintf('  Total conflicts: %d', count($conflicts)));
            
            // Filter only block sectioning conflicts
            $blockConflicts = array_filter($conflicts, function($conflict) {
                return $conflict['type'] === 'block_sectioning_conflict';
            });
            
            $io->writeln(sprintf('  Block sectioning conflicts: %d', count($blockConflicts)));

            if (!empty($blockConflicts)) {
                $conflictCount += count($blockConflicts);
                $scheduleWithConflicts[] = $schedule;
                
                foreach ($blockConflicts as $conflict) {
                    $allConflicts[] = [
                        'schedule' => $schedule,
                        'conflict' => $conflict
                    ];
                }
            }
        }

        // Display results
        if ($conflictCount === 0) {
            $io->success('✅ No block sectioning conflicts found! All schedules are valid.');
            return Command::SUCCESS;
        }

        $io->error(sprintf('❌ Found %d block sectioning conflict(s) affecting %d schedule(s)', 
            $conflictCount, 
            count($scheduleWithConflicts)
        ));
        $io->newLine();

        // Group conflicts by section and year level
        $groupedConflicts = [];
        foreach ($allConflicts as $item) {
            $schedule = $item['schedule'];
            $conflict = $item['conflict'];
            
            $section = $schedule->getSection();
            $yearLevel = 'Unknown';
            
            if ($schedule->getCurriculumSubject() && $schedule->getCurriculumSubject()->getCurriculumTerm()) {
                $yearLevel = $schedule->getCurriculumSubject()->getCurriculumTerm()->getYearLevel();
            }
            
            $key = "Year $yearLevel - Section $section";
            
            if (!isset($groupedConflicts[$key])) {
                $groupedConflicts[$key] = [];
            }
            
            $groupedConflicts[$key][] = [
                'subject1' => $schedule->getSubject()->getCode(),
                'subject2' => $conflict['schedule']->getSubject()->getCode(),
                'section' => $section,
                'dayPattern' => $schedule->getDayPattern(),
                'time' => sprintf('%s - %s',
                    $schedule->getStartTime()->format('g:i A'),
                    $schedule->getEndTime()->format('g:i A')
                ),
                'room1' => $schedule->getRoom()->getName(),
                'room2' => $conflict['schedule']->getRoom()->getName(),
                'message' => $conflict['message']
            ];
        }

        // Display grouped conflicts
        foreach ($groupedConflicts as $group => $conflicts) {
            $io->section($group);
            
            foreach ($conflicts as $conflict) {
                $io->writeln(sprintf(
                    '  🔴 <fg=red>%s</> (Section %s) conflicts with <fg=red>%s</> (Section %s)',
                    $conflict['subject1'],
                    $conflict['section'],
                    $conflict['subject2'],
                    $conflict['section']
                ));
                $io->writeln(sprintf(
                    '     Time: %s on %s',
                    $conflict['time'],
                    $conflict['dayPattern']
                ));
                $io->writeln(sprintf(
                    '     Rooms: %s vs %s',
                    $conflict['room1'],
                    $conflict['room2']
                ));
                $io->newLine();
            }
        }

        $io->newLine();
        $io->warning([
            'Action Required:',
            'Students in the same section cannot attend both classes at the same time.',
            'Please reschedule one of the conflicting subjects to a different time slot.',
        ]);

        return Command::FAILURE;
    }
}
