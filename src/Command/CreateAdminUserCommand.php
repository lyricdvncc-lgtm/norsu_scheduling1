<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Create an admin user for production deployment',
)]
class CreateAdminUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Admin username')
            ->addArgument('email', InputArgument::OPTIONAL, 'Admin email')
            ->addArgument('password', InputArgument::OPTIONAL, 'Admin password')
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'First name')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Last name')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Create Admin User');

        // Get or prompt for username
        $username = $input->getArgument('username');
        if (!$username) {
            $username = $io->ask('Enter admin username', 'admin');
        }

        // Get or prompt for email
        $email = $input->getArgument('email');
        if (!$email) {
            $email = $io->ask('Enter admin email', 'admin@norsu.edu.ph');
        }

        // Get or prompt for password
        $password = $input->getArgument('password');
        if (!$password) {
            $password = $io->askHidden('Enter admin password (min 8 characters)');
            $confirmPassword = $io->askHidden('Confirm password');

            if ($password !== $confirmPassword) {
                $io->error('Passwords do not match!');
                return Command::FAILURE;
            }

            if (strlen($password) < 8) {
                $io->error('Password must be at least 8 characters long!');
                return Command::FAILURE;
            }
        }

        // Get optional name fields
        $firstName = $input->getOption('first-name') ?: $io->ask('Enter first name', 'Admin');
        $lastName = $input->getOption('last-name') ?: $io->ask('Enter last name', 'User');

        // Check if user already exists by username or email
        $existingUser = $this->entityManager->getRepository(User::class)
            ->findOneBy(['username' => $username]);
        
        if (!$existingUser) {
            $existingUser = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);
        }

        if ($existingUser) {
            // Update existing user's password and ensure it's active
            $io->note("User '{$username}' already exists. Updating password and ensuring admin role...");
            
            $hashedPassword = $this->passwordHasher->hashPassword($existingUser, $password);
            $existingUser->setPassword($hashedPassword);
            $existingUser->setRole(1);
            $existingUser->setIsActive(true);
            
            $this->entityManager->flush();
            
            $io->success('Admin user password updated successfully!');
        } else {
            // Create new admin user
            $admin = new User();
            $admin->setUsername($username);
            $admin->setEmail($email);
            $admin->setFirstName($firstName);
            $admin->setLastName($lastName);
            $admin->setRole(1); // 1 = Admin role
            $admin->setIsActive(true);
            
            // Hash the password
            $hashedPassword = $this->passwordHasher->hashPassword($admin, $password);
            $admin->setPassword($hashedPassword);

            // Persist the user
            $this->entityManager->persist($admin);
            $this->entityManager->flush();

            $io->success('Admin user created successfully!');
        }
        
        $io->table(
            ['Field', 'Value'],
            [
                ['Username', $username],
                ['Email', $email],
                ['First Name', $firstName],
                ['Last Name', $lastName],
                ['Role', 'ROLE_ADMIN'],
            ]
        );

        $io->note([
            'You can now login with these credentials:',
            "Username: {$username}",
            "Email: {$email}",
            'Password: [the password you just entered]',
        ]);

        return Command::SUCCESS;
    }
}
