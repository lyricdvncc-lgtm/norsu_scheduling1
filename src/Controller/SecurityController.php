<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\LoginFormType;
use App\Form\RegistrationFormType;
use App\Repository\CollegeRepository;
use App\Repository\DepartmentRepository;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        // Start session if not already started
        if (!$request->getSession()->isStarted()) {
            $request->getSession()->start();
        }

        // if ($this->getUser()) {
        //     return $this->redirectToRoute('target_path');
        // }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        
        // Check if this is a CSRF token error specifically
        if ($error && strpos($error->getMessage(), 'CSRF') !== false) {
            $this->addFlash('error', 'CSRF token error detected. Please try again.');
        }
        
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        $loginForm = $this->createForm(LoginFormType::class, [
            'email' => $lastUsername,
        ]);

        return $this->render('security/login.html.twig', [
            'loginForm' => $loginForm->createView(),
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, CollegeRepository $collegeRepository, DepartmentRepository $departmentRepository, ActivityLogService $activityLogService): Response
    {
        $user = new User();
        
        // Set default role to faculty (role = 3)
        $user->setRole(3);
        
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // encode the plain password
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            // Set updated timestamp
            $user->setUpdatedAt(new \DateTime());

            $entityManager->persist($user);
            $entityManager->flush();

            // Log the registration activity
            $activityLogService->logUserActivity('user.created', $user, [
                'source' => 'self-registration',
                'role' => 'Faculty'
            ]);

            // Add flash message
            $this->addFlash('success', 'Your account has been created successfully! You can now login.');

            return $this->redirectToRoute('app_login');
        }

        // Get all active colleges for the form
        $colleges = $collegeRepository->findBy(['isActive' => true]);
        
        // Get all active departments grouped by college for JavaScript
        $departments = $departmentRepository->findBy(['isActive' => true]);
        $departmentsByCollege = [];
        foreach ($departments as $department) {
            $collegeId = $department->getCollege()->getId();
            if (!isset($departmentsByCollege[$collegeId])) {
                $departmentsByCollege[$collegeId] = [];
            }
            $departmentsByCollege[$collegeId][] = [
                'id' => $department->getId(),
                'name' => $department->getName()
            ];
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form->createView(),
            'departments_by_college' => $departmentsByCollege,
        ]);
    }
}