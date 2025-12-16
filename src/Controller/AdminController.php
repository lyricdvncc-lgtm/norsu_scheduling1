<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\College;
use App\Entity\ActivityLog;
use App\Form\UserFormType;
use App\Form\UserEditFormType;
use App\Form\CollegeFormType;
use App\Repository\CollegeRepository;
use App\Repository\DepartmentRepository;
use App\Service\DashboardService;
use App\Service\UserService;
use App\Service\CollegeService;
use App\Service\ActivityLogService;
use App\Service\SystemSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    private DashboardService $dashboardService;
    private UserService $userService;
    private CollegeService $collegeService;
    private ActivityLogService $activityLogService;
    private CollegeRepository $collegeRepository;
    private DepartmentRepository $departmentRepository;
    private EntityManagerInterface $entityManager;
    private SystemSettingsService $systemSettingsService;

    public function __construct(
        DashboardService $dashboardService, 
        UserService $userService,
        CollegeService $collegeService,
        ActivityLogService $activityLogService,
        CollegeRepository $collegeRepository,
        DepartmentRepository $departmentRepository,
        EntityManagerInterface $entityManager,
        SystemSettingsService $systemSettingsService
    ) {
        $this->dashboardService = $dashboardService;
        $this->userService = $userService;
        $this->collegeService = $collegeService;
        $this->activityLogService = $activityLogService;
        $this->collegeRepository = $collegeRepository;
        $this->departmentRepository = $departmentRepository;
        $this->entityManager = $entityManager;
        $this->systemSettingsService = $systemSettingsService;
    }

    private function getBaseTemplateData(): array
    {
        return [
            'dashboard_data' => $this->dashboardService->getAdminDashboardData(),
            'activeSemesterDisplay' => $this->systemSettingsService->getActiveSemesterDisplay(),
            'hasActiveSemester' => $this->systemSettingsService->hasActiveSemester(),
        ];
    }

    #[Route('/', name: 'index')]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(): Response
    {
        $dashboardData = $this->dashboardService->getAdminDashboardData();

        return $this->render('admin/dashboard.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Admin Dashboard',
            'dashboard_data' => $dashboardData,
        ]));
    }
    
    #[Route('/dashboard/recent-activities', name: 'dashboard_recent_activities', methods: ['GET'])]
    public function getRecentActivities(): JsonResponse
    {
        $dashboardData = $this->dashboardService->getAdminDashboardData();
        $activities = $dashboardData['recent_activities'] ?? [];
        
        $activityData = [];
        foreach ($activities as $activity) {
            $activityData[] = [
                'description' => $activity->getDescription(),
                'userName' => $activity->getUser() ? $activity->getUser()->getFirstName() . ' ' . $activity->getUser()->getLastName() : null,
                'createdAt' => $activity->getCreatedAt()->format('M j, Y g:i A'),
                'iconClass' => $activity->getIconClass(),
                'svgIcon' => $activity->getSvgIcon(),
            ];
        }
        
        return new JsonResponse(['activities' => $activityData]);
    }

    #[Route('/activities', name: 'activities')]
    public function activities(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 50);
        $action = $request->query->get('action');
        $search = $request->query->get('search');
        
        $activityLogRepository = $this->entityManager->getRepository(ActivityLog::class);
        
        $queryBuilder = $activityLogRepository->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->orderBy('a.createdAt', 'DESC');
        
        // Filter by action type
        if ($action) {
            $queryBuilder->andWhere('a.action = :action')
                ->setParameter('action', $action);
        }
        
        // Search filter
        if ($search) {
            $queryBuilder->andWhere('a.description LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }
        
        // Count total
        $totalQuery = clone $queryBuilder;
        $total = count($totalQuery->select('a.id')->getQuery()->getResult());
        
        // Paginate
        $activities = $queryBuilder
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        
        $totalPages = ceil($total / $limit);
        
        // Get unique action types for filter
        $actionTypes = $activityLogRepository->createQueryBuilder('a')
            ->select('DISTINCT a.action')
            ->orderBy('a.action', 'ASC')
            ->getQuery()
            ->getScalarResult();
        
        return $this->render('admin/activities.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Activity Logs',
            'activities' => $activities,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $total,
                'items_per_page' => $limit,
            ],
            'filters' => [
                'action' => $action,
                'search' => $search,
            ],
            'action_types' => array_column($actionTypes, 'action'),
        ]));
    }

    #[Route('/colleges', name: 'colleges')]
    public function colleges(Request $request): Response
    {
        // Handle is_active properly
        $isActiveParam = $request->query->get('is_active');
        $isActive = null;
        if ($isActiveParam !== null && $isActiveParam !== '') {
            $isActive = $isActiveParam === '1' || $isActiveParam === 'true' || $isActiveParam === true;
        }

        $filters = [
            'page' => $request->query->getInt('page', 1),
            'limit' => $request->query->getInt('limit', 20),
            'search' => $request->query->get('search'),
            'is_active' => $isActive,
            'sort_field' => $request->query->get('sort_field', 'createdAt'),
            'sort_direction' => $request->query->get('sort_direction', 'DESC'),
        ];

        $result = $this->collegeService->getCollegesWithFilters($filters);
        $statistics = $this->collegeService->getCollegeStatistics();

        return $this->render('admin/colleges.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Colleges Management',
            'colleges' => $result['colleges'],
            'pagination' => $result,
            'filters' => $filters,
            'statistics' => $statistics,
        ]));
    }

    #[Route('/colleges/create', name: 'colleges_create')]
    public function createCollege(Request $request): Response
    {
        $college = new College();
        $form = $this->createForm(CollegeFormType::class, $college, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Handle logo file upload
                $logoFile = $form->get('logoFile')->getData();
                $logoFilename = null;
                
                if ($logoFile) {
                    $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
                    $newFilename = $safeFilename . '-' . uniqid() . '.' . $logoFile->guessExtension();
                    
                    try {
                        $logoFile->move(
                            $this->getParameter('kernel.project_dir') . '/public/images',
                            $newFilename
                        );
                        $logoFilename = $newFilename;
                    } catch (\Exception $e) {
                        $this->addFlash('error', 'Error uploading logo: ' . $e->getMessage());
                    }
                }

                $data = [
                    'code' => $college->getCode(),
                    'name' => $college->getName(),
                    'description' => $college->getDescription(),
                    'dean' => $college->getDean(),
                    'logo' => $logoFilename,
                    'isActive' => $college->isActive(),
                ];

                $createdCollege = $this->collegeService->createCollege($data);
                
                // Log the activity
                $this->activityLogService->log(
                    'college.created',
                    "College created: {$createdCollege->getName()} ({$createdCollege->getCode()})",
                    'College',
                    $createdCollege->getId(),
                    ['code' => $createdCollege->getCode(), 'dean' => $createdCollege->getDean()]
                );
                
                $this->addFlash('success', 'College has been created successfully.');
                
                return $this->redirectToRoute('admin_colleges');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating college: ' . $e->getMessage());
            }
        }

        return $this->render('admin/colleges/create.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Create New College',
            'form' => $form,
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/colleges/{id}/edit', name: 'colleges_edit')]
    public function editCollege(int $id, Request $request): Response
    {
        try {
            $college = $this->collegeService->getCollegeById($id);
        } catch (\Exception $e) {
            $this->addFlash('error', 'College not found.');
            return $this->redirectToRoute('admin_colleges');
        }

        $form = $this->createForm(CollegeFormType::class, $college, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Handle logo file upload
                $logoFile = $form->get('logoFile')->getData();
                $logoFilename = $college->getLogo(); // Keep existing logo by default
                
                if ($logoFile) {
                    // Delete old logo if it exists
                    if ($college->getLogo()) {
                        $oldLogoPath = $this->getParameter('kernel.project_dir') . '/public/images/' . $college->getLogo();
                        if (file_exists($oldLogoPath)) {
                            unlink($oldLogoPath);
                        }
                    }
                    
                    $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
                    $newFilename = $safeFilename . '-' . uniqid() . '.' . $logoFile->guessExtension();
                    
                    try {
                        $logoFile->move(
                            $this->getParameter('kernel.project_dir') . '/public/images',
                            $newFilename
                        );
                        $logoFilename = $newFilename;
                    } catch (\Exception $e) {
                        $this->addFlash('error', 'Error uploading logo: ' . $e->getMessage());
                    }
                }

                $data = [
                    'code' => $college->getCode(),
                    'name' => $college->getName(),
                    'description' => $college->getDescription(),
                    'dean' => $college->getDean(),
                    'logo' => $logoFilename,
                    'isActive' => $college->isActive(),
                ];

                $this->collegeService->updateCollege($college, $data);
                
                // Log the activity
                $this->activityLogService->log(
                    'college.updated',
                    "College updated: {$college->getName()} ({$college->getCode()})",
                    'College',
                    $college->getId(),
                    ['code' => $college->getCode()]
                );
                
                $this->addFlash('success', 'College has been updated successfully.');
                
                return $this->redirectToRoute('admin_colleges');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating college: ' . $e->getMessage());
            }
        }

        return $this->render('admin/colleges/edit.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Edit College: ' . $college->getName(),
            'form' => $form,
            'college' => $college,
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/colleges/{id}/view', name: 'colleges_view')]
    public function viewCollege(int $id): Response
    {
        try {
            $college = $this->collegeService->getCollegeById($id);
            $departments = $this->collegeService->getDepartmentsByCollege($college);
        } catch (\Exception $e) {
            $this->addFlash('error', 'College not found.');
            return $this->redirectToRoute('admin_colleges');
        }

        return $this->render('admin/colleges/view.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'College Details: ' . $college->getName(),
            'college' => $college,
            'departments' => $departments,
        ]));
    }

    #[Route('/colleges/{id}/delete', name: 'colleges_delete', methods: ['POST'])]
    public function deleteCollege(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_college_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_colleges');
        }

        try {
            $college = $this->collegeService->getCollegeById($id);
            $collegeName = $college->getName();
            $collegeCode = $college->getCode();
            
            $this->collegeService->deleteCollege($college);
            
            // Log the activity
            $this->activityLogService->log(
                'college.deleted',
                "College deleted: {$collegeName} ({$collegeCode})",
                'College',
                $id
            );
            
            $this->addFlash('success', 'College has been deleted successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deleting college: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_colleges');
    }

    #[Route('/colleges/{id}/activate', name: 'colleges_activate', methods: ['POST'])]
    public function activateCollege(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('activate_college_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_colleges');
        }

        try {
            $college = $this->collegeService->getCollegeById($id);
            $this->collegeService->activateCollege($college);
            $this->addFlash('success', 'College has been activated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error activating college: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_colleges');
    }

    #[Route('/colleges/{id}/deactivate', name: 'colleges_deactivate', methods: ['POST'])]
    public function deactivateCollege(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('deactivate_college_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_colleges');
        }

        try {
            $college = $this->collegeService->getCollegeById($id);
            $this->collegeService->deactivateCollege($college);
            $this->addFlash('success', 'College has been deactivated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deactivating college: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_colleges');
    }

    #[Route('/api/colleges/bulk-action', name: 'api_colleges_bulk_action', methods: ['POST'])]
    public function bulkCollegeAction(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $action = $data['action'] ?? null;
        $collegeIds = $data['college_ids'] ?? [];

        if (!in_array($action, ['activate', 'deactivate', 'delete'])) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
        }

        if (empty($collegeIds)) {
            return new JsonResponse(['success' => false, 'message' => 'No colleges selected.'], 400);
        }

        try {
            $count = 0;
            switch ($action) {
                case 'activate':
                    $count = $this->collegeService->bulkActivateColleges($collegeIds);
                    break;
                case 'deactivate':
                    $count = $this->collegeService->bulkDeactivateColleges($collegeIds);
                    break;
                case 'delete':
                    $count = $this->collegeService->bulkDeleteColleges($collegeIds);
                    break;
            }

            return new JsonResponse([
                'success' => true,
                'message' => "{$count} colleges {$action}d successfully.",
                'count' => $count
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error performing bulk action: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/colleges/check-code', name: 'api_colleges_check_code', methods: ['POST'])]
    public function checkCollegeCode(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;
        $excludeId = $data['exclude_id'] ?? null;

        if (!$code) {
            return new JsonResponse(['available' => false, 'message' => 'Missing college code.']);
        }

        $available = $this->collegeService->isCodeAvailable($code, $excludeId);

        return new JsonResponse([
            'available' => $available,
            'message' => $available ? 'Code is available' : 'College code is already taken.'
        ]);
    }

    // Department Management Routes

    #[Route('/departments', name: 'departments')]
    public function departments(Request $request, \App\Service\DepartmentService $departmentService): Response
    {
        // Handle is_active properly
        $isActiveParam = $request->query->get('is_active');
        $isActive = null;
        if ($isActiveParam !== null && $isActiveParam !== '') {
            $isActive = $isActiveParam === '1' || $isActiveParam === 'true' || $isActiveParam === true;
        }

        $filters = [
            'search' => $request->query->get('search'),
            'is_active' => $isActive,
            'college_id' => $request->query->get('college_id'),
            'sort' => $request->query->get('sort', 'name'),
            'dir' => $request->query->get('dir', 'ASC'),
        ];

        $departments = $departmentService->getDepartments($filters);
        $statistics = $departmentService->getDepartmentStatistics();
        $colleges = $this->collegeRepository->findBy(['deletedAt' => null], ['name' => 'ASC']);

        return $this->render('admin/departments.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Departments Management',
            'departments' => $departments,
            'statistics' => $statistics,
            'colleges' => $colleges,
            'filters' => $filters,
        ]));
    }

    #[Route('/departments/create', name: 'departments_create')]
    public function createDepartment(Request $request, \App\Service\DepartmentService $departmentService): Response
    {
        $department = new \App\Entity\Department();
        $department->setIsActive(true);
        
        $form = $this->createForm(\App\Form\DepartmentFormType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check if code is unique
            if (!$departmentService->isCodeUnique($department->getCode())) {
                $this->addFlash('error', 'Department code already exists.');
                return $this->render('admin/departments/create.html.twig', array_merge($this->getBaseTemplateData(), [
                    'page_title' => 'Create Department',
                    'form' => $form->createView(),
                ]));
            }

            try {
                $departmentService->createDepartment($department);
                
                // Log the activity
                $this->activityLogService->log(
                    'department.created',
                    "Department created: {$department->getName()} ({$department->getCode()})",
                    'Department',
                    $department->getId(),
                    [
                        'code' => $department->getCode(),
                        'college' => $department->getCollege() ? $department->getCollege()->getName() : null
                    ]
                );
                
                $this->addFlash('success', 'Department created successfully.');
                return $this->redirectToRoute('admin_departments');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating department: ' . $e->getMessage());
            }
        }

        return $this->render('admin/departments/create.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Create Department',
            'form' => $form->createView(),
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/departments/{id}/edit', name: 'departments_edit')]
    public function editDepartment(int $id, Request $request, \App\Service\DepartmentService $departmentService): Response
    {
        $department = $departmentService->getDepartmentById($id);

        if (!$department) {
            $this->addFlash('error', 'Department not found.');
            return $this->redirectToRoute('admin_departments');
        }

        // DEBUG: Log department data
        error_log("=== EDIT DEPARTMENT DEBUG ===");
        error_log("Department ID: " . $department->getId());
        error_log("Department Code: " . $department->getCode());
        error_log("Department Name: " . $department->getName());
        error_log("Department College: " . ($department->getCollege() ? $department->getCollege()->getName() : 'NULL'));
        error_log("Request Method: " . $request->getMethod());
        error_log("Request Query: " . json_encode($request->query->all()));

        // Create form with the department data
        $form = $this->createForm(\App\Form\DepartmentFormType::class, $department);
        
        // DEBUG: Check form data before handleRequest
        error_log("Form Data BEFORE handleRequest: " . json_encode($form->getData()));
        
        // Only handle request if it's a POST (form submission)
        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
            
            // DEBUG: Check form data after handleRequest
            error_log("Form Data AFTER handleRequest: " . json_encode($form->getData()));

            if ($form->isSubmitted() && $form->isValid()) {
                // Check if code is unique (excluding current department)
                if (!$departmentService->isCodeUnique($department->getCode(), $id)) {
                    $this->addFlash('error', 'Department code already exists.');
                    return $this->render('admin/departments/edit.html.twig', array_merge($this->getBaseTemplateData(), [
                        'page_title' => 'Edit Department',
                        'form' => $form->createView(),
                        'department' => $department,
                    ]));
                }

                try {
                    $departmentService->updateDepartment($department);
                    
                    // Log the activity
                    $this->activityLogService->log(
                        'department.updated',
                        "Department updated: {$department->getName()} ({$department->getCode()})",
                        'Department',
                        $department->getId(),
                        ['code' => $department->getCode()]
                    );
                    
                    $this->addFlash('success', 'Department updated successfully.');
                    return $this->redirectToRoute('admin_departments');
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Error updating department: ' . $e->getMessage());
                }
            }
        }

        return $this->render('admin/departments/edit.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Edit Department',
            'form' => $form->createView(),
            'department' => $department,
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/departments/{id}', name: 'departments_view')]
    public function viewDepartment(int $id, \App\Service\DepartmentService $departmentService): Response
    {
        $department = $departmentService->getDepartmentById($id);

        if (!$department) {
            $this->addFlash('error', 'Department not found.');
            return $this->redirectToRoute('admin_departments');
        }

        return $this->render('admin/departments/view.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Department Details',
            'department' => $department,
        ]));
    }

    #[Route('/departments/{id}/delete', name: 'departments_delete', methods: ['POST'])]
    public function deleteDepartment(int $id, Request $request, \App\Service\DepartmentService $departmentService): Response
    {
        if (!$this->isCsrfTokenValid('delete_department_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_departments');
        }

        try {
            $department = $departmentService->getDepartmentById($id);
            $departmentName = $department->getName();
            $departmentCode = $department->getCode();
            
            $departmentService->deleteDepartment($department);
            
            // Log the activity
            $this->activityLogService->log(
                'department.deleted',
                "Department deleted: {$departmentName} ({$departmentCode})",
                'Department',
                $id
            );
            
            $this->addFlash('success', 'Department has been deleted successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deleting department: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_departments');
    }

    #[Route('/departments/{id}/toggle-status', name: 'departments_toggle_status', methods: ['POST'])]
    public function toggleDepartmentStatus(int $id, Request $request, \App\Service\DepartmentService $departmentService): Response
    {
        if (!$this->isCsrfTokenValid('toggle_status_department_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_departments');
        }

        try {
            $department = $departmentService->getDepartmentById($id);
            $departmentService->toggleDepartmentStatus($department);
            $status = $department->getIsActive() ? 'activated' : 'deactivated';
            $this->addFlash('success', "Department has been {$status} successfully.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error toggling department status: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_departments');
    }

    // Curricula Management Routes

    #[Route('/curricula', name: 'curricula')]
    public function curricula(Request $request, \App\Service\CurriculumService $curriculumService): Response
    {
        // Get all departments with curriculum counts
        $departments = $this->departmentRepository->findBy(['deletedAt' => null], ['name' => 'ASC']);
        $statistics = $curriculumService->getCurriculumStatistics();
        
        // Get curriculum count per department
        $departmentStats = [];
        foreach ($departments as $dept) {
            $deptCurricula = $curriculumService->getCurricula(['department_id' => $dept->getId()]);
            $published = count(array_filter($deptCurricula, fn($c) => $c->isPublished()));
            $draft = count($deptCurricula) - $published;
            
            $departmentStats[$dept->getId()] = [
                'total' => count($deptCurricula),
                'published' => $published,
                'draft' => $draft,
            ];
        }

        return $this->render('admin/curricula.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Curricula Management',
            'departments' => $departments,
            'department_stats' => $departmentStats,
            'statistics' => $statistics,
        ]));
    }

    #[Route('/curricula/department/{departmentId}', name: 'curricula_by_department', requirements: ['departmentId' => '\d+'])]
    public function curriculaByDepartment(int $departmentId, Request $request, \App\Service\CurriculumService $curriculumService, \App\Service\DepartmentService $departmentService): Response
    {
        // Prevent timeout for large datasets
        set_time_limit(120);
        
        // LOG: Debug method call
        error_log("curriculaByDepartment called for departmentId: " . $departmentId);
        error_log("Request URI: " . $request->getRequestUri());
        
        $department = $departmentService->getDepartmentById($departmentId);
        
        if (!$department) {
            $this->addFlash('error', 'Department not found.');
            return $this->redirectToRoute('admin_curricula');
        }

        // Use system-wide active semester as the filter
        $selectedSemester = $this->systemSettingsService->getActiveSemester();

        // Handle is_published properly
        $isPublishedParam = $request->query->get('is_published');
        $isPublished = null;
        if ($isPublishedParam !== null && $isPublishedParam !== '') {
            $isPublished = $isPublishedParam === '1' || $isPublishedParam === 'true' || $isPublishedParam === true;
        }

        $filters = [
            'search' => $request->query->get('search'),
            'is_published' => $isPublished,
            'department_id' => $departmentId,
            'sort' => $request->query->get('sort', 'createdAt'),
            'dir' => $request->query->get('dir', 'DESC'),
        ];

        $curricula = $curriculumService->getCurricula($filters);
        
        // Get department-specific statistics
        $published = count(array_filter($curricula, fn($c) => $c->isPublished()));
        $draft = count($curricula) - $published;
        
        $deptStatistics = [
            'total' => count($curricula),
            'published' => $published,
            'draft' => $draft,
        ];

        return $this->render('admin/curricula/by_department.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => $department->getName() . ' - Curricula',
            'department' => $department,
            'curricula' => $curricula,
            'statistics' => $deptStatistics,
            'filters' => $filters,
            'selectedSemester' => $selectedSemester,
        ]));
    }

    #[Route('/curricula/department/{departmentId}/semester/{semester}', name: 'curricula_by_department_semester', requirements: ['departmentId' => '\d+', 'semester' => '.+'])]
    public function curriculaByDepartmentSemester(
        int $departmentId, 
        string $semester,
        Request $request, 
        \App\Service\CurriculumService $curriculumService, 
        \App\Service\DepartmentService $departmentService,
        \App\Repository\CurriculumTermRepository $curriculumTermRepository
    ): Response
    {
        // LOG: Debug what semester value we received
        error_log("curriculaByDepartmentSemester called with semester: '" . $semester . "' (length: " . strlen($semester) . ")");
        error_log("Request URI: " . $request->getRequestUri());
        
        // Validate semester parameter - redirect if empty or whitespace only
        $semester = trim($semester);
        if (empty($semester)) {
            error_log("Semester is empty after trim, redirecting to department page");
            return $this->redirectToRoute('admin_curricula_by_department', ['departmentId' => $departmentId]);
        }

        $department = $departmentService->getDepartmentById($departmentId);
        
        if (!$department) {
            $this->addFlash('error', 'Department not found.');
            return $this->redirectToRoute('admin_curricula');
        }

        // System-wide semester is now managed in System Settings
        // Redirect users to System Settings to change the active semester
        $this->addFlash('info', 'To change the semester filter, please use System Settings.');
        return $this->redirectToRoute('admin_system_settings');
    }

    /**
     * Catch-all route for malformed department curricula URLs
     * This handles cases where someone tries to access /admin/curricula/department/ without an ID
     */
    #[Route('/curricula/department', name: 'curricula_by_department_catchall', priority: -1)]
    public function curriculaByDepartmentCatchall(): Response
    {
        $this->addFlash('info', 'Showing all departments. Select a specific department to view its curricula.');
        return $this->redirectToRoute('admin_curricula');
    }
    
    /**
     * Catch-all route for semester URLs with empty/invalid semester parameter
     * Handles /admin/curricula/department/{id}/semester/ (trailing slash, no semester value)
     */
    #[Route('/curricula/department/{departmentId}/semester', name: 'curricula_by_department_semester_empty', requirements: ['departmentId' => '\d+'], priority: -1)]
    public function curriculaByDepartmentSemesterEmpty(int $departmentId, Request $request): Response
    {
        error_log("Empty semester route hit for departmentId: " . $departmentId);
        error_log("Request URI: " . $request->getRequestUri());
        
        // System-wide semester is managed in System Settings
        $this->addFlash('info', 'Semester filtering is now system-wide. Use System Settings to change it.');
        return $this->redirectToRoute('admin_curricula_by_department', ['departmentId' => $departmentId]);
    }

    #[Route('/curricula/create', name: 'curricula_create', methods: ['GET', 'POST'])]
    public function createCurriculum(Request $request, \App\Service\CurriculumService $curriculumService, \App\Service\DepartmentService $departmentService): Response
    {
        $curriculum = new \App\Entity\Curriculum();
        $curriculum->setIsPublished(false);
        
        // Check if department is pre-selected (coming from department view)
        $departmentId = $request->query->get('department_id');
        $showDepartmentFields = false; // By default, don't show department/college fields
        
        if ($departmentId) {
            // Department is already known - pre-fill and hide the selection fields
            $department = $departmentService->getDepartmentById($departmentId);
            if ($department) {
                $curriculum->setDepartment($department);
                // Don't show department fields since we already know the department
                $showDepartmentFields = false;
            }
        } else {
            // No department specified - this is a global admin view, show all fields
            // Only show department fields if no department is specified
            $showDepartmentFields = true;
        }
        
        $form = $this->createForm(\App\Form\CurriculumFormType::class, $curriculum, [
            'show_department_fields' => $showDepartmentFields
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $curriculumService->createCurriculum($curriculum);
                $this->addFlash('success', 'Curriculum created successfully.');
                
                // Redirect back to department curricula page if we came from there
                if ($curriculum->getDepartment()) {
                    return $this->redirectToRoute('admin_curricula_by_department', ['departmentId' => $curriculum->getDepartment()->getId()]);
                }
                return $this->redirectToRoute('admin_curricula');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating curriculum: ' . $e->getMessage());
            }
        }

        // Prepare data for JavaScript cascading dropdown (only if showing department fields)
        $departmentsByCollege = [];
        if ($showDepartmentFields) {
            $departments = $this->departmentRepository->findBy(['deletedAt' => null], ['name' => 'ASC']);
            foreach ($departments as $dept) {
                if ($dept->getCollege()) {
                    $collegeId = $dept->getCollege()->getId();
                    if (!isset($departmentsByCollege[$collegeId])) {
                        $departmentsByCollege[$collegeId] = [];
                    }
                    $departmentsByCollege[$collegeId][$dept->getId()] = $dept->getName() . ' (' . $dept->getCode() . ')';
                }
            }
        }

        return $this->render('admin/curricula/create.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Create Curriculum',
            'form' => $form->createView(),
            'departments_by_college' => $departmentsByCollege,
            'show_department_fields' => $showDepartmentFields,
            'department_id' => $departmentId, // Pass to template for context
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/curricula/{id}/edit', name: 'curricula_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editCurriculum(int $id, Request $request, \App\Service\CurriculumService $curriculumService): Response
    {
        $curriculum = $curriculumService->getCurriculumById($id);

        if (!$curriculum) {
            $this->addFlash('error', 'Curriculum not found.');
            return $this->redirectToRoute('admin_curricula');
        }

        $form = $this->createForm(\App\Form\CurriculumFormType::class, $curriculum);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $curriculumService->updateCurriculum($curriculum);
                $this->addFlash('success', 'Curriculum updated successfully.');
                
                // Redirect back to department curricula page
                if ($curriculum->getDepartment()) {
                    return $this->redirectToRoute('admin_curricula_by_department', ['departmentId' => $curriculum->getDepartment()->getId()]);
                }
                return $this->redirectToRoute('admin_curricula');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating curriculum: ' . $e->getMessage());
            }
        }

        // Group departments by college for cascading dropdown
        $departments = $this->departmentRepository->findBy(['deletedAt' => null], ['name' => 'ASC']);
        $departmentsByCollege = [];
        foreach ($departments as $dept) {
            if ($dept->getCollege()) {
                $collegeId = $dept->getCollege()->getId();
                if (!isset($departmentsByCollege[$collegeId])) {
                    $departmentsByCollege[$collegeId] = [];
                }
                $departmentsByCollege[$collegeId][$dept->getId()] = $dept->getName() . ' (' . $dept->getCode() . ')';
            }
        }

        return $this->render('admin/curricula/edit.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Edit Curriculum',
            'form' => $form->createView(),
            'curriculum' => $curriculum,
            'departments_by_college' => $departmentsByCollege,
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/curricula/{id}', name: 'curricula_view', requirements: ['id' => '\d+'])]
    public function viewCurriculum(int $id, \App\Service\CurriculumService $curriculumService): Response
    {
        $curriculum = $curriculumService->getCurriculumById($id);

        if (!$curriculum) {
            $this->addFlash('error', 'Curriculum not found.');
            return $this->redirectToRoute('admin_curricula');
        }

        return $this->render('admin/curricula/view.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Curriculum Details',
            'curriculum' => $curriculum,
        ]));
    }

    #[Route('/curricula/{id}/delete', name: 'curricula_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteCurriculum(int $id, Request $request, \App\Service\CurriculumService $curriculumService): Response
    {
        if (!$this->isCsrfTokenValid('delete_curriculum_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_curricula');
        }

        try {
            $curriculum = $curriculumService->getCurriculumById($id);
            $departmentId = $curriculum->getDepartment() ? $curriculum->getDepartment()->getId() : null;
            $curriculumService->deleteCurriculum($curriculum);
            $this->addFlash('success', 'Curriculum has been deleted successfully.');
            
            // Redirect back to department page with semester filter if active
            if ($departmentId) {
                $session = $request->getSession();
                $semesterFilter = $session->get('semester_filter');
                
                if ($semesterFilter && !empty(trim($semesterFilter))) {
                    return $this->redirectToRoute('admin_curricula_by_department_semester', [
                        'departmentId' => $departmentId,
                        'semester' => $semesterFilter
                    ]);
                }
                
                return $this->redirectToRoute('admin_curricula_by_department', ['departmentId' => $departmentId]);
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deleting curriculum: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_curricula');
    }

    #[Route('/curricula/{id}/toggle-publish', name: 'curricula_toggle_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleCurriculumPublish(int $id, Request $request, \App\Service\CurriculumService $curriculumService): Response
    {
        if (!$this->isCsrfTokenValid('toggle_publish_curriculum_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_curricula');
        }

        try {
            $curriculum = $curriculumService->getCurriculumById($id);
            $curriculumService->togglePublishStatus($curriculum);
            $status = $curriculum->isPublished() ? 'published' : 'unpublished';
            $this->addFlash('success', "Curriculum has been {$status} successfully.");
            
            // Redirect back to department page with semester filter if active
            if ($curriculum->getDepartment()) {
                $session = $request->getSession();
                $semesterFilter = $session->get('semester_filter');
                
                if ($semesterFilter && !empty(trim($semesterFilter))) {
                    return $this->redirectToRoute('admin_curricula_by_department_semester', [
                        'departmentId' => $curriculum->getDepartment()->getId(),
                        'semester' => $semesterFilter
                    ]);
                }
                
                return $this->redirectToRoute('admin_curricula_by_department', ['departmentId' => $curriculum->getDepartment()->getId()]);
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error toggling curriculum status: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_curricula');
    }

    // ==================== CURRICULUM SUBJECTS MANAGEMENT ====================

    #[Route('/curricula/{id}/subjects', name: 'curriculum_subjects', requirements: ['id' => '\d+'])]
    public function manageCurriculumSubjects(
        int $id,
        Request $request,
        \App\Service\CurriculumService $curriculumService,
        \App\Service\CurriculumTermService $curriculumTermService,
        \App\Service\CurriculumSubjectService $curriculumSubjectService,
        \App\Repository\CurriculumTermRepository $curriculumTermRepository
    ): Response {
        try {
            $curriculum = $curriculumService->getCurriculumById($id);
            $terms = $curriculumTermRepository->findByCurriculum($id);
            
            $statistics = [
                'total_terms' => count($terms),
                'total_subjects' => $curriculum->getTotalSubjects(),
                'total_units' => $curriculum->getTotalUnits(),
            ];

            return $this->render('admin/curricula/subjects.html.twig', array_merge($this->getBaseTemplateData(), [
                'page_title' => 'Manage Curriculum Subjects',
                'curriculum' => $curriculum,
                'terms' => $terms,
                'statistics' => $statistics,
            ]));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error loading curriculum: ' . $e->getMessage());
            return $this->redirectToRoute('admin_curricula');
        }
    }

    #[Route('/curricula/{id}/terms/add', name: 'curriculum_terms_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addCurriculumTerm(
        int $id,
        Request $request,
        \App\Service\CurriculumService $curriculumService,
        \App\Service\CurriculumTermService $curriculumTermService,
        \App\Form\CurriculumTermFormType $formType
    ): Response {
        if (!$this->isCsrfTokenValid('add_curriculum_term_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_curriculum_subjects', ['id' => $id]);
        }

        try {
            $curriculum = $curriculumService->getCurriculumById($id);
            $yearLevel = $request->request->get('year_level');
            $semester = $request->request->get('semester');
            $termName = $request->request->get('term_name');

            $curriculumTermService->createTerm($curriculum, $yearLevel, $semester, $termName);
            $this->addFlash('success', 'Term added successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error adding term: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_curriculum_subjects', ['id' => $id]);
    }

    #[Route('/curricula/{id}/terms/generate', name: 'curriculum_terms_generate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generateDefaultTerms(
        int $id,
        Request $request,
        \App\Service\CurriculumService $curriculumService,
        \App\Service\CurriculumTermService $curriculumTermService
    ): Response {
        if (!$this->isCsrfTokenValid('generate_terms_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_curriculum_subjects', ['id' => $id]);
        }

        try {
            $curriculum = $curriculumService->getCurriculumById($id);
            $years = (int) $request->request->get('years', 4);
            $curriculumTermService->generateDefaultTerms($curriculum, $years);
            $this->addFlash('success', "Default terms for {$years} years generated successfully.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error generating terms: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_curriculum_subjects', ['id' => $id]);
    }

    #[Route('/curricula/terms/{id}/delete', name: 'curriculum_terms_delete', methods: ['POST'])]
    public function deleteCurriculumTerm(
        int $id,
        Request $request,
        \App\Service\CurriculumTermService $curriculumTermService,
        \App\Repository\CurriculumTermRepository $curriculumTermRepository
    ): Response {
        $term = $curriculumTermRepository->find($id);
        if (!$term) {
            $this->addFlash('error', 'Term not found.');
            return $this->redirectToRoute('admin_curricula');
        }

        $curriculumId = $term->getCurriculum()->getId();

        if (!$this->isCsrfTokenValid('delete_term_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_curriculum_subjects', ['id' => $curriculumId]);
        }

        try {
            $curriculumTermService->deleteTerm($term);
            $this->addFlash('success', 'Term deleted successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deleting term: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_curriculum_subjects', ['id' => $curriculumId]);
    }

    #[Route('/curricula/terms/{termId}/subjects/add', name: 'curriculum_subjects_add', requirements: ['termId' => '\d+'], methods: ['POST'])]
    public function addSubjectToTerm(
        int $termId,
        Request $request,
        \App\Service\CurriculumSubjectService $curriculumSubjectService,
        \App\Repository\CurriculumTermRepository $curriculumTermRepository,
        \App\Repository\SubjectRepository $subjectRepository
    ): JsonResponse {
        $term = $curriculumTermRepository->find($termId);
        if (!$term) {
            return new JsonResponse(['success' => false, 'message' => 'Term not found.'], 404);
        }

        $subjectId = $request->request->get('subject_id');
        $subject = $subjectRepository->find($subjectId);
        
        if (!$subject) {
            return new JsonResponse(['success' => false, 'message' => 'Subject not found.'], 404);
        }

        try {
            $curriculumSubject = $curriculumSubjectService->addSubjectToTerm($term, $subject);
            return new JsonResponse([
                'success' => true,
                'message' => 'Subject added successfully.',
                'subject' => [
                    'id' => $curriculumSubject->getId(),
                    'code' => $subject->getCode(),
                    'title' => $subject->getTitle(),
                    'units' => $subject->getUnits(),
                    'type' => $subject->getType(),
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route('/curricula/subjects/{id}/remove', name: 'curriculum_subjects_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeSubjectFromTerm(
        int $id,
        Request $request,
        \App\Service\CurriculumSubjectService $curriculumSubjectService,
        \App\Repository\CurriculumSubjectRepository $curriculumSubjectRepository
    ): Response {
        $curriculumSubject = $curriculumSubjectRepository->find($id);
        if (!$curriculumSubject) {
            $this->addFlash('error', 'Curriculum subject not found.');
            return $this->redirectToRoute('admin_curricula');
        }

        $curriculumId = $curriculumSubject->getCurriculum()->getId();

        if (!$this->isCsrfTokenValid('remove_subject_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_curriculum_subjects', ['id' => $curriculumId]);
        }

        try {
            $curriculumSubjectService->removeSubjectFromTerm($curriculumSubject);
            $this->addFlash('success', 'Subject removed successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error removing subject: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_curriculum_subjects', ['id' => $curriculumId]);
    }

    #[Route('/curricula/{id}/subjects/available', name: 'curriculum_subjects_available', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getAvailableSubjects(
        int $id,
        Request $request,
        \App\Repository\SubjectRepository $subjectRepository,
        \App\Service\CurriculumService $curriculumService
    ): JsonResponse {
        try {
            $curriculum = $curriculumService->getCurriculumById($id);
            $search = $request->query->get('search', '');
            
            $subjects = $subjectRepository->findActive();
            
            // Filter by search term if provided
            if ($search) {
                $subjects = array_filter($subjects, function($subject) use ($search) {
                    $searchLower = strtolower($search);
                    return strpos(strtolower($subject->getCode()), $searchLower) !== false ||
                           strpos(strtolower($subject->getTitle()), $searchLower) !== false;
                });
            }
            
            // Convert to array format
            $subjectsArray = array_map(function($subject) {
                return [
                    'id' => $subject->getId(),
                    'code' => $subject->getCode(),
                    'title' => $subject->getTitle(),
                    'units' => $subject->getUnits(),
                    'type' => $subject->getType(),
                    'lecture_hours' => $subject->getLectureHours(),
                    'lab_hours' => $subject->getLabHours(),
                ];
            }, $subjects);
            
            return new JsonResponse(['success' => true, 'subjects' => array_values($subjectsArray)]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ==================== SUBJECT MANAGEMENT ROUTES ====================

    #[Route('/subjects', name: 'subjects')]
    public function subjects(Request $request, \App\Service\SubjectService $subjectService): Response
    {
        // Use system-wide active semester as the default filter
        $semesterFilter = $this->systemSettingsService->getActiveSemester();
        
        // Default to showing ALL subjects, not just published ones
        $publishedOnlyParam = $request->query->get('published_only');
        $publishedOnly = $publishedOnlyParam === 'published' ? '1' : ($publishedOnlyParam === 'all' ? '0' : '0');
        
        $filters = [
            'search' => $request->query->get('search', ''),
            'department_id' => $request->query->get('department_id', ''),
            'type' => $request->query->get('type', ''),
            'is_active' => $request->query->get('is_active', ''),
            'published_only' => $publishedOnly,
            'semester' => $semesterFilter,
            'sort' => $request->query->get('sort', 'code'),
            'dir' => $request->query->get('dir', 'ASC'),
        ];

        $subjects = $subjectService->getSubjects($filters);
        $statistics = $subjectService->getSubjectStatistics();
        $departments = $this->departmentRepository->findBy(['deletedAt' => null], ['name' => 'ASC']);

        return $this->render('admin/subjects.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Subject Management',
            'subjects' => $subjects,
            'statistics' => $statistics,
            'departments' => $departments,
            'filters' => $filters,
            'semesterFilter' => $semesterFilter,
        ]));
    }

    #[Route('/subjects/create', name: 'subjects_create')]
    public function createSubject(Request $request, \App\Service\SubjectService $subjectService): Response
    {
        $subject = new \App\Entity\Subject();
        $subject->setIsActive(true);
        
        $form = $this->createForm(\App\Form\SubjectFormType::class, $subject);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $subjectService->createSubject($subject);
                $this->addFlash('success', 'Subject created successfully.');
                return $this->redirectToRoute('admin_subjects');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating subject: ' . $e->getMessage());
            }
        }

        return $this->render('admin/subjects/create.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Create Subject',
            'form' => $form->createView(),
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/subjects/{id}/edit', name: 'subjects_edit')]
    public function editSubject(int $id, Request $request, \App\Service\SubjectService $subjectService): Response
    {
        $subject = $subjectService->getSubjectById($id);

        if (!$subject) {
            $this->addFlash('error', 'Subject not found.');
            return $this->redirectToRoute('admin_subjects');
        }

        $form = $this->createForm(\App\Form\SubjectFormType::class, $subject);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $subjectService->updateSubject($subject);
                $this->addFlash('success', 'Subject updated successfully.');
                return $this->redirectToRoute('admin_subjects');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating subject: ' . $e->getMessage());
            }
        }

        return $this->render('admin/subjects/edit.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Edit Subject',
            'form' => $form->createView(),
            'subject' => $subject,
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/subjects/{id}', name: 'subjects_view')]
    public function viewSubject(int $id, \App\Service\SubjectService $subjectService, DepartmentRepository $departmentRepository): Response
    {
        $subject = $subjectService->getSubjectById($id);

        if (!$subject) {
            $this->addFlash('error', 'Subject not found.');
            return $this->redirectToRoute('admin_subjects');
        }

        $department = $departmentRepository->find($subject->getDepartmentId());

        return $this->render('admin/subjects/view.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Subject Details',
            'subject' => $subject,
            'department' => $department,
        ]));
    }

    #[Route('/subjects/{id}/delete', name: 'subjects_delete', methods: ['POST'])]
    public function deleteSubject(int $id, Request $request, \App\Service\SubjectService $subjectService): Response
    {
        if (!$this->isCsrfTokenValid('delete_subject_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_subjects');
        }

        try {
            $subject = $subjectService->getSubjectById($id);
            $subjectService->deleteSubject($subject);
            $this->addFlash('success', 'Subject has been deleted successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deleting subject: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_subjects');
    }

    #[Route('/subjects/{id}/toggle-active', name: 'subjects_toggle_active', methods: ['POST'])]
    public function toggleSubjectActive(int $id, Request $request, \App\Service\SubjectService $subjectService): Response
    {
        if (!$this->isCsrfTokenValid('toggle_active_subject_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_subjects');
        }

        try {
            $subject = $subjectService->getSubjectById($id);
            $subjectService->toggleActiveStatus($subject);
            $status = $subject->isActive() ? 'activated' : 'deactivated';
            $this->addFlash('success', 'Subject has been ' . $status . ' successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error updating subject: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_subjects');
    }

    // Removed: User-specific semester filter routes (now using system-wide active semester)

    // User Management Routes - Main Categories

    #[Route('/users/all', name: 'users_all')]
    public function allUsers(Request $request): Response
    {
        // Handle is_active properly - convert string "0" and "1" to boolean
        $isActiveParam = $request->query->get('is_active');
        $isActive = null;
        if ($isActiveParam !== null && $isActiveParam !== '') {
            $isActive = $isActiveParam === '1' || $isActiveParam === 'true' || $isActiveParam === true;
        }

        $filters = [
            'page' => $request->query->getInt('page', 1),
            'limit' => $request->query->getInt('limit', 20),
            'search' => $request->query->get('search'),
            'role' => $request->query->get('role') ? (int) $request->query->get('role') : null, // Allow filtering by role
            'is_active' => $isActive,
            'college_id' => $request->query->get('college_id') ? (int) $request->query->get('college_id') : null,
            'department_id' => $request->query->get('department_id') ? (int) $request->query->get('department_id') : null,
            'sort_field' => $request->query->get('sort_field', 'createdAt'),
            'sort_direction' => $request->query->get('sort_direction', 'DESC'),
        ];

        $result = $this->userService->getUsersWithFilters($filters);
        $statistics = $this->userService->getUserStatistics();

        // Get colleges and departments for filter dropdowns
        $colleges = $this->collegeRepository->findBy(['isActive' => true], ['name' => 'ASC']);
        $departments = $this->departmentRepository->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->render('admin/users/all.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'All Users',
            'users' => $result['users'],
            'pagination' => $result,
            'filters' => $filters,
            'statistics' => $statistics,
            'user_type' => 'all',
            'colleges' => $colleges,
            'departments' => $departments,
        ]));
    }

    #[Route('/users/administrators', name: 'users_administrators')]
    public function administrators(Request $request): Response
    {
        // Handle is_active properly - convert string "0" and "1" to boolean
        $isActiveParam = $request->query->get('is_active');
        $isActive = null;
        if ($isActiveParam !== null && $isActiveParam !== '') {
            $isActive = $isActiveParam === '1' || $isActiveParam === 'true' || $isActiveParam === true;
        }

        $filters = [
            'page' => $request->query->getInt('page', 1),
            'limit' => $request->query->getInt('limit', 20),
            'search' => $request->query->get('search'),
            'role' => 1, // Administrator role
            'is_active' => $isActive,
            'department_id' => $request->query->get('department_id') ? (int) $request->query->get('department_id') : null,
            'sort_field' => $request->query->get('sort_field', 'createdAt'),
            'sort_direction' => $request->query->get('sort_direction', 'DESC'),
        ];

        $result = $this->userService->getUsersWithFilters($filters);
        $statistics = $this->userService->getUserStatistics();

        return $this->render('admin/users/administrators.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Administrators',
            'users' => $result['users'],
            'pagination' => $result,
            'filters' => $filters,
            'statistics' => $statistics,
            'user_type' => 'administrators',
        ]));
    }

    #[Route('/users/department-heads', name: 'users_department_heads')]
    public function departmentHeads(Request $request): Response
    {
        // Handle is_active properly - convert string "0" and "1" to boolean
        $isActiveParam = $request->query->get('is_active');
        $isActive = null;
        if ($isActiveParam !== null && $isActiveParam !== '') {
            $isActive = $isActiveParam === '1' || $isActiveParam === 'true' || $isActiveParam === true;
        }

        $filters = [
            'page' => $request->query->getInt('page', 1),
            'limit' => $request->query->getInt('limit', 20),
            'search' => $request->query->get('search'),
            'role' => 2, // Department Head role
            'is_active' => $isActive,
            'college_id' => $request->query->get('college_id') ? (int) $request->query->get('college_id') : null,
            'department_id' => $request->query->get('department_id') ? (int) $request->query->get('department_id') : null,
            'sort_field' => $request->query->get('sort_field', 'createdAt'),
            'sort_direction' => $request->query->get('sort_direction', 'DESC'),
        ];

        $result = $this->userService->getUsersWithFilters($filters);
        $statistics = $this->userService->getUserStatistics();

        // Get colleges and departments for filter dropdowns
        $colleges = $this->collegeRepository->findBy(['isActive' => true], ['name' => 'ASC']);
        $departments = $this->departmentRepository->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->render('admin/users/department_heads.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Department Heads',
            'users' => $result['users'],
            'pagination' => $result,
            'filters' => $filters,
            'statistics' => $statistics,
            'user_type' => 'department_heads',
            'colleges' => $colleges,
            'departments' => $departments,
        ]));
    }

    #[Route('/users/faculty', name: 'users_faculty')]
    public function faculty(Request $request): Response
    {
        // Handle is_active properly - convert string "0" and "1" to boolean
        $isActiveParam = $request->query->get('is_active');
        $isActive = null;
        if ($isActiveParam !== null && $isActiveParam !== '') {
            $isActive = $isActiveParam === '1' || $isActiveParam === 'true' || $isActiveParam === true;
        }

        $filters = [
            'page' => $request->query->getInt('page', 1),
            'limit' => $request->query->getInt('limit', 20),
            'search' => $request->query->get('search'),
            'role' => 3, // Faculty role
            'is_active' => $isActive,
            'college_id' => $request->query->get('college_id') ? (int) $request->query->get('college_id') : null,
            'department_id' => $request->query->get('department_id') ? (int) $request->query->get('department_id') : null,
            'sort_field' => $request->query->get('sort_field', 'createdAt'),
            'sort_direction' => $request->query->get('sort_direction', 'DESC'),
        ];

        $result = $this->userService->getUsersWithFilters($filters);
        $statistics = $this->userService->getUserStatistics();

        // Get colleges and departments for filter dropdowns
        $colleges = $this->collegeRepository->findBy(['isActive' => true], ['name' => 'ASC']);
        $departments = $this->departmentRepository->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->render('admin/users/faculty.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Faculty Members',
            'users' => $result['users'],
            'pagination' => $result,
            'filters' => $filters,
            'statistics' => $statistics,
            'user_type' => 'faculty',
            'colleges' => $colleges,
            'departments' => $departments,
        ]));
    }

    #[Route('/users/faculty/{id}/teaching-history', name: 'users_faculty_history', methods: ['GET'])]
    public function facultyTeachingHistory(int $id, Request $request): Response
    {
        $faculty = $this->userService->getUserById($id);
        
        if (!$faculty || $faculty->getRole() != 3) {
            $this->addFlash('error', 'Faculty member not found.');
            return $this->redirectToRoute('admin_users_faculty');
        }

        // Get filters from query parameters
        $semesterFilter = $request->query->get('semester');
        $yearFilter = $request->query->get('year');

        // Get teaching history for this faculty member
        $scheduleRepository = $this->entityManager->getRepository(\App\Entity\Schedule::class);
        $qb = $scheduleRepository->createQueryBuilder('s')
            ->leftJoin('s.faculty', 'f')
            ->leftJoin('s.subject', 'subj')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.academicYear', 'ay')
            ->addSelect('f', 'subj', 'r', 'ay')
            ->where('s.faculty = :faculty')
            ->setParameter('faculty', $faculty)
            ->orderBy('ay.year', 'DESC')
            ->addOrderBy('s.semester', 'DESC')
            ->addOrderBy('subj.code', 'ASC');

        // Apply filters if provided
        if ($semesterFilter) {
            $qb->andWhere('s.semester = :semester')
               ->setParameter('semester', $semesterFilter);
        }
        if ($yearFilter) {
            $qb->andWhere('ay.id = :yearId')
               ->setParameter('yearId', $yearFilter);
        }

        $teachingHistory = $qb->getQuery()->getResult();

        // Calculate workload statistics
        $workloadBySemester = [];
        foreach ($teachingHistory as $schedule) {
            if ($schedule->getAcademicYear() && $schedule->getSemester() && $schedule->getSubject()) {
                $key = $schedule->getAcademicYear()->getYear() . ' - ' . $schedule->getSemester();
                if (!isset($workloadBySemester[$key])) {
                    $workloadBySemester[$key] = [
                        'year' => $schedule->getAcademicYear()->getYear(),
                        'semester' => $schedule->getSemester(),
                        'subjects' => [],
                        'total_units' => 0,
                        'schedule_count' => 0,
                    ];
                }
                
                $subjectId = $schedule->getSubject()->getId();
                if (!in_array($subjectId, $workloadBySemester[$key]['subjects'])) {
                    $workloadBySemester[$key]['subjects'][] = $subjectId;
                    $workloadBySemester[$key]['total_units'] += $schedule->getSubject()->getUnits();
                }
                $workloadBySemester[$key]['schedule_count']++;
            }
        }

        // Get all academic years for filter dropdown
        $academicYears = $this->entityManager->getRepository(\App\Entity\AcademicYear::class)
            ->createQueryBuilder('ay')
            ->where('ay.deletedAt IS NULL')
            ->orderBy('ay.year', 'DESC')
            ->getQuery()
            ->getResult();

        // Calculate unique subjects and rooms
        $uniqueSubjectIds = [];
        $uniqueRoomIds = [];
        foreach ($teachingHistory as $schedule) {
            if ($schedule->getSubject()) {
                $uniqueSubjectIds[$schedule->getSubject()->getId()] = true;
            }
            if ($schedule->getRoom()) {
                $uniqueRoomIds[$schedule->getRoom()->getId()] = true;
            }
        }

        return $this->render('admin/users/faculty_history.html.twig', [
            'faculty' => $faculty,
            'teachingHistory' => $teachingHistory,
            'workloadBySemester' => $workloadBySemester,
            'academicYears' => $academicYears,
            'semesterFilter' => $semesterFilter,
            'yearFilter' => $yearFilter,
            'uniqueSubjectCount' => count($uniqueSubjectIds),
            'uniqueRoomCount' => count($uniqueRoomIds),
        ]);
    }

    #[Route('/users/faculty/{id}/teaching-history/export', name: 'users_faculty_history_export', methods: ['GET'])]
    public function exportFacultyTeachingHistory(int $id, Request $request): Response
    {
        $faculty = $this->userService->getUserById($id);
        
        if (!$faculty || $faculty->getRole() != 3) {
            $this->addFlash('error', 'Faculty member not found.');
            return $this->redirectToRoute('admin_users_faculty');
        }

        // Get filters
        $semesterFilter = $request->query->get('semester');
        $yearFilter = $request->query->get('year');

        // Get teaching history
        $scheduleRepository = $this->entityManager->getRepository(\App\Entity\Schedule::class);
        $qb = $scheduleRepository->createQueryBuilder('s')
            ->leftJoin('s.faculty', 'f')
            ->leftJoin('s.subject', 'subj')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.academicYear', 'ay')
            ->addSelect('f', 'subj', 'r', 'ay')
            ->where('s.faculty = :faculty')
            ->setParameter('faculty', $faculty)
            ->orderBy('ay.year', 'DESC')
            ->addOrderBy('s.semester', 'DESC')
            ->addOrderBy('subj.code', 'ASC');

        if ($semesterFilter) {
            $qb->andWhere('s.semester = :semester')
               ->setParameter('semester', $semesterFilter);
        }
        if ($yearFilter) {
            $qb->andWhere('ay.id = :yearId')
               ->setParameter('yearId', $yearFilter);
        }

        $teachingHistory = $qb->getQuery()->getResult();

        // Create CSV content
        $csv = [];
        $csv[] = ['Faculty Name', 'Employee ID', 'Academic Year', 'Semester', 'Subject Code', 'Subject Name', 'Units', 'Section', 'Room', 'Day', 'Start Time', 'End Time'];

        foreach ($teachingHistory as $schedule) {
            $csv[] = [
                $faculty->getFullName(),
                $faculty->getEmployeeId() ?: 'N/A',
                $schedule->getAcademicYear() ? $schedule->getAcademicYear()->getYear() : 'N/A',
                $schedule->getSemester() ?: 'N/A',
                $schedule->getSubject() ? $schedule->getSubject()->getCode() : 'N/A',
                $schedule->getSubject() ? $schedule->getSubject()->getName() : 'N/A',
                $schedule->getSubject() ? $schedule->getSubject()->getUnits() : 'N/A',
                $schedule->getSection() ?: 'N/A',
                $schedule->getRoom() ? $schedule->getRoom()->getName() : 'N/A',
                $schedule->getDayOfWeek() ?: 'N/A',
                $schedule->getStartTime() ? $schedule->getStartTime()->format('H:i') : 'N/A',
                $schedule->getEndTime() ? $schedule->getEndTime()->format('H:i') : 'N/A',
            ];
        }

        // Generate CSV file
        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="faculty_history_' . $faculty->getEmployeeId() . '_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $response->setContent(stream_get_contents($output));
        fclose($output);

        return $response;
    }

    // Legacy route - redirect to all users
    #[Route('/users', name: 'users')]
    public function users(): Response
    {
        return $this->redirectToRoute('admin_users_all');
    }

    #[Route('/history', name: 'history', methods: ['GET'])]
    public function historyHub(Request $request): Response
    {
        // Get export parameter only
        $exportType = $request->query->get('export', '');
        $selectedYear = $request->query->get('year', '');
        $selectedSemester = $request->query->get('semester', '');
        $selectedDepartment = $request->query->get('department', '');
        $searchTerm = $request->query->get('search', '');
        
        // Get active semester for default
        $activeYear = $this->systemSettingsService->getActiveAcademicYear();
        $activeSemester = $this->systemSettingsService->getActiveSemester();
        
        // Get all academic years for filter dropdown
        $academicYears = $this->entityManager->getRepository(\App\Entity\AcademicYear::class)
            ->createQueryBuilder('ay')
            ->select('ay.year')
            ->distinct(true)
            ->orderBy('ay.year', 'DESC')
            ->getQuery()
            ->getResult();
        $years = array_column($academicYears, 'year');
        
        // Get all departments for filter dropdown
        $departments = $this->entityManager->getRepository(\App\Entity\Department::class)
            ->createQueryBuilder('d')
            ->where('d.deletedAt IS NULL')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
        
        // Load ALL rooms with schedule details (for client-side filtering)
        $rooms = $this->entityManager->getRepository(\App\Entity\Room::class)
            ->createQueryBuilder('r')
            ->orderBy('r.building', 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
        
        // Load ALL schedules with department/year/semester info
        $schedules = $this->entityManager->getRepository(\App\Entity\Schedule::class)
            ->createQueryBuilder('s')
            ->select('s', 'r', 'sub', 'd', 'u')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.subject', 'sub')
            ->leftJoin('sub.department', 'd')
            ->leftJoin('s.faculty', 'u')
            ->getQuery()
            ->getResult();
        
        // Build room data with schedule counts and department info
        $roomsData = [];
        foreach ($rooms as $room) {
            $roomSchedules = array_filter($schedules, fn($s) => $s->getRoom() && $s->getRoom()->getId() === $room->getId());
            
            // Get unique departments for this room
            $roomDepartments = [];
            $roomYears = [];
            $roomSemesters = [];
            foreach ($roomSchedules as $schedule) {
                if ($schedule->getSubject() && $schedule->getSubject()->getDepartment()) {
                    $dept = $schedule->getSubject()->getDepartment();
                    $roomDepartments[$dept->getId()] = $dept->getName();
                }
                if ($schedule->getAcademicYear()) {
                    $roomYears[] = $schedule->getAcademicYear();
                }
                if ($schedule->getSemester()) {
                    $roomSemesters[] = $schedule->getSemester();
                }
            }
            
            $roomsData[] = [
                0 => $room,
                'scheduleCount' => count($roomSchedules),
                'departments' => implode(', ', array_unique($roomDepartments)),
                'years' => implode(', ', array_unique($roomYears)),
                'semesters' => implode(', ', array_unique($roomSemesters))
            ];
        }
        
        // Load ALL faculty with basic stats first
        $facultyQuery = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->select('u', 'COUNT(s.id) as scheduleCount', 'SUM(sub.units) as totalUnits')
            ->leftJoin(\App\Entity\Schedule::class, 's', 'WITH', 's.faculty = u')
            ->leftJoin('s.subject', 'sub')
            ->where('u.role = :role')
            ->andWhere('u.isActive = :active')
            ->setParameter('role', 3)
            ->setParameter('active', true)
            ->groupBy('u.id')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC');
        
        $facultyResults = $facultyQuery->getQuery()->getResult();
        
        // Build faculty data with schedule details for filtering
        $facultyData = [];
        foreach ($facultyResults as $result) {
            $faculty = $result[0];
            $facultySchedules = array_filter($schedules, fn($s) => $s->getFaculty() && $s->getFaculty()->getId() === $faculty->getId());
            
            // Get unique departments, years, semesters for this faculty
            $facultyDepartments = [];
            $facultyYears = [];
            $facultySemesters = [];
            
            foreach ($facultySchedules as $schedule) {
                if ($schedule->getSubject() && $schedule->getSubject()->getDepartment()) {
                    $dept = $schedule->getSubject()->getDepartment();
                    $facultyDepartments[$dept->getId()] = $dept->getName();
                }
                if ($schedule->getAcademicYear()) {
                    $facultyYears[] = $schedule->getAcademicYear();
                }
                if ($schedule->getSemester()) {
                    $facultySemesters[] = $schedule->getSemester();
                }
            }
            
            $facultyData[] = [
                0 => $faculty,
                'scheduleCount' => $result['scheduleCount'],
                'totalUnits' => $result['totalUnits'],
                'departments' => implode(', ', array_unique($facultyDepartments)),
                'years' => implode(', ', array_unique($facultyYears)),
                'semesters' => implode(', ', array_unique($facultySemesters))
            ];
        }
        
        // Handle bulk exports (apply filters only for export)
        if ($exportType === 'rooms') {
            return $this->exportAllRooms($roomsData, $selectedYear, $selectedSemester);
        } elseif ($exportType === 'faculty') {
            return $this->exportAllFaculty($facultyData, $selectedYear, $selectedSemester);
        }
        
        // Create display string for active semester
        $activeSemesterDisplay = $activeYear && $activeSemester 
            ? $activeYear->getYear() . ' | ' . $activeSemester . ' Semester'
            : null;
        
        return $this->render('admin/history/index.html.twig', [
            'years' => $years,
            'departments' => $departments,
            'selectedYear' => '',
            'selectedSemester' => '',
            'selectedDepartment' => '',
            'searchTerm' => '',
            'activeYear' => $activeYear,
            'activeSemester' => $activeSemester,
            'activeSemesterDisplay' => $activeSemesterDisplay,
            'roomsData' => $roomsData,
            'facultyData' => $facultyData,
            'hasActiveSemester' => $this->systemSettingsService->hasActiveSemester(),
        ]);
    }

    private function exportAllRooms(array $roomsData, string $year, string $semester): Response
    {
        // Prepare CSV data
        $csvData = [];
        $csvData[] = ['Room', 'Building', 'Capacity', 'Schedule Count', 'Academic Year', 'Semester'];
        
        foreach ($roomsData as $item) {
            $room = $item[0];
            $scheduleCount = $item['scheduleCount'];
            
            $csvData[] = [
                $room->getName(),
                $room->getBuilding(),
                $room->getCapacity(),
                $scheduleCount,
                $year ?: 'All',
                $semester ?: 'All'
            ];
        }
        
        // Generate CSV
        $filename = 'all_rooms_history_' . ($year ?: 'all_years') . '_' . ($semester ?: 'all_semesters') . '_' . date('Y-m-d') . '.csv';
        
        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        
        $output = fopen('php://temp', 'r+');
        foreach ($csvData as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $response->setContent(stream_get_contents($output));
        fclose($output);
        
        return $response;
    }

    private function exportAllFaculty(array $facultyData, string $year, string $semester): Response
    {
        // Prepare CSV data
        $csvData = [];
        $csvData[] = ['Employee ID', 'First Name', 'Last Name', 'Position', 'Department', 'Total Units', 'Total Subjects', 'Academic Year', 'Semester'];
        
        foreach ($facultyData as $item) {
            $faculty = $item[0];
            $scheduleCount = $item['scheduleCount'];
            $totalUnits = $item['totalUnits'] ?? 0;
            
            $csvData[] = [
                $faculty->getEmployeeId(),
                $faculty->getFirstName(),
                $faculty->getLastName(),
                $faculty->getPosition(),
                $faculty->getDepartment() ? $faculty->getDepartment()->getName() : 'No Department',
                $totalUnits,
                $scheduleCount,
                $year ?: 'All',
                $semester ?: 'All'
            ];
        }
        
        // Generate CSV
        $filename = 'all_faculty_history_' . ($year ?: 'all_years') . '_' . ($semester ?: 'all_semesters') . '_' . date('Y-m-d') . '.csv';
        
        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        
        $output = fopen('php://temp', 'r+');
        foreach ($csvData as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $response->setContent(stream_get_contents($output));
        fclose($output);
        
        return $response;
    }

    // User CRUD Operations

    #[Route('/users/create', name: 'users_create')]
    public function createUser(Request $request): Response
    {
        // Ensure session is started
        if (!$request->getSession()->isStarted()) {
            $request->getSession()->start();
        }
        
        $user = new User();
        
        // Pre-select role if specified in query parameter
        $preselectedRole = $request->query->getInt('role');
        if ($preselectedRole && in_array($preselectedRole, [1, 2, 3])) {
            $user->setRole($preselectedRole);
        }
        
        $form = $this->createForm(UserFormType::class, $user, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $plainPassword = $form->get('plainPassword')->getData();
                $userData = [
                    'username' => $user->getUsername(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'email' => $user->getEmail(),
                    'employeeId' => $user->getEmployeeId(),
                    'position' => $user->getPosition(),
                    'role' => $user->getRole(),
                    'departmentId' => $user->getDepartmentId(),
                    'isActive' => $user->isActive(),
                ];

                $createdUser = $this->userService->createUser($userData, $plainPassword);
                
                // Log the activity
                $this->activityLogService->logUserActivity('user.created', $createdUser, [
                    'role' => $createdUser->getRoleDisplayName(),
                    'department' => $createdUser->getDepartment() ? $createdUser->getDepartment()->getName() : null
                ]);
                
                $this->addFlash('success', 'User has been created successfully.');
                
                // Redirect to appropriate user list based on role
                return match($createdUser->getRole()) {
                    1 => $this->redirectToRoute('admin_users_administrators'),
                    2 => $this->redirectToRoute('admin_users_department_heads'),
                    3 => $this->redirectToRoute('admin_users_faculty'),
                    default => $this->redirectToRoute('admin_users_all')
                };
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating user: ' . $e->getMessage());
            }
        } elseif ($form->isSubmitted() && !$form->isValid()) {
            // Debug: Log form errors
            $errors = $form->getErrors(true);
            $errorCount = 0;
            
            /** @var \Symfony\Component\Form\FormError $error */
            foreach ($errors as $error) {
                $errorCount++;
                $errorText = $error->getMessage();
                if (str_contains((string)$errorText, 'CSRF token')) {
                    $this->addFlash('error', 'Form Validation Errors: • The CSRF token is invalid. Please try to resubmit the form.');
                } else {
                    $this->addFlash('error', 'Form Validation Errors: • ' . $errorText);
                }
            }
            
            // Debug info for troubleshooting
            if ($errorCount === 0) {
                $this->addFlash('info', 'Form submitted but validation failed with no specific error messages.');
            }
        }

        // Add a unique cache-buster to prevent browser form restoration dialog
        // If no 't' parameter exists, redirect to add one
        if (!$request->query->has('t')) {
            return $this->redirectToRoute('admin_users_create', ['t' => time()]);
        }

        return $this->render('admin/users/create.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Create New User',
            'form' => $form,
            'preselected_role' => $preselectedRole,
            'departments_by_college' => $this->getDepartmentsByCollege(),
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/users/{id}/edit', name: 'users_edit')]
    public function editUser(int $id, Request $request): Response
    {
        try {
            $user = $this->userService->getUserById($id);
        } catch (\Exception $e) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('admin_users_all');
        }

        // Determine return URL
        $returnUrl = $request->query->get('return_url');
        if (!$returnUrl) {
            $referer = $request->headers->get('referer');
            if ($referer && str_contains($referer, '/admin/users/')) {
                $returnUrl = $referer;
            } else {
                // Default return URL based on user role
                $returnUrl = match($user->getRole()) {
                    1 => $this->generateUrl('admin_users_administrators'),
                    2 => $this->generateUrl('admin_users_department_heads'),
                    3 => $this->generateUrl('admin_users_faculty'),
                    default => $this->generateUrl('admin_users_all')
                };
            }
        }

        $form = $this->createForm(UserEditFormType::class, $user, ['include_password_reset' => true]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $newPassword = $form->get('newPassword')->getData();
                
                // Get college from form (it's unmapped now)
                $college = $form->get('college')->getData();
                
                $userData = [
                    'username' => $user->getUsername(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'email' => $user->getEmail(),
                    'employeeId' => $user->getEmployeeId(),
                    'position' => $user->getPosition(),
                    'role' => $user->getRole(),
                    'collegeId' => $college ? $college->getId() : null,
                    'departmentId' => $user->getDepartment() ? $user->getDepartment()->getId() : null,
                    'isActive' => $user->isActive(),
                ];

                $this->userService->updateUser($user, $userData, $newPassword);
                
                // Log the activity
                $this->activityLogService->logUserActivity('user.updated', $user, [
                    'updated_fields' => array_keys($userData),
                    'password_changed' => !empty($newPassword)
                ]);

                $this->addFlash('success', 'User has been updated successfully.');
                
                // Redirect to return URL
                return $this->redirect($returnUrl);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating user: ' . $e->getMessage());
            }
        }

        return $this->render('admin/users/edit.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Edit User: ' . $user->getFullName(),
            'form' => $form,
            'user' => $user,
            'return_url' => $returnUrl,
            'departments_by_college' => $this->getDepartmentsByCollege(),
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/users/{id}', name: 'users_show', requirements: ['id' => '\d+'])]
    public function showUser(int $id): Response
    {
        // Redirect /admin/users/{id} to /admin/users/{id}/view
        return $this->redirectToRoute('admin_users_view', ['id' => $id], 301);
    }

    #[Route('/users/{id}/view', name: 'users_view')]
    public function viewUser(int $id, Request $request): Response
    {
        try {
            $user = $this->userService->getUserById($id);
        } catch (\Exception $e) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('admin_users_all');
        }

        // Determine the back URL based on referer or user role
        $backUrl = $request->headers->get('referer');
        
        // Parse the referer to check if it's a valid user list page
        $validListPages = [
            '/admin/users/all',
            '/admin/users/administrators',
            '/admin/users/department-heads',
            '/admin/users/faculty',
            '/admin/users/create',
            '/admin/users/' . $id . '/edit'
        ];
        
        $isValidReferer = false;
        if ($backUrl) {
            foreach ($validListPages as $validPage) {
                if (str_contains($backUrl, $validPage)) {
                    $isValidReferer = true;
                    break;
                }
            }
        }
        
        // If referer is not a valid list/edit/create page, generate appropriate list URL
        if (!$isValidReferer) {
            $backUrl = match($user->getRole()) {
                1 => $this->generateUrl('admin_users_administrators'),
                2 => $this->generateUrl('admin_users_department_heads'),
                3 => $this->generateUrl('admin_users_faculty'),
                default => $this->generateUrl('admin_users_all')
            };
        }

        return $this->render('admin/users/view.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'User Details: ' . $user->getFullName(),
            'user' => $user,
            'back_url' => $backUrl,
        ]));
    }

    #[Route('/users/{id}/delete', name: 'users_delete', methods: ['POST'])]
    public function deleteUser(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_user_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_users_all');
        }

        try {
            $user = $this->userService->getUserById($id);
            $userRole = $user->getRole();
            $userName = $user->getFullName();
            
            $this->userService->deleteUser($user);
            
            // Log the activity
            $this->activityLogService->log(
                'user.deleted',
                "User {$userName} was deleted",
                'User',
                $id,
                ['role' => $user->getRoleDisplayName()]
            );
            
            $this->addFlash('success', 'User has been deleted successfully.');
            
            // Redirect back to appropriate list
            $referer = $request->headers->get('referer');
            if ($referer && str_contains($referer, '/admin/users/')) {
                return $this->redirect($referer);
            }
            
            return match($userRole) {
                1 => $this->redirectToRoute('admin_users_administrators'),
                2 => $this->redirectToRoute('admin_users_department_heads'),
                3 => $this->redirectToRoute('admin_users_faculty'),
                default => $this->redirectToRoute('admin_users_all')
            };
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deleting user: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_users_all');
    }

    #[Route('/users/{id}/activate', name: 'users_activate', methods: ['POST'])]
    public function activateUser(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('activate_user_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_users_all');
        }

        try {
            $user = $this->userService->getUserById($id);
            $this->userService->activateUser($user);
            
            // Log the activity
            $this->activityLogService->logUserActivity('user.activated', $user);
            
            $this->addFlash('success', 'User has been activated successfully.');
            
            // Redirect back to referer
            $referer = $request->headers->get('referer');
            if ($referer) {
                return $this->redirect($referer);
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error activating user: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_users_all');
    }

    #[Route('/users/{id}/deactivate', name: 'users_deactivate', methods: ['POST'])]
    public function deactivateUser(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('deactivate_user_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_users_all');
        }

        try {
            $user = $this->userService->getUserById($id);
            $this->userService->deactivateUser($user);
            
            // Log the activity
            $this->activityLogService->logUserActivity('user.deactivated', $user);
            
            $this->addFlash('success', 'User has been deactivated successfully.');
            
            // Redirect back to referer
            $referer = $request->headers->get('referer');
            if ($referer) {
                return $this->redirect($referer);
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deactivating user: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_users_all');
    }

    // AJAX/API Routes

    #[Route('/api/users/bulk-action', name: 'api_users_bulk_action', methods: ['POST'])]
    public function bulkUserAction(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $action = $data['action'] ?? null;
        $userIds = $data['user_ids'] ?? [];

        if (!in_array($action, ['activate', 'deactivate', 'delete'])) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
        }

        if (empty($userIds)) {
            return new JsonResponse(['success' => false, 'message' => 'No users selected.'], 400);
        }

        try {
            $count = 0;
            switch ($action) {
                case 'activate':
                    $count = $this->userService->bulkActivateUsers($userIds);
                    break;
                case 'deactivate':
                    $count = $this->userService->bulkDeactivateUsers($userIds);
                    break;
                case 'delete':
                    $count = $this->userService->bulkDeleteUsers($userIds);
                    break;
            }

            return new JsonResponse([
                'success' => true,
                'message' => "{$count} users {$action}d successfully.",
                'count' => $count
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error performing bulk action: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/users/check-availability', name: 'api_users_check_availability', methods: ['POST'])]
    public function checkAvailability(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $field = $data['field'] ?? null;
        $value = $data['value'] ?? null;
        $excludeId = $data['exclude_id'] ?? null;

        if (!$field || !$value) {
            return new JsonResponse(['available' => false, 'message' => 'Missing field or value.']);
        }

        $available = false;
        switch ($field) {
            case 'username':
                $available = $this->userService->isUsernameAvailable($value, $excludeId);
                break;
            case 'email':
                $available = $this->userService->isEmailAvailable($value, $excludeId);
                break;
            case 'employee_id':
                $available = $this->userService->isEmployeeIdAvailable($value, $excludeId);
                break;
        }

        return new JsonResponse([
            'available' => $available,
            'message' => $available ? 'Available' : ucfirst($field) . ' is already taken.'
        ]);
    }

    #[Route('/api/users/statistics', name: 'api_users_statistics', methods: ['GET'])]
    public function getUserStatistics(): JsonResponse
    {
        $statistics = $this->userService->getUserStatistics();
        return new JsonResponse($statistics);
    }

    #[Route('/api/users/generate-password', name: 'api_users_generate_password', methods: ['POST'])]
    public function generatePassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $length = $data['length'] ?? 12;
        
        if ($length < 6 || $length > 50) {
            return new JsonResponse(['success' => false, 'message' => 'Password length must be between 6 and 50 characters.'], 400);
        }

        $password = $this->userService->generateRandomPassword($length);
        
        return new JsonResponse([
            'success' => true,
            'password' => $password
        ]);
    }

    /**
     * Get departments grouped by college for frontend filtering
     */
    private function getDepartmentsByCollege(): array
    {
        $departments = $this->departmentRepository->createQueryBuilder('d')
            ->leftJoin('d.college', 'c')
            ->where('d.isActive = :active')
            ->andWhere('d.deletedAt IS NULL')
            ->andWhere('c.isActive = :active')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('active', true)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();

        $departmentsByCollege = [];
        
        foreach ($departments as $department) {
            $college = $department->getCollege();
            if ($college) {
                $collegeId = $college->getId();
                if (!isset($departmentsByCollege[$collegeId])) {
                    $departmentsByCollege[$collegeId] = [];
                }
                $departmentsByCollege[$collegeId][$department->getId()] = $department->getName();
            }
        }
        
        return $departmentsByCollege;
    }

    // ==================== ROOM MANAGEMENT ====================
    
    #[Route('/rooms', name: 'rooms', methods: ['GET'])]
    public function rooms(Request $request, \App\Service\RoomService $roomService): Response
    {
        // Load ALL rooms for client-side filtering
        $filters = [
            'search' => '',
            'is_active' => '',
            'type' => '',
            'building' => '',
            'department' => null,
            'page' => 1,
            'limit' => 10000, // Load all rooms
        ];

        $result = $roomService->getPaginatedRooms($filters);
        $statistics = $roomService->getRoomStatistics([]);
        $buildings = $roomService->getAllBuildings([]);
        
        // Get all departments for the filter dropdown
        $departments = $this->departmentRepository->findBy(['deletedAt' => null], ['name' => 'ASC']);

        // Get active semester information
        $activeYear = $this->systemSettingsService->getActiveAcademicYear();
        $activeSemester = $this->systemSettingsService->getActiveSemester();
        $activeSemesterDisplay = $this->systemSettingsService->getActiveSemesterDisplay();
        $hasActiveSemester = $this->systemSettingsService->hasActiveSemester();

        return $this->render('admin/rooms.html.twig', array_merge($this->getBaseTemplateData(), [
            'rooms' => $result['rooms'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
            'statistics' => $statistics,
            'buildings' => $buildings,
            'departments' => $departments,
            'selected_department' => null,
            'activeYear' => $activeYear,
            'activeSemester' => $activeSemester,
        ]));
    }

    #[Route('/rooms/create', name: 'rooms_create', methods: ['GET', 'POST'])]
    public function createRoom(Request $request, \App\Service\RoomService $roomService): Response
    {
        $room = new \App\Entity\Room();
        $form = $this->createForm(\App\Form\RoomFormType::class, $room);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check if code is unique
            if (!$roomService->isCodeUnique($room->getCode())) {
                $this->addFlash('error', 'Room code already exists. Please use a different code.');
                return $this->render('admin/rooms/create.html.twig', [
                    'form' => $form->createView(),
                ]);
            }

            $roomService->createRoom($room);
            
            // Log the activity
            $this->activityLogService->log(
                'room.created',
                "Room created: {$room->getBuilding()} - {$room->getName()}",
                'Room',
                $room->getId(),
                [
                    'building' => $room->getBuilding(),
                    'code' => $room->getCode(),
                    'capacity' => $room->getCapacity()
                ]
            );
            
            $this->addFlash('success', 'Room created successfully!');
            return $this->redirectToRoute('admin_rooms');
        }

        return $this->render('admin/rooms/create.html.twig', [
            'form' => $form->createView(),
        ], new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/rooms/{id}/edit', name: 'rooms_edit', methods: ['GET', 'POST'])]
    public function editRoom(int $id, Request $request, \App\Service\RoomService $roomService): Response
    {
        $room = $roomService->getRoomById($id);
        
        if (!$room) {
            $this->addFlash('error', 'Room not found.');
            return $this->redirectToRoute('admin_rooms');
        }

        $form = $this->createForm(\App\Form\RoomFormType::class, $room);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check if code is unique (excluding current room)
            if (!$roomService->isCodeUnique($room->getCode(), $id)) {
                $this->addFlash('error', 'Room code already exists. Please use a different code.');
                return $this->render('admin/rooms/edit.html.twig', [
                    'form' => $form->createView(),
                    'room' => $room,
                ]);
            }

            $roomService->updateRoom($room);
            
            // Log the activity
            $this->activityLogService->log(
                'room.updated',
                "Room updated: {$room->getBuilding()} - {$room->getName()}",
                'Room',
                $room->getId(),
                ['building' => $room->getBuilding(), 'code' => $room->getCode()]
            );
            
            $this->addFlash('success', 'Room updated successfully!');
            return $this->redirectToRoute('admin_rooms');
        }

        return $this->render('admin/rooms/edit.html.twig', [
            'form' => $form->createView(),
            'room' => $room,
        ], new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/rooms/{id}', name: 'rooms_view', methods: ['GET'])]
    public function viewRoom(int $id, \App\Service\RoomService $roomService): Response
    {
        $room = $roomService->getRoomById($id);
        
        if (!$room) {
            $this->addFlash('error', 'Room not found.');
            return $this->redirectToRoute('admin_rooms');
        }

        return $this->render('admin/rooms/view.html.twig', array_merge($this->getBaseTemplateData(), [
            'room' => $room,
        ]));
    }

    #[Route('/rooms/{id}/delete', name: 'rooms_delete', methods: ['POST'])]
    public function deleteRoom(int $id, Request $request, \App\Service\RoomService $roomService): Response
    {
        if ($this->isCsrfTokenValid('delete_room_' . $id, $request->request->get('_token'))) {
            $room = $roomService->getRoomById($id);
            
            if ($room) {
                $roomName = $room->getBuilding() . ' - ' . $room->getName();
                
                $roomService->deleteRoom($room);
                
                // Log the activity
                $this->activityLogService->log(
                    'room.deleted',
                    "Room deleted: {$roomName}",
                    'Room',
                    $id
                );
                
                $this->addFlash('success', 'Room deleted successfully!');
            } else {
                $this->addFlash('error', 'Room not found.');
            }
        } else {
            $this->addFlash('error', 'Invalid CSRF token.');
        }

        return $this->redirectToRoute('admin_rooms');
    }

    #[Route('/rooms/{id}/toggle-status', name: 'rooms_toggle_status', methods: ['POST'])]
    public function toggleRoomStatus(int $id, Request $request, \App\Service\RoomService $roomService): Response
    {
        if ($this->isCsrfTokenValid('toggle_status_room_' . $id, $request->request->get('_token'))) {
            $room = $roomService->getRoomById($id);
            
            if ($room) {
                $roomService->toggleRoomStatus($room);
                $status = $room->isActive() ? 'activated' : 'deactivated';
                $this->addFlash('success', "Room {$status} successfully!");
            } else {
                $this->addFlash('error', 'Room not found.');
            }
        } else {
            $this->addFlash('error', 'Invalid CSRF token.');
        }

        return $this->redirectToRoute('admin_rooms');
    }

    #[Route('/rooms/{id}/history', name: 'rooms_history', methods: ['GET'])]
    public function roomHistory(int $id, Request $request, \App\Service\RoomService $roomService): Response
    {
        $room = $roomService->getRoomById($id);
        
        if (!$room) {
            return new JsonResponse(['error' => 'Room not found'], 404);
        }

        // Get filters from query parameters
        $semesterFilter = $request->query->get('semester');
        $yearFilter = $request->query->get('year');

        // Get active semester info
        $activeYear = $this->systemSettingsService->getActiveAcademicYear();
        $activeSemester = $this->systemSettingsService->getActiveSemester();

        // Default to active semester if no filters provided
        if (!$semesterFilter && $activeSemester) {
            $semesterFilter = $activeSemester;
        }
        if (!$yearFilter && $activeYear) {
            $yearFilter = $activeYear->getId();
        }

        // Get schedule history for this room
        $scheduleRepository = $this->entityManager->getRepository(\App\Entity\Schedule::class);
        $qb = $scheduleRepository->createQueryBuilder('s')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.subject', 'subj')
            ->leftJoin('s.faculty', 'f')
            ->leftJoin('s.academicYear', 'ay')
            ->addSelect('r', 'subj', 'f', 'ay')
            ->where('s.room = :room')
            ->setParameter('room', $room)
            ->orderBy('ay.year', 'DESC')
            ->addOrderBy('s.semester', 'DESC')
            ->addOrderBy('subj.code', 'ASC');

        // Apply filters if provided
        if ($semesterFilter) {
            $qb->andWhere('s.semester = :semester')
               ->setParameter('semester', $semesterFilter);
        }
        if ($yearFilter) {
            $qb->andWhere('ay.id = :yearId')
               ->setParameter('yearId', $yearFilter);
        }

        $scheduleHistory = $qb->getQuery()->getResult();

        // Get all academic years for filter dropdown
        $academicYears = $this->entityManager->getRepository(\App\Entity\AcademicYear::class)
            ->createQueryBuilder('ay')
            ->where('ay.deletedAt IS NULL')
            ->orderBy('ay.year', 'DESC')
            ->getQuery()
            ->getResult();

        // Calculate unique faculty and subjects
        $uniqueFacultyIds = [];
        $uniqueSubjectIds = [];
        foreach ($scheduleHistory as $schedule) {
            if ($schedule->getFaculty()) {
                $uniqueFacultyIds[$schedule->getFaculty()->getId()] = true;
            }
            if ($schedule->getSubject()) {
                $uniqueSubjectIds[$schedule->getSubject()->getId()] = true;
            }
        }

        return $this->render('admin/rooms/history.html.twig', array_merge($this->getBaseTemplateData(), [
            'room' => $room,
            'scheduleHistory' => $scheduleHistory,
            'academicYears' => $academicYears,
            'semesterFilter' => $semesterFilter,
            'yearFilter' => $yearFilter,
            'uniqueFacultyCount' => count($uniqueFacultyIds),
            'uniqueSubjectCount' => count($uniqueSubjectIds),
            'activeYear' => $activeYear,
            'activeSemester' => $activeSemester,
        ]));
    }

    #[Route('/rooms/{id}/history/export', name: 'rooms_history_export', methods: ['GET'])]
    public function exportRoomHistory(int $id, Request $request, \App\Service\RoomService $roomService): Response
    {
        $room = $roomService->getRoomById($id);
        
        if (!$room) {
            $this->addFlash('error', 'Room not found.');
            return $this->redirectToRoute('admin_rooms');
        }

        // Get filters
        $semesterFilter = $request->query->get('semester');
        $yearFilter = $request->query->get('year');

        // Get schedule history
        $scheduleRepository = $this->entityManager->getRepository(\App\Entity\Schedule::class);
        $qb = $scheduleRepository->createQueryBuilder('s')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.subject', 'subj')
            ->leftJoin('s.faculty', 'f')
            ->leftJoin('s.academicYear', 'ay')
            ->addSelect('r', 'subj', 'f', 'ay')
            ->where('s.room = :room')
            ->setParameter('room', $room)
            ->orderBy('ay.year', 'DESC')
            ->addOrderBy('s.semester', 'DESC')
            ->addOrderBy('subj.code', 'ASC');

        if ($semesterFilter) {
            $qb->andWhere('s.semester = :semester')
               ->setParameter('semester', $semesterFilter);
        }
        if ($yearFilter) {
            $qb->andWhere('ay.id = :yearId')
               ->setParameter('yearId', $yearFilter);
        }

        $scheduleHistory = $qb->getQuery()->getResult();

        // Create CSV content
        $csv = [];
        $csv[] = ['Academic Year', 'Semester', 'Subject Code', 'Subject Name', 'Section', 'Faculty', 'Day', 'Start Time', 'End Time'];

        foreach ($scheduleHistory as $schedule) {
            $csv[] = [
                $schedule->getAcademicYear() ? $schedule->getAcademicYear()->getYear() : 'N/A',
                $schedule->getSemester() ?: 'N/A',
                $schedule->getSubject() ? $schedule->getSubject()->getCode() : 'N/A',
                $schedule->getSubject() ? $schedule->getSubject()->getName() : 'N/A',
                $schedule->getSection() ?: 'N/A',
                $schedule->getFaculty() ? $schedule->getFaculty()->getFullName() : 'Unassigned',
                $schedule->getDayOfWeek() ?: 'N/A',
                $schedule->getStartTime() ? $schedule->getStartTime()->format('H:i') : 'N/A',
                $schedule->getEndTime() ? $schedule->getEndTime()->format('H:i') : 'N/A',
            ];
        }

        // Generate CSV file
        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="room_history_' . $room->getCode() . '_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $response->setContent(stream_get_contents($output));
        fclose($output);

        return $response;
    }

    #[Route('/rooms/{id}/schedule-pdf', name: 'rooms_schedule_pdf', methods: ['GET'])]
    public function generateRoomSchedulePdf(
        int $id, 
        Request $request,
        \App\Service\RoomService $roomService,
        \App\Service\RoomSchedulePdfService $pdfService
    ): Response
    {
        $room = $roomService->getRoomById($id);
        
        if (!$room) {
            $this->addFlash('error', 'Room not found.');
            return $this->redirectToRoute('admin_rooms');
        }

        // Get optional filters or use active semester
        $academicYear = $request->query->get('academic_year');
        $semester = $request->query->get('semester');

        // Default to active semester if not specified
        if (!$academicYear || !$semester) {
            $activeYear = $this->systemSettingsService->getActiveAcademicYear();
            $activeSemester = $this->systemSettingsService->getActiveSemester();
            
            if (!$academicYear && $activeYear) {
                $academicYear = $activeYear->getYear();
            }
            if (!$semester && $activeSemester) {
                $semester = $activeSemester;
            }
        }

        try {
            $pdfContent = $pdfService->generateRoomSchedulePdf($room, $academicYear, $semester);
            
            $filename = 'room_schedule_' . $room->getCode() . '_' . date('Y-m-d') . '.pdf';
            
            return new Response(
                $pdfContent,
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="' . $filename . '"',
                ]
            );
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error generating PDF: ' . $e->getMessage());
            return $this->redirectToRoute('admin_rooms_view', ['id' => $id]);
        }
    }

    // ==================== ACADEMIC YEARS MANAGEMENT ====================

    /**
     * @param Request $request
     * @param \App\Service\AcademicYearService $academicYearService
     * @return Response
     */
    #[Route('/academic-years', name: 'academic_years')]
    public function academicYears(Request $request, \App\Service\AcademicYearService $academicYearService): Response
    {
        $isActiveParam = $request->query->get('is_active');
        $isActive = null;
        if ($isActiveParam !== null && $isActiveParam !== '') {
            $isActive = $isActiveParam === '1' || $isActiveParam === 'true' || $isActiveParam === true;
        }

        $isCurrentParam = $request->query->get('is_current');
        $isCurrent = null;
        if ($isCurrentParam !== null && $isCurrentParam !== '') {
            $isCurrent = $isCurrentParam === '1' || $isCurrentParam === 'true' || $isCurrentParam === true;
        }

        $filters = [
            'search' => $request->query->get('search'),
            'is_active' => $isActive,
            'is_current' => $isCurrent,
            'sort_field' => $request->query->get('sort_field', 'startDate'),
            'sort_direction' => $request->query->get('sort_direction', 'DESC'),
        ];

        $academicYears = $academicYearService->getAcademicYears($filters);
        $statistics = $academicYearService->getStatistics();
        $currentYear = $academicYearService->getCurrentAcademicYear();

        return $this->render('admin/academic_years.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Academic Years Management',
            'academic_years' => $academicYears,
            'statistics' => $statistics,
            'current_year' => $currentYear,
            'filters' => $filters,
        ]));
    }

    /**
     * @param Request $request
     * @param \App\Service\AcademicYearService $academicYearService
     * @return Response
     */
    #[Route('/academic-years/create', name: 'academic_years_create')]
    public function createAcademicYear(Request $request, \App\Service\AcademicYearService $academicYearService): Response
    {
        $academicYear = new \App\Entity\AcademicYear();
        
        // Set suggested next year
        $suggestedYear = $academicYearService->generateNextYear();
        $academicYear->setYear($suggestedYear);

        $form = $this->createForm(\App\Form\AcademicYearFormType::class, $academicYear);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $academicYearService->createAcademicYear($academicYear);
                
                // Log the activity
                $this->activityLogService->log(
                    'academic_year.created',
                    "Academic year created: {$academicYear->getYear()}",
                    'AcademicYear',
                    $academicYear->getId(),
                    [
                        'year' => $academicYear->getYear(),
                        'is_active' => $academicYear->isActive()
                    ]
                );
                
                $this->addFlash('success', 'Academic year created successfully!');
                return $this->redirectToRoute('admin_academic_years');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating academic year: ' . $e->getMessage());
            }
        }

        return $this->render('admin/academic_years/form.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Create Academic Year',
            'form' => $form->createView(),
            'academic_year' => $academicYear,
            'is_edit' => false,
        ]));
    }

    /**
     * @param int $id
     * @param Request $request
     * @param \App\Service\AcademicYearService $academicYearService
     * @return Response
     */
    #[Route('/academic-years/{id}/edit', name: 'academic_years_edit', requirements: ['id' => '\d+'])]
    public function editAcademicYear(int $id, Request $request, \App\Service\AcademicYearService $academicYearService): Response
    {
        $academicYear = $academicYearService->getAcademicYearById($id);

        if (!$academicYear) {
            $this->addFlash('error', 'Academic year not found.');
            return $this->redirectToRoute('admin_academic_years');
        }

        $form = $this->createForm(\App\Form\AcademicYearFormType::class, $academicYear);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $academicYearService->updateAcademicYear($academicYear);
                
                // Log the activity
                $this->activityLogService->log(
                    'academic_year.updated',
                    "Academic year updated: {$academicYear->getYear()}",
                    'AcademicYear',
                    $academicYear->getId(),
                    ['year' => $academicYear->getYear()]
                );
                
                $this->addFlash('success', 'Academic year updated successfully!');
                return $this->redirectToRoute('admin_academic_years');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating academic year: ' . $e->getMessage());
            }
        }

        return $this->render('admin/academic_years/form.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Edit Academic Year',
            'form' => $form->createView(),
            'academic_year' => $academicYear,
            'is_edit' => true,
        ]));
    }

    /**
     * @param int $id
     * @param Request $request
     * @param \App\Service\AcademicYearService $academicYearService
     * @return Response
     */
    #[Route('/academic-years/{id}/delete', name: 'academic_years_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteAcademicYear(int $id, Request $request, \App\Service\AcademicYearService $academicYearService): Response
    {
        if (!$this->isCsrfTokenValid('delete_academic_year_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_academic_years');
        }

        try {
            $academicYear = $academicYearService->getAcademicYearById($id);
            if ($academicYear) {
                $yearName = $academicYear->getYear();
                $academicYearService->deleteAcademicYear($academicYear);
                
                // Log the activity
                $this->activityLogService->log(
                    'academic_year.deleted',
                    "Academic year deleted: {$yearName}",
                    'AcademicYear',
                    $id
                );
                
                $this->addFlash('success', 'Academic year deleted successfully.');
            } else {
                $this->addFlash('error', 'Academic year not found.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deleting academic year: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_academic_years');
    }

    /**
     * @param int $id
     * @param Request $request
     * @param \App\Service\AcademicYearService $academicYearService
     * @return Response
     */
    #[Route('/academic-years/{id}/set-current', name: 'academic_years_set_current', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function setCurrentAcademicYear(int $id, Request $request, \App\Service\AcademicYearService $academicYearService): Response
    {
        if (!$this->isCsrfTokenValid('set_current_year_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_academic_years');
        }

        try {
            $academicYear = $academicYearService->getAcademicYearById($id);
            if ($academicYear) {
                $academicYearService->setCurrentYear($academicYear);
                $this->addFlash('success', 'Academic year ' . $academicYear->getYear() . ' is now set as current.');
            } else {
                $this->addFlash('error', 'Academic year not found.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error setting current year: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_academic_years');
    }

    /**
     * @param int $id
     * @param Request $request
     * @param \App\Service\AcademicYearService $academicYearService
     * @return Response
     */
    #[Route('/academic-years/{id}/toggle-status', name: 'academic_years_toggle_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleAcademicYearStatus(int $id, Request $request, \App\Service\AcademicYearService $academicYearService): Response
    {
        if (!$this->isCsrfTokenValid('toggle_status_year_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_academic_years');
        }

        try {
            $academicYear = $academicYearService->getAcademicYearById($id);
            if ($academicYear) {
                $academicYearService->toggleActiveStatus($academicYear);
                $status = $academicYear->isActive() ? 'activated' : 'deactivated';
                $this->addFlash('success', "Academic year {$status} successfully!");
            } else {
                $this->addFlash('error', 'Academic year not found.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error toggling status: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_academic_years');
    }

    // ==================== CURRICULUM UPLOAD ====================

    /**
     * @param \App\Service\CurriculumUploadService $uploadService
     * @return Response
     */
    #[Route('/curricula/template/download', name: 'curriculum_download_template', methods: ['GET'])]
    public function downloadCurriculumTemplate(\App\Service\CurriculumUploadService $uploadService): Response
    {
        $csv = $uploadService->generateTemplate();
        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="curriculum_template.csv"');
        return $response;
    }

    /**
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @param \App\Service\DepartmentService $departmentService
     * @param \App\Service\CurriculumUploadService $uploadService
     * @return JsonResponse
     */
    #[Route('/curricula/bulk-upload', name: 'curriculum_bulk_upload', methods: ['POST'])]
    public function bulkUploadCurriculum(
        Request $request,
        EntityManagerInterface $entityManager,
        \App\Service\DepartmentService $departmentService,
        \App\Service\CurriculumUploadService $uploadService
    ): JsonResponse
    {
        try {
            $file = $request->files->get('curriculum_file');
            $curriculumName = $request->request->get('curriculum_name');
            $version = $request->request->get('version');
            $departmentId = $request->request->get('department_id');
            // Default to true when the caller does not explicitly provide the flag
            $autoCreateTerms = $request->request->has('auto_create_terms') ? ($request->request->get('auto_create_terms') === '1') : true;

            // Validate required fields
            if (!$file) {
                return new JsonResponse(['success' => false, 'message' => 'No file uploaded.'], 400);
            }
            if (!$curriculumName || !$version || !$departmentId) {
                return new JsonResponse(['success' => false, 'message' => 'Missing required fields (name, version, department).'], 400);
            }

            // Validate file
            $maxSize = 10 * 1024 * 1024; // 10MB
            if ($file->getSize() > $maxSize) {
                return new JsonResponse(['success' => false, 'message' => 'File size exceeds 10MB limit.'], 400);
            }

            $allowedExtensions = ['csv', 'xlsx', 'xls'];
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, $allowedExtensions)) {
                return new JsonResponse(['success' => false, 'message' => 'Invalid file format. Supported: CSV, XLSX, XLS'], 400);
            }

            // Get department
            $department = $departmentService->getDepartmentById($departmentId);
            if (!$department) {
                return new JsonResponse(['success' => false, 'message' => 'Department not found.'], 404);
            }

            // Start transaction to ensure curriculum is only created if upload succeeds
            $entityManager->beginTransaction();
            
            try {
                // Create curriculum
                $curriculum = new \App\Entity\Curriculum();
                $curriculum->setName($curriculumName);
                $curriculum->setVersion((int)$version);
                $curriculum->setDepartment($department);
                $curriculum->setIsPublished(false);
                $curriculum->setCreatedAt(new \DateTimeImmutable());
                $curriculum->setUpdatedAt(new \DateTimeImmutable());
                $entityManager->persist($curriculum);
                $entityManager->flush(); // Flush to get curriculum ID

                // Process upload using service
                $result = $uploadService->processUpload($file, $curriculum, $autoCreateTerms);

                if (!$result['success']) {
                    // Rollback transaction if upload failed
                    $entityManager->rollback();
                    return new JsonResponse($result, 400);
                }
                
                // Commit transaction - curriculum and all subjects/terms are saved
                $entityManager->commit();
            } catch (\Exception $uploadException) {
                // Rollback on any error
                if ($entityManager->getConnection()->isTransactionActive()) {
                    $entityManager->rollback();
                }
                throw $uploadException;
            }

            // If subjects were created but no terms were generated, warn the user
            if (($result['subjects_added'] ?? 0) > 0 && ($result['terms_created'] ?? 0) === 0) {
                $result['warning'] = 'Subjects were added but no terms were created. Re-upload with "Auto create terms" enabled to link subjects to curriculum terms.';
            }

            return new JsonResponse($result);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error processing upload: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @param Request $request
     * @param \App\Entity\Curriculum $curriculum
     * @param \App\Service\CurriculumUploadService $uploadService
     * @return JsonResponse
     */
    #[Route('/curricula/{id}/upload', name: 'curriculum_upload', methods: ['POST'])]
    public function uploadCurriculum(
        Request $request, 
        \App\Entity\Curriculum $curriculum,
        \App\Service\CurriculumUploadService $uploadService
    ): JsonResponse
    {
        try {
            $file = $request->files->get('curriculum_file');
            if (!$file) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No file uploaded.'
                ], 400);
            }

            // Default to true when the caller does not explicitly provide the flag
            $autoCreateTerms = $request->request->has('auto_create_terms') ? ($request->request->get('auto_create_terms') === '1') : true;

            // Process upload using service
            $result = $uploadService->processUpload($file, $curriculum, $autoCreateTerms);

            // If subjects were created but no terms were generated, include a warning
            if (($result['subjects_added'] ?? 0) > 0 && ($result['terms_created'] ?? 0) === 0) {
                $result['warning'] = 'Subjects were added but no terms were created. Re-upload with "Auto create terms" enabled to link subjects to curriculum terms.';
            }

            return new JsonResponse($result, $result['success'] ? 200 : 400);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error processing upload: ' . $e->getMessage()
            ], 500);
        }
    }

    // System Settings Routes

    #[Route('/settings/', name: 'settings_redirect')]
    public function settingsRedirect(): Response
    {
        return $this->redirectToRoute('admin_system_settings');
    }

    #[Route('/settings/system', name: 'system_settings')]
    public function systemSettings(Request $request): Response
    {
        $academicYearRepository = $this->entityManager->getRepository(\App\Entity\AcademicYear::class);
        $activeYear = $this->systemSettingsService->getActiveAcademicYear();
        $activeSemester = $this->systemSettingsService->getActiveSemester();
        
        // Get all active academic years
        $academicYears = $academicYearRepository->createQueryBuilder('ay')
            ->where('ay.isActive = :active')
            ->andWhere('ay.deletedAt IS NULL')
            ->setParameter('active', true)
            ->orderBy('ay.year', 'DESC')
            ->getQuery()
            ->getResult();
        
        $semesters = $this->systemSettingsService->getAvailableSemesters();

        return $this->render('admin/settings/system.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'System Settings',
            'academic_years' => $academicYears,
            'active_year' => $activeYear,
            'active_semester' => $activeSemester,
            'available_semesters' => $semesters,
        ]));
    }

    #[Route('/settings/system/set-semester', name: 'set_active_semester', methods: ['POST'])]
    public function setActiveSemester(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $academicYearId = $data['academic_year_id'] ?? null;
            $semester = $data['semester'] ?? null;

            if (!$academicYearId || !$semester) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Academic year and semester are required'
                ], 400);
            }

            // Validate the transition
            $warnings = $this->systemSettingsService->validateSemesterTransition($academicYearId, $semester);
            if (!empty($warnings)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Validation failed',
                    'warnings' => $warnings
                ], 400);
            }

            // Get schedule count for current semester (if exists)
            $scheduleRepository = $this->entityManager->getRepository(\App\Entity\Schedule::class);
            $currentScheduleCount = 0;
            $currentYear = $this->systemSettingsService->getActiveAcademicYear();
            $currentSemester = $this->systemSettingsService->getActiveSemester();
            
            if ($currentYear && $currentSemester) {
                $currentScheduleCount = $scheduleRepository->countByAcademicYearAndSemester($currentYear, $currentSemester);
            }

            // Set the new active semester
            $result = $this->systemSettingsService->setActiveSemester($academicYearId, $semester);

            // Update the user's semester filter preference to match the new active semester
            $session = $request->getSession();
            $session->set('semester_filter', $semester);

            // Log the activity
            $this->activityLogService->log(
                'system_settings',
                sprintf('Changed active semester to %s', $result->getFullDisplayName()),
                'AcademicYear',
                $result->getId(),
                null,
                $this->getUser()
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Active semester updated successfully',
                'display' => $result->getFullDisplayName(),
                'current_schedule_count' => $currentScheduleCount
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/settings/system/semester-info', name: 'get_semester_info', methods: ['GET'])]
    public function getSemesterInfo(): JsonResponse
    {
        try {
            $info = $this->systemSettingsService->getSemesterTransitionInfo();
            
            // Get schedule count if there's an active semester
            if ($info['has_active']) {
                $scheduleRepository = $this->entityManager->getRepository(\App\Entity\Schedule::class);
                $activeYear = $this->systemSettingsService->getActiveAcademicYear();
                $activeSemester = $this->systemSettingsService->getActiveSemester();
                
                $info['schedule_count'] = $scheduleRepository->countByAcademicYearAndSemester($activeYear, $activeSemester);
            } else {
                $info['schedule_count'] = 0;
            }

            return new JsonResponse($info);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/logs', name: 'logs', methods: ['GET'])]
    public function activityLogs(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Get filter parameters
        $filterUser = $request->query->get('user');
        $filterAction = $request->query->get('action');
        $filterDateFrom = $request->query->get('date_from');
        $filterDateTo = $request->query->get('date_to');

        // Build query
        $qb = $this->entityManager->getRepository(ActivityLog::class)
            ->createQueryBuilder('al')
            ->leftJoin('al.user', 'u')
            ->addSelect('u')
            ->orderBy('al.createdAt', 'DESC');

        // Apply filters
        if ($filterUser) {
            $qb->andWhere('u.id = :userId')
               ->setParameter('userId', $filterUser);
        }

        if ($filterAction) {
            $qb->andWhere('al.action = :action')
               ->setParameter('action', $filterAction);
        }

        if ($filterDateFrom) {
            $qb->andWhere('al.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($filterDateFrom . ' 00:00:00'));
        }

        if ($filterDateTo) {
            $qb->andWhere('al.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTime($filterDateTo . ' 23:59:59'));
        }

        // Get total count
        $totalCount = (int) (clone $qb)
            ->select('COUNT(al.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Get paginated logs
        $logs = $qb
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Get all users for filter dropdown
        $users = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.deletedAt IS NULL')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        // Get distinct actions for filter
        $actions = $this->entityManager->getRepository(ActivityLog::class)
            ->createQueryBuilder('al')
            ->select('DISTINCT al.action')
            ->orderBy('al.action', 'ASC')
            ->getQuery()
            ->getScalarResult();
        $actions = array_column($actions, 'action');

        $totalPages = ceil($totalCount / $limit);

        return $this->render('admin/logs/index.html.twig', [
            'logs' => $logs,
            'users' => $users,
            'actions' => $actions,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'filters' => [
                'user' => $filterUser,
                'action' => $filterAction,
                'date_from' => $filterDateFrom,
                'date_to' => $filterDateTo,
            ],
        ]);
    }
}
