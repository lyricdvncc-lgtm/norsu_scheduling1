<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:update-user-role',
    description: 'Update a user\'s role',
)]
class UpdateUserRoleCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('role', InputArgument::REQUIRED, 'Role: admin, department_head, or faculty')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $roleName = strtolower($input->getArgument('role'));

        // Map role names to role IDs
        $roleMap = [
            'admin' => 1,
            'department_head' => 2,
            'faculty' => 3,
        ];

        if (!isset($roleMap[$roleName])) {
            $io->error("Invalid role! Use: admin, department_head, or faculty");
            return Command::FAILURE;
        }

        $roleId = $roleMap[$roleName];

        // Find the user
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        if (!$user) {
            $io->error("User with email '{$email}' not found!");
            return Command::FAILURE;
        }

        $oldRole = $user->getRoleString();
        
        // Update the role
        $user->setRole($roleId);
        $this->entityManager->flush();

        $io->success("User '{$email}' role updated from '{$oldRole}' to '{$roleName}'");
        $io->info("User can now access the {$roleName} dashboard");

        return Command::SUCCESS;
    }
}
