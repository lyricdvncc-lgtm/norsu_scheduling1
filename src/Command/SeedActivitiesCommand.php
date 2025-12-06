<?php

namespace App\Command;

use App\Entity\ActivityLog;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-activities',
    description: 'Generate sample activity log entries for testing',
)]
class SeedActivitiesCommand extends Command
{
    private UserRepository $userRepository;
    private ActivityLogRepository $activityLogRepository;
    private EntityManagerInterface $entityManager;

    public function __construct(
        UserRepository $userRepository,
        ActivityLogRepository $activityLogRepository,
        EntityManagerInterface $entityManager
    ) {
        parent::__construct();
        $this->userRepository = $userRepository;
        $this->activityLogRepository = $activityLogRepository;
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Seeding Activity Logs');

        // Get some users
        $users = $this->userRepository->findAll();
        
        if (empty($users)) {
            $io->error('No users found in the database. Please create users first.');
            return Command::FAILURE;
        }

        $io->info(sprintf('Found %d users to generate activities for', count($users)));

        // Sample activities
        $sampleActivities = [
            ['action' => 'user.login', 'description' => 'User logged in successfully'],
            ['action' => 'schedule.created', 'description' => 'Created new schedule for BSIT 3A'],
            ['action' => 'schedule.updated', 'description' => 'Updated schedule for CS 101'],
            ['action' => 'schedule.approved', 'description' => 'Approved schedule for Database Systems'],
            ['action' => 'curriculum.created', 'description' => 'Created new curriculum for BSCS'],
            ['action' => 'curriculum.updated', 'description' => 'Updated curriculum subjects'],
            ['action' => 'room.created', 'description' => 'Added new room: Building A - Room 201'],
            ['action' => 'subject.created', 'description' => 'Added new subject: Data Structures'],
            ['action' => 'user.updated', 'description' => 'Updated user profile information'],
            ['action' => 'schedule.rejected', 'description' => 'Rejected schedule due to conflicts'],
        ];

        $count = 0;
        $targetCount = 20;

        // Generate activities for the past 7 days
        foreach ($sampleActivities as $activityTemplate) {
            if ($count >= $targetCount) {
                break;
            }

            // Pick a random user
            $randomUser = $users[array_rand($users)];

            // Generate random timestamp within last 7 days
            $daysAgo = rand(0, 7);
            $hoursAgo = rand(0, 23);
            $minutesAgo = rand(0, 59);
            
            $timestamp = new \DateTimeImmutable(sprintf('-%d days -%d hours -%d minutes', $daysAgo, $hoursAgo, $minutesAgo));

            // Create activity manually to control timestamp
            $activity = new ActivityLog();
            $activity->setUser($randomUser);
            $activity->setAction($activityTemplate['action']);
            $activity->setDescription($activityTemplate['description']);
            $activity->setMetadata(['generated' => true, 'seeded' => true]);
            
            // Use reflection to set the historical timestamp
            $reflection = new \ReflectionClass($activity);
            $property = $reflection->getProperty('createdAt');
            $property->setAccessible(true);
            $property->setValue($activity, $timestamp);

            // Persist the activity
            $this->entityManager->persist($activity);
            $count++;
        }

        // Flush all activities at once
        $this->entityManager->flush();

        $io->success(sprintf('Successfully generated %d sample activities!', $count));

        return Command::SUCCESS;
    }
}
