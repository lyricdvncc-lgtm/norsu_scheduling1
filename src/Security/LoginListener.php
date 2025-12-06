<?php

namespace App\Security;

use App\Entity\User;
use App\Service\ActivityLogService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class LoginListener implements EventSubscriberInterface
{
    private ActivityLogService $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        
        if ($user instanceof User) {
            $this->activityLogService->logUserActivity('user.login', $user, [
                'login_time' => (new \DateTime())->format('Y-m-d H:i:s'),
                'role' => $user->getRoleDisplayName()
            ]);
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        
        if ($token && $token->getUser() instanceof User) {
            $user = $token->getUser();
            $this->activityLogService->logUserActivity('user.logout', $user, [
                'logout_time' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);
        }
    }
}
