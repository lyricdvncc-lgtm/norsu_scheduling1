<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * This subscriber ensures users are automatically redirected to their appropriate
 * dashboard based on their role when accessing protected areas they don't have access to.
 */
class RoleRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private AuthorizationCheckerInterface $authorizationChecker,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9], // Run before security
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Skip authentication routes, API routes, and public routes
        if ($this->isPublicRoute($path)) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token || !$token->getUser() instanceof User) {
            return;
        }

        /** @var User $user */
        $user = $token->getUser();
        
        // Refresh user from database to get current status (bypass cache)
        $this->entityManager->refresh($user);
        
        // Check if user account is inactive or soft-deleted
        if (!$user->isActive() || $user->getDeletedAt() !== null) {
            // Clear token first
            $this->tokenStorage->setToken(null);
            
            // Get session and invalidate it
            $session = $request->getSession();
            $session->invalidate();
            
            // Redirect to login
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
            return;
        }
        
        $role = $user->getRole();

        // If user has null or invalid role, redirect to home
        if ($role === null || !in_array($role, [1, 2, 3])) {
            if (!str_starts_with($path, '/login') && $path !== '/') {
                $request->getSession()->set('_flash_error', 'Your account does not have a valid role assigned. Please contact the administrator.');
                $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_home')));
            }
            return;
        }

        // Auto-redirect users trying to access wrong dashboard
        $this->handleRoleBasedRedirect($event, $path, $role);
    }

    private function handleRoleBasedRedirect(RequestEvent $event, string $path, int $role): void
    {
        $redirectMap = [
            1 => [ // Admin
                'allowed_prefixes' => ['/admin'],
                'redirect_to' => 'admin_dashboard',
                'role_check' => 'ROLE_ADMIN'
            ],
            2 => [ // Department Head
                'allowed_prefixes' => ['/department-head'],
                'redirect_to' => 'department_head_dashboard',
                'role_check' => 'ROLE_DEPARTMENT_HEAD'
            ],
            3 => [ // Faculty
                'allowed_prefixes' => ['/faculty'],
                'redirect_to' => 'faculty_dashboard',
                'role_check' => 'ROLE_FACULTY'
            ],
        ];

        if (!isset($redirectMap[$role])) {
            return;
        }

        $config = $redirectMap[$role];
        
        // Check if user is trying to access another role's area
        foreach ($redirectMap as $otherRole => $otherConfig) {
            if ($otherRole === $role) {
                continue; // Skip own role
            }

            foreach ($otherConfig['allowed_prefixes'] as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    // User is trying to access another role's area, redirect to their own
                    $event->getRequest()->getSession()->set('_flash_warning', 'You don\'t have access to that area. Redirected to your dashboard.');
                    $event->setResponse(
                        new RedirectResponse($this->urlGenerator->generate($config['redirect_to']))
                    );
                    return;
                }
            }
        }
    }

    private function isPublicRoute(string $path): bool
    {
        $publicRoutes = [
            '/login',
            '/register',
            '/logout',
            '/health',
            '/_profiler',
            '/_wdt',
            '/css',
            '/js',
            '/images',
            '/build',
            '/vendor',
        ];

        foreach ($publicRoutes as $route) {
            if (str_starts_with($path, $route)) {
                return true;
            }
        }

        // Root path is also public
        return $path === '/';
    }
}
