<?php

namespace App\EventSubscriber;

use App\Service\SystemSettingsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Checks on every admin request whether the current semester has expired.
 * If so, auto-transitions to the next semester and flashes a notification.
 *
 * This acts as a real-time safety net — even without a cron job, the transition
 * happens on the very first admin page load after the end date passes.
 */
class SemesterAutoTransitionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SystemSettingsService $systemSettingsService,
        private TokenStorageInterface $tokenStorage,
        private RequestStack $requestStack,
        private ?LoggerInterface $logger = null
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run after security (priority < 8) so we have the authenticated user
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Only run on admin routes (not AJAX, not login, not API)
        if (!str_starts_with($path, '/admin')) {
            return;
        }

        // Skip AJAX/JSON requests to avoid side effects
        if ($request->isXmlHttpRequest() || $request->headers->get('Content-Type') === 'application/json') {
            return;
        }

        // Must be an authenticated user
        $token = $this->tokenStorage->getToken();
        if (!$token || !$token->getUser()) {
            return;
        }

        // Throttle: only check once per session/minute to avoid multiple DB queries
        $session = $request->getSession();
        $lastCheck = $session->get('_semester_auto_check', 0);
        $now = time();
        if (($now - $lastCheck) < 60) {
            return; // Already checked within the last minute
        }
        $session->set('_semester_auto_check', $now);

        // Grace period: skip auto-transition for 5 minutes after a manual semester change
        // This prevents the subscriber from immediately undoing the user's selection
        $manualSetAt = $session->get('_semester_manual_set_at', 0);
        if (($now - $manualSetAt) < 300) {
            return; // Manual change within the last 5 minutes — don't auto-transition
        }

        try {
            $result = $this->systemSettingsService->checkAndAutoTransition();

            if ($result === null) {
                return; // No transition needed
            }

            if ($result['transitioned']) {
                // Flash a notification to the user
                if ($session instanceof FlashBagAwareSessionInterface) {
                    $session->getFlashBag()->add('info', sprintf(
                        'The semester was automatically transitioned from "%s" to "%s" because the end date has passed.',
                        $result['from'],
                        $result['to']
                    ));
                }

                // Update the session's semester filter
                $activeYear = $this->systemSettingsService->getActiveAcademicYear();
                if ($activeYear) {
                    $session->set('semester_filter', $activeYear->getCurrentSemester());
                }

                $this->logger?->info(sprintf(
                    'Auto-transition triggered via web request: "%s" → "%s"',
                    $result['from'],
                    $result['to']
                ));
            } elseif (!empty($result['reason'])) {
                // Transition needed but couldn't complete — warn admin
                if ($session instanceof FlashBagAwareSessionInterface) {
                    $session->getFlashBag()->add('warning', sprintf(
                        'The current semester has expired but could not auto-transition: %s',
                        $result['reason']
                    ));
                }
            }
        } catch (\Throwable $e) {
            // Never let the auto-transition break the admin panel
            $this->logger?->error('Semester auto-transition error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
