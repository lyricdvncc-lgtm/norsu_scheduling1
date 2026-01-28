<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Checks if a user account is active before allowing authentication
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Check if user account is active
        if (!$user->isActive()) {
            throw new DisabledException(
                'This account has been deactivated. Please contact the administrator for assistance.'
            );
        }

        // Check if account is soft-deleted
        if ($user->getDeletedAt() !== null) {
            throw new DisabledException(
                'This account has been deactivated. Please contact the administrator for assistance.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Additional check after authentication if needed
        // This is called after the user has been authenticated
    }
}
