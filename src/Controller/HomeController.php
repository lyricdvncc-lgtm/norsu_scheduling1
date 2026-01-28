<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Redirect authenticated users to their appropriate dashboard based on role hierarchy
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_dashboard');
        }
        
        if ($this->isGranted('ROLE_DEPARTMENT_HEAD')) {
            return $this->redirectToRoute('department_head_dashboard');
        }
        
        if ($this->isGranted('ROLE_FACULTY')) {
            return $this->redirectToRoute('faculty_dashboard');
        }
        
        // For authenticated users without proper roles, show a message
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            if ($user && ($user->getRole() === null || !in_array($user->getRole(), [1, 2, 3]))) {
                $this->addFlash('error', 'Your account does not have a valid role assigned. Please contact the administrator to assign a role to your account.');
            }
        }
        
        // Fallback for other authenticated users or guests
        return $this->render('home/index.html.twig', [
            'page_title' => 'Welcome to Smart Scheduling System'
        ]);
    }

    #[Route('/profile', name: 'app_profile')]
    public function profile(): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        
        return $this->json([
            'user' => [
                'email' => $user?->getUserIdentifier(),
                'roles' => $user?->getRoles(),
                'role_string' => $user?->getRoleString(),
                'role_number' => $user?->getRole(),
            ]
        ]);
    }
}