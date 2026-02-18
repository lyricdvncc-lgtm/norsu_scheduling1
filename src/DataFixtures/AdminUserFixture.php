<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminUserFixture extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Check if admin already exists
        $existingAdmin = $manager->getRepository(User::class)->findOneBy(['email' => 'admin@norsu.edu.ph']);
        
        if ($existingAdmin) {
            // Admin already exists, skip
            return;
        }

        $admin = new User();
        $admin->setUsername('admin');
        $admin->setEmail('admin@norsu.edu.ph');
        $admin->setFirstName('System');
        $admin->setLastName('Administrator');
        $admin->setRole(1); // 1 = Administrator
        $admin->setIsActive(true);
        
        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($admin, 'Admin@123456');
        $admin->setPassword($hashedPassword);

        $manager->persist($admin);
        $manager->flush();

        echo "✅ Admin user created: admin@norsu.edu.ph (password: Admin@123456)\n";
    }
}
