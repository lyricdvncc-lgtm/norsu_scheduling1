<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Entity\User;
use App\Repository\UserRepository;

class AppAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UserRepository $userRepository,
        private RequestStack $requestStack
    )
    {
    }

    public function authenticate(Request $request): Passport
    {
        $formData = $request->getPayload()->all();
        $email = $formData['login_form']['email'] ?? '';

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        // Check if user account is active before authentication
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user && (!$user->isActive() || $user->getDeletedAt() !== null)) {
            throw new CustomUserMessageAuthenticationException(
                'This account has been deactivated. Please contact the administrator for assistance.'
            );
        }

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($formData['login_form']['password'] ?? ''),
            [
                new CsrfTokenBadge('authenticate', $formData['login_form']['_token'] ?? ''),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Role-based redirect logic
        $user = $token->getUser();
        
        if ($user instanceof User) {
            // Clear any saved target path to prevent redirecting to previous pages (like PDFs)
            // Always redirect to role-based dashboard after login
            $this->removeTargetPath($request->getSession(), $firewallName);

            $role = $user->getRole();
            
            // Handle null or invalid role - redirect to home with error
            if ($role === null || !in_array($role, [1, 2, 3])) {
                $request->getSession()->set('_flash_error', 
                    'Your account does not have a valid role assigned. Please contact the administrator.'
                );
                return new RedirectResponse($this->urlGenerator->generate('app_home'));
            }

            // Redirect based on user role
            switch ($role) {
                case 1: // Admin
                    return new RedirectResponse($this->urlGenerator->generate('admin_dashboard'));
                case 2: // Department Head
                    return new RedirectResponse($this->urlGenerator->generate('department_head_dashboard'));
                case 3: // Faculty
                    return new RedirectResponse($this->urlGenerator->generate('faculty_dashboard'));
                default:
                    return new RedirectResponse($this->urlGenerator->generate('app_home'));
            }
        }

        // If something went wrong, redirect to home
        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Store the error message in the session
        if ($exception instanceof DisabledException || 
            ($exception instanceof CustomUserMessageAuthenticationException && 
             str_contains($exception->getMessage(), 'deactivated'))) {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        } else {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        }

        return new RedirectResponse($this->getLoginUrl($request));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}