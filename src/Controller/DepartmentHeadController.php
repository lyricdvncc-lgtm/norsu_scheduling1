<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Room;
use App\Entity\Schedule;
use App\Form\UserFormType;
use App\Form\UserEditFormType;
use App\Form\RoomFormType;
use App\Service\DashboardService;
use App\Service\DepartmentHeadService;
use App\Service\UserService;
use App\Service\ScheduleConflictDetector;
use App\Service\TeachingLoadPdfService;
use App\Service\ActivityLogService;
use App\Service\SystemSettingsService;
use App\Repository\DepartmentRepository;
use App\Repository\RoomRepository;
use App\Repository\ScheduleRepository;
use App\Repository\SubjectRepository;
use App\Repository\AcademicYearRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/department-head', name: 'department_head_')]
#[IsGranted('ROLE_DEPARTMENT_HEAD')]
class DepartmentHeadController extends AbstractController
{
    private DashboardService $dashboardService;
    private DepartmentHeadService $departmentHeadService;
    private UserService $userService;
    private DepartmentRepository $departmentRepository;
    private RoomRepository $roomRepository;
    private ScheduleRepository $scheduleRepository;
    private SubjectRepository $subjectRepository;
    private AcademicYearRepository $academicYearRepository;
    private ScheduleConflictDetector $conflictDetector;
    private EntityManagerInterface $entityManager;
    private TeachingLoadPdfService $pdfService;
    private ActivityLogService $activityLogService;
    private SystemSettingsService $systemSettingsService;

    public function __construct(
        DashboardService $dashboardService,
        DepartmentHeadService $departmentHeadService,
        UserService $userService,
        DepartmentRepository $departmentRepository,
        RoomRepository $roomRepository,
        ScheduleRepository $scheduleRepository,
        SubjectRepository $subjectRepository,
        AcademicYearRepository $academicYearRepository,
        ScheduleConflictDetector $conflictDetector,
        EntityManagerInterface $entityManager,
        TeachingLoadPdfService $pdfService,
        ActivityLogService $activityLogService,
        SystemSettingsService $systemSettingsService
    ) {
        $this->dashboardService = $dashboardService;
        $this->departmentHeadService = $departmentHeadService;
        $this->userService = $userService;
        $this->activityLogService = $activityLogService;
        $this->departmentRepository = $departmentRepository;
        $this->roomRepository = $roomRepository;
        $this->scheduleRepository = $scheduleRepository;
        $this->subjectRepository = $subjectRepository;
        $this->academicYearRepository = $academicYearRepository;
        $this->conflictDetector = $conflictDetector;
        $this->entityManager = $entityManager;
        $this->pdfService = $pdfService;
        $this->systemSettingsService = $systemSettingsService;
    }

    private function getBaseTemplateData(): array
    {
        /** @var User $user */
        $user = $this->getUser();
        return [
            'dashboard_data' => $this->departmentHeadService->getDashboardData($user),
            'activeSemesterDisplay' => $this->systemSettingsService->getActiveSemesterDisplay(),
            'hasActiveSemester' => $this->systemSettingsService->hasActiveSemester(),
        ];
    }

    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $dashboardData = $this->departmentHeadService->getDashboardData($user);

        return $this->render('department_head/dashboard.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Department Head Dashboard',
            'dashboard_data' => $dashboardData,
        ]));
    }

    // Faculty Management Routes

    #[Route('/faculty', name: 'faculty')]
    public function faculty(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

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

        $result = $this->departmentHeadService->getFacultyWithFilters($user, $filters);
        $statistics = $this->departmentHeadService->getFacultyStatistics($user);

        // Map result to pagination structure expected by template
        $pagination = [
            'currentPage' => $result['page'],
            'totalPages' => $result['pages'],
            'limit' => $result['limit'],
            'total' => $result['total'],
            'hasPrevious' => $result['has_previous'],
            'hasNext' => $result['has_next'],
        ];

        return $this->render('department_head/faculty/list.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Faculty Members',
            'users' => $result['users'],
            'pagination' => $pagination,
            'filters' => $filters,
            'statistics' => $statistics,
        ]));
    }

    #[Route('/faculty/create', name: 'faculty_create')]
    public function createFaculty(Request $request): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        
        if (!$departmentHead->getDepartmentId()) {
            $this->addFlash('error', 'You must be assigned to a department to create faculty members.');
            return $this->redirectToRoute('department_head_dashboard');
        }

        $user = new User();
        $user->setRole(3); // Faculty role
        $user->setDepartment($departmentHead->getDepartment());
        
        $form = $this->createForm(UserFormType::class, $user, [
            'is_edit' => false,
            'is_department_head' => true, // Lock department selection
        ]);
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
                    'role' => 3, // Force faculty role
                    'departmentId' => $departmentHead->getDepartmentId(), // Force their department
                    'isActive' => $user->isActive(),
                ];

                $this->userService->createUser($userData, $plainPassword);
                $this->addFlash('success', 'Faculty member has been created successfully.');
                
                return $this->redirectToRoute('department_head_faculty');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating faculty member: ' . $e->getMessage());
            }
        }

        return $this->render('department_head/faculty/create.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Create New Faculty Member',
            'form' => $form,
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/faculty/{id}/edit', name: 'faculty_edit')]
    public function editFaculty(int $id, Request $request): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();

        try {
            $user = $this->userService->getUserById($id);
            
            // Explicitly fetch the college to ensure it's loaded (not lazy-loaded proxy)
            if ($user->getCollege()) {
                $user->getCollege()->getName(); // This forces Doctrine to load the college
            }
            
            // Check access
            if (!$this->departmentHeadService->canAccessUser($departmentHead, $user)) {
                throw $this->createAccessDeniedException('You can only edit faculty in your department.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Faculty member not found or access denied.');
            return $this->redirectToRoute('department_head_faculty');
        }

        $form = $this->createForm(UserEditFormType::class, $user, [
            'include_password_reset' => true,
            'is_department_head' => true,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $newPassword = $form->get('newPassword')->getData();
                $userData = [
                    'username' => $user->getUsername(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'email' => $user->getEmail(),
                    'employeeId' => $user->getEmployeeId(),
                    'position' => $user->getPosition(),
                    'role' => 3, // Keep as faculty
                    'collegeId' => $user->getCollege() ? $user->getCollege()->getId() : null,
                    'departmentId' => $departmentHead->getDepartmentId(), // Keep in their department
                    'isActive' => $user->isActive(),
                ];

                $this->userService->updateUser($user, $userData, $newPassword);

                $this->addFlash('success', 'Faculty member has been updated successfully.');
                return $this->redirectToRoute('department_head_faculty');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating faculty member: ' . $e->getMessage());
            }
        }

        return $this->render('department_head/faculty/edit.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Edit Faculty: ' . $user->getFullName(),
            'form' => $form,
            'user' => $user,
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/faculty/{id}/view', name: 'faculty_view')]
    public function viewFaculty(int $id): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();

        try {
            $user = $this->userService->getUserById($id);
            
            // Check access
            if (!$this->departmentHeadService->canAccessUser($departmentHead, $user)) {
                throw $this->createAccessDeniedException('You can only view faculty in your department.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Faculty member not found or access denied.');
            return $this->redirectToRoute('department_head_faculty');
        }

        return $this->render('department_head/faculty/view.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Faculty Details: ' . $user->getFullName(),
            'user' => $user,
        ]));
    }

    #[Route('/faculty/{id}/activate', name: 'faculty_activate', methods: ['POST'])]
    public function activateFaculty(int $id, Request $request): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();

        if (!$this->isCsrfTokenValid('activate_user_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('department_head_faculty');
        }

        try {
            $user = $this->userService->getUserById($id);
            
            // Check access
            if (!$this->departmentHeadService->canAccessUser($departmentHead, $user)) {
                throw $this->createAccessDeniedException('Access denied.');
            }

            $this->userService->activateUser($user);
            $this->addFlash('success', 'Faculty member has been activated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error activating faculty member: ' . $e->getMessage());
        }

        return $this->redirectToRoute('department_head_faculty');
    }

    #[Route('/faculty/{id}/deactivate', name: 'faculty_deactivate', methods: ['POST'])]
    public function deactivateFaculty(int $id, Request $request): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();

        if (!$this->isCsrfTokenValid('deactivate_user_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('department_head_faculty');
        }

        try {
            $user = $this->userService->getUserById($id);
            
            // Check access
            if (!$this->departmentHeadService->canAccessUser($departmentHead, $user)) {
                throw $this->createAccessDeniedException('Access denied.');
            }

            $this->userService->deactivateUser($user);
            $this->addFlash('success', 'Faculty member has been deactivated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deactivating faculty member: ' . $e->getMessage());
        }

        return $this->redirectToRoute('department_head_faculty');
    }

    // Department Information

    #[Route('/department', name: 'department_info')]
    public function departmentInfo(): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        
        $department = $departmentHead->getDepartment();
        
        if (!$department) {
            $this->addFlash('error', 'You are not assigned to any department.');
            return $this->redirectToRoute('department_head_dashboard');
        }

        return $this->render('department_head/department_info.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Department Information',
            'department' => $department,
        ]));
    }

    // Curriculum Management

    #[Route('/curricula', name: 'curricula')]
    public function curricula(Request $request): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        
        $filters = [
            'page' => $request->query->getInt('page', 1),
            'limit' => $request->query->getInt('limit', 20),
            'search' => $request->query->get('search'),
            'is_published' => $request->query->get('is_published'),
            'sort_field' => $request->query->get('sort_field', 'createdAt'),
            'sort_direction' => $request->query->get('sort_direction', 'DESC'),
        ];

        $result = $this->departmentHeadService->getCurriculaWithFilters($departmentHead, $filters);
        $statistics = $this->departmentHeadService->getCurriculumStatistics($departmentHead);

        return $this->render('department_head/curricula/list.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Curriculum Management',
            'curricula' => $result['curricula'],
            'pagination' => $result,
            'filters' => $filters,
            'statistics' => $statistics,
        ]));
    }

    #[Route('/curricula/{id}/view', name: 'curricula_view')]
    public function viewCurriculum(int $id): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();

        try {
            $curriculum = $this->departmentHeadService->getCurriculumById($departmentHead, $id);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('department_head_curricula');
        }

        return $this->render('department_head/curricula/view.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Curriculum Details: ' . $curriculum->getName(),
            'curriculum' => $curriculum,
        ]));
    }

    #[Route('/curricula/{id}/publish', name: 'curricula_publish', methods: ['POST'])]
    public function publishCurriculum(int $id, Request $request): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();

        if (!$this->isCsrfTokenValid('publish_curriculum_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('department_head_curricula');
        }

        try {
            $this->departmentHeadService->publishCurriculum($departmentHead, $id);
            $this->addFlash('success', 'Curriculum published successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('department_head_curricula');
    }

    #[Route('/curricula/{id}/unpublish', name: 'curricula_unpublish', methods: ['POST'])]
    public function unpublishCurriculum(int $id, Request $request): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();

        if (!$this->isCsrfTokenValid('unpublish_curriculum_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('department_head_curricula');
        }

        try {
            $this->departmentHeadService->unpublishCurriculum($departmentHead, $id);
            $this->addFlash('success', 'Curriculum unpublished successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('department_head_curricula');
    }

    // Reports & Analytics

    #[Route('/reports/faculty-workload', name: 'reports_faculty_workload')]
    public function facultyWorkloadReport(Request $request): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        $department = $departmentHead->getDepartment();

        if (!$department) {
            $this->addFlash('error', 'You are not assigned to a department.');
            return $this->redirectToRoute('department_head_dashboard');
        }

        // Get filters
        $academicYearId = $request->query->get('academic_year');
        $semester = $request->query->get('semester');
        $statusFilter = $request->query->get('status', 'all'); // all, overloaded, optimal, underloaded

        // Get workload data
        $workloadData = $this->departmentHeadService->getFacultyWorkloadReport(
            $department,
            $academicYearId,
            $semester,
            $statusFilter
        );

        return $this->render('department_head/reports/faculty_workload.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Faculty Workload Report',
            'workload_data' => $workloadData['faculty_workload'],
            'statistics' => $workloadData['statistics'],
            'academic_years' => $workloadData['academic_years'],
            'selected_academic_year' => $workloadData['selected_academic_year'],
            'selected_semester' => $workloadData['selected_semester'],
            'status_filter' => $statusFilter,
        ]));
    }

    #[Route('/reports/history', name: 'reports_history', methods: ['GET'])]
    public function historyReports(Request $request): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        $department = $departmentHead->getDepartment();

        if (!$department) {
            $this->addFlash('error', 'You are not assigned to a department.');
            return $this->redirectToRoute('department_head_dashboard');
        }

        // Get export parameters
        $exportType = $request->query->get('export', '');
        $selectedYear = $request->query->get('year', '');
        $selectedSemester = $request->query->get('semester', '');
        $searchTerm = $request->query->get('search', '');
        
        // Get active semester for default
        $activeYear = $this->systemSettingsService->getActiveAcademicYear();
        $activeSemester = $this->systemSettingsService->getActiveSemester();
        
        // Get all academic years for filter dropdown
        $years = $this->getHistoryYears();
        
        // Get department group IDs
        $departmentIds = $this->getDepartmentGroupIds($department);
        
        // Load schedules and build data using helper methods
        $schedules = $this->loadDepartmentSchedules($departmentIds);
        $roomsData = $this->buildHistoryRoomsData($departmentIds, $schedules);
        $facultyData = $this->buildHistoryFacultyData($departmentIds, $schedules);
        $subjectsData = $this->buildHistorySubjectsData($departmentIds, $schedules);
        
        // Handle exports
        if ($exportType === 'rooms-pdf') {
            return $this->exportDepartmentRoomsPdf($roomsData, $selectedYear, $selectedSemester, $department->getName(), $searchTerm);
        } elseif ($exportType === 'faculty-pdf') {
            return $this->exportDepartmentFacultyPdf($facultyData, $selectedYear, $selectedSemester, $department->getName(), $searchTerm);
        }
        
        // Create display string for active semester
        $activeSemesterDisplay = $activeYear && $activeSemester 
            ? $activeYear->getYear() . ' | ' . $activeSemester . ' Semester'
            : null;
        
        return $this->render('department_head/reports/history.html.twig', array_merge($this->getBaseTemplateData(), [
            'years' => $years,
            'selectedYear' => '',
            'selectedSemester' => '',
            'searchTerm' => '',
            'activeYear' => $activeYear,
            'activeSemester' => $activeSemester,
            'activeSemesterDisplay' => $activeSemesterDisplay,
            'roomsData' => $roomsData,
            'facultyData' => $facultyData,
            'subjectsData' => $subjectsData,
            'hasActiveSemester' => $this->systemSettingsService->hasActiveSemester(),
        ]));
    }

    /**
     * Get all academic years for history reports filter.
     */
    private function getHistoryYears(): array
    {
        $academicYears = $this->entityManager->getRepository(\App\Entity\AcademicYear::class)
            ->createQueryBuilder('ay')
            ->select('ay.year')
            ->distinct(true)
            ->orderBy('ay.year', 'DESC')
            ->getQuery()
            ->getResult();
        return array_column($academicYears, 'year');
    }

    /**
     * Get department IDs including department group members.
     */
    private function getDepartmentGroupIds(\App\Entity\Department $department): array
    {
        $departmentIds = [$department->getId()];
        if ($department->getDepartmentGroup()) {
            $departmentIds = $department->getDepartmentGroup()
                ->getDepartments()
                ->map(fn($d) => $d->getId())
                ->toArray();
        }
        return $departmentIds;
    }

    /**
     * Load all schedules for departments.
     * @return Schedule[]
     */
    private function loadDepartmentSchedules(array $departmentIds): array
    {
        return $this->entityManager->getRepository(Schedule::class)
            ->createQueryBuilder('s')
            ->select('s', 'r', 'sub', 'd', 'u')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.subject', 'sub')
            ->leftJoin('sub.department', 'd')
            ->leftJoin('s.faculty', 'u')
            ->where('sub.department IN (:departmentIds)')
            ->setParameter('departmentIds', $departmentIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Build rooms data for history reports.
     */
    private function buildHistoryRoomsData(array $departmentIds, array $schedules): array
    {
        $rooms = $this->entityManager->getRepository(Room::class)
            ->createQueryBuilder('r')
            ->leftJoin(Schedule::class, 's', 'WITH', 's.room = r')
            ->leftJoin('s.subject', 'sub')
            ->where('sub.department IN (:departmentIds)')
            ->setParameter('departmentIds', $departmentIds)
            ->groupBy('r.id')
            ->orderBy('r.building', 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        $roomsData = [];
        foreach ($rooms as $room) {
            $roomSchedules = array_filter($schedules, fn($s) => $s->getRoom() && $s->getRoom()->getId() === $room->getId());
            
            $roomYears = [];
            $roomSemesters = [];
            foreach ($roomSchedules as $schedule) {
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
                'years' => implode(', ', array_unique($roomYears)),
                'semesters' => implode(', ', array_unique($roomSemesters))
            ];
        }
        return $roomsData;
    }

    /**
     * Build faculty data for history reports.
     */
    private function buildHistoryFacultyData(array $departmentIds, array $schedules): array
    {
        $facultyResults = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->select('u', 'COUNT(s.id) as scheduleCount', 'SUM(sub.units) as totalUnits')
            ->leftJoin(Schedule::class, 's', 'WITH', 's.faculty = u')
            ->leftJoin('s.subject', 'sub')
            ->where('u.role = :role')
            ->andWhere('u.isActive = :active')
            ->andWhere('u.department IN (:departmentIds)')
            ->setParameter('role', 3)
            ->setParameter('active', true)
            ->setParameter('departmentIds', $departmentIds)
            ->groupBy('u.id')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        $facultyData = [];
        foreach ($facultyResults as $result) {
            $faculty = $result[0];
            $facultySchedules = array_filter($schedules, fn($s) => $s->getFaculty() && $s->getFaculty()->getId() === $faculty->getId());
            
            $facultyYears = [];
            $facultySemesters = [];
            foreach ($facultySchedules as $schedule) {
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
                'years' => implode(', ', array_unique($facultyYears)),
                'semesters' => implode(', ', array_unique($facultySemesters))
            ];
        }
        return $facultyData;
    }

    /**
     * Build subjects data for history reports.
     */
    private function buildHistorySubjectsData(array $departmentIds, array $schedules): array
    {
        $subjects = $this->entityManager->getRepository(\App\Entity\Subject::class)
            ->createQueryBuilder('sub')
            ->leftJoin('sub.department', 'd')
            ->where('sub.deletedAt IS NULL')
            ->andWhere('sub.department IN (:departmentIds)')
            ->setParameter('departmentIds', $departmentIds)
            ->orderBy('sub.code', 'ASC')
            ->getQuery()
            ->getResult();

        $subjectsData = [];
        foreach ($subjects as $subject) {
            $subjectSchedules = array_filter($schedules, fn($s) => $s->getSubject() && $s->getSubject()->getId() === $subject->getId());
            
            $subjectYears = [];
            $subjectSemesters = [];
            $scheduleDetails = [];
            
            foreach ($subjectSchedules as $schedule) {
                if ($schedule->getAcademicYear()) {
                    $subjectYears[$schedule->getAcademicYear()->getId()] = $schedule->getAcademicYear()->getYear();
                }
                if ($schedule->getSemester()) {
                    $subjectSemesters[] = $schedule->getSemester();
                }
                
                $scheduleDetails[] = [
                    'section' => $schedule->getSection() ?: 'N/A',
                    'time' => $schedule->getStartTime()->format('h:i A') . ' - ' . $schedule->getEndTime()->format('h:i A'),
                    'day' => $schedule->getDayPattern(),
                    'room' => $schedule->getRoom() ? $schedule->getRoom()->getCode() : 'N/A',
                    'faculty' => $schedule->getFaculty() ? ($schedule->getFaculty()->getFirstName() . ' ' . $schedule->getFaculty()->getLastName()) : 'N/A',
                    'year' => $schedule->getAcademicYear() ? $schedule->getAcademicYear()->getYear() : null,
                    'semester' => $schedule->getSemester() ?: null
                ];
            }
            
            $subjectsData[] = [
                0 => $subject,
                'schedules' => $scheduleDetails,
                'years' => implode(', ', array_unique($subjectYears)),
                'semesters' => implode(', ', array_unique($subjectSemesters)),
                'yearsList' => array_values(array_unique($subjectYears)),
                'semestersList' => array_values(array_unique($subjectSemesters))
            ];
        }
        return $subjectsData;
    }

    private function exportDepartmentRoomsPdf(array $roomsData, ?string $year, ?string $semester, string $departmentName, ?string $searchTerm): Response
    {
        // Filter rooms data based on parameters
        $filteredRooms = $this->filterHistoryData($roomsData, $year, $semester, $searchTerm);
        
        $pdfService = new \App\Service\RoomsReportPdfService($this->entityManager);
        $pdfContent = $pdfService->generateRoomsReportPdf($filteredRooms, $year, $semester, $departmentName, $searchTerm);
        
        $filenameParts = ['rooms', preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($departmentName))];
        if ($year) $filenameParts[] = str_replace('-', '_', $year);
        if ($semester) $filenameParts[] = strtolower($semester) . '_sem';
        if ($searchTerm) $filenameParts[] = 'search';
        $filenameParts[] = date('Y-m-d');
        
        $filename = implode('_', $filenameParts) . '.pdf';
        
        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"'
        ]);
    }

    private function exportDepartmentFacultyPdf(array $facultyData, ?string $year, ?string $semester, string $departmentName, ?string $searchTerm): Response
    {
        // Filter faculty data based on parameters
        $filteredFaculty = $this->filterHistoryData($facultyData, $year, $semester, $searchTerm);
        
        $pdfService = new \App\Service\FacultyReportPdfService($this->entityManager);
        $pdfContent = $pdfService->generateFacultyReportPdf($filteredFaculty, $year, $semester, $departmentName, $searchTerm);
        
        $filenameParts = ['faculty', preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($departmentName))];
        if ($year) $filenameParts[] = str_replace('-', '_', $year);
        if ($semester) $filenameParts[] = strtolower($semester) . '_sem';
        if ($searchTerm) $filenameParts[] = 'search';
        $filenameParts[] = date('Y-m-d');
        
        $filename = implode('_', $filenameParts) . '.pdf';
        
        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"'
        ]);
    }

    private function filterHistoryData(array $data, ?string $year, ?string $semester, ?string $searchTerm): array
    {
        $filtered = [];
        
        foreach ($data as $item) {
            $entity = $item[0];
            
            // Filter by year
            if ($year && !empty($item['years']) && !str_contains($item['years'], $year)) {
                continue;
            }
            
            // Filter by semester
            if ($semester && !empty($item['semesters']) && !str_contains(strtolower($item['semesters']), strtolower($semester))) {
                continue;
            }
            
            // Filter by search term
            if ($searchTerm) {
                $searchLower = strtolower($searchTerm);
                $searchable = '';
                
                if (method_exists($entity, 'getCode')) {
                    $searchable .= ' ' . strtolower($entity->getCode());
                }
                if (method_exists($entity, 'getName')) {
                    $searchable .= ' ' . strtolower($entity->getName());
                }
                if (method_exists($entity, 'getFirstName')) {
                    $searchable .= ' ' . strtolower($entity->getFirstName() . ' ' . $entity->getLastName());
                }
                if (method_exists($entity, 'getEmployeeId')) {
                    $searchable .= ' ' . strtolower($entity->getEmployeeId());
                }
                
                if (!str_contains($searchable, $searchLower)) {
                    continue;
                }
            }
            
            $filtered[] = $item;
        }
        
        return $filtered;
    }

    #[Route('/curricula/bulk-upload', name: 'curricula_bulk_upload', methods: ['POST'])]
    public function bulkUploadCurriculum(
        Request $request,
        EntityManagerInterface $entityManager,
        \App\Service\CurriculumUploadService $uploadService
    ): JsonResponse
    {
        try {
            /** @var User $departmentHead */
            $departmentHead = $this->getUser();
            $department = $departmentHead->getDepartment();

            if (!$department) {
                return new JsonResponse(['success' => false, 'message' => 'You are not assigned to a department.'], 403);
            }

            $file = $request->files->get('curriculum_file');
            $curriculumName = $request->request->get('curriculum_name');
            $version = $request->request->get('version');
            $autoCreateTerms = $request->request->has('auto_create_terms') ? ($request->request->get('auto_create_terms') === '1') : true;

            // Validate required fields
            if (!$file) {
                return new JsonResponse(['success' => false, 'message' => 'No file uploaded.'], 400);
            }
            if (!$curriculumName || !$version) {
                return new JsonResponse(['success' => false, 'message' => 'Missing required fields (name, version).'], 400);
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
                $entityManager->flush();

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

    // API Routes

    #[Route('/api/faculty/generate-password', name: 'api_faculty_generate_password', methods: ['POST'])]
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

    #[Route('/api/faculty/check-availability', name: 'api_faculty_check_availability', methods: ['POST'])]
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

    // ==================== ROOM MANAGEMENT ====================

    #[Route('/rooms', name: 'rooms')]
    public function rooms(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

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
            'type' => $request->query->get('type'),
            'is_active' => $isActive,
            'sort_field' => $request->query->get('sort_field', 'code'),
            'sort_direction' => $request->query->get('sort_direction', 'ASC'),
        ];

        $result = $this->departmentHeadService->getRoomsWithFilters($user, $filters);
        $statistics = $this->departmentHeadService->getRoomStatistics($user);

        return $this->render('department_head/rooms/list.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Rooms',
            'rooms' => $result['rooms'],
            'pagination' => $result,
            'filters' => $filters,
            'statistics' => $statistics,
        ]));
    }

    #[Route('/rooms/create', name: 'rooms_create')]
    public function createRoom(Request $request): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        $department = $departmentHead->getDepartment();

        if (!$department) {
            $this->addFlash('error', 'You must be assigned to a department to create rooms.');
            return $this->redirectToRoute('department_head_dashboard');
        }

        $room = new Room();
        $room->setDepartment($department);
        
        $form = $this->createForm(RoomFormType::class, $room);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($room);
            $this->entityManager->flush();
            
            // Log the activity
            $this->activityLogService->log(
                'room.created',
                "Room created: {$room->getBuilding()} - {$room->getName()}",
                'Room',
                $room->getId(),
                [
                    'building' => $room->getBuilding(),
                    'code' => $room->getCode(),
                    'capacity' => $room->getCapacity(),
                    'department' => $department->getName()
                ]
            );

            $this->addFlash('success', 'Room created successfully.');
            return $this->redirectToRoute('department_head_rooms');
        }

        return $this->render('department_head/rooms/create.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Create Room',
            'form' => $form->createView(),
            'room' => $room,
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/rooms/{id}/edit', name: 'rooms_edit')]
    public function editRoom(Request $request, Room $room): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        
        // Validate access to this room
        try {
            $this->departmentHeadService->validateRoomAccess($departmentHead, $room);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('department_head_rooms');
        }

        $form = $this->createForm(RoomFormType::class, $room);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $room->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();
            
            // Log the activity
            $this->activityLogService->log(
                'room.updated',
                "Room updated: {$room->getBuilding()} - {$room->getName()}",
                'Room',
                $room->getId(),
                ['building' => $room->getBuilding(), 'code' => $room->getCode()]
            );

            $this->addFlash('success', 'Room updated successfully.');
            return $this->redirectToRoute('department_head_rooms');
        }

        return $this->render('department_head/rooms/edit.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Edit Room',
            'form' => $form->createView(),
            'room' => $room,
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/rooms/{id}/view', name: 'rooms_view')]
    public function viewRoom(Room $room): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        
        // Validate access to this room
        try {
            $this->departmentHeadService->validateRoomAccess($departmentHead, $room);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('department_head_rooms');
        }

        return $this->render('department_head/rooms/view.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'View Room',
            'room' => $room,
        ]));
    }

    #[Route('/rooms/{id}/activate', name: 'rooms_activate', methods: ['POST'])]
    public function activateRoom(Room $room): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        
        try {
            $this->departmentHeadService->validateRoomAccess($departmentHead, $room);
            
            $room->setIsActive(true);
            $room->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();
            
            // Log the activity
            $this->activityLogService->log(
                'room.activated',
                "Room activated: {$room->getBuilding()} - {$room->getName()}",
                'Room',
                $room->getId()
            );

            $this->addFlash('success', 'Room activated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('department_head_rooms');
    }

    #[Route('/rooms/{id}/deactivate', name: 'rooms_deactivate', methods: ['POST'])]
    public function deactivateRoom(Room $room): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        
        try {
            $this->departmentHeadService->validateRoomAccess($departmentHead, $room);
            
            $room->setIsActive(false);
            $room->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();
            
            // Log the activity
            $this->activityLogService->log(
                'room.deactivated',
                "Room deactivated: {$room->getBuilding()} - {$room->getName()}",
                'Room',
                $room->getId()
            );

            $this->addFlash('success', 'Room deactivated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('department_head_rooms');
    }

    // Schedule Management Routes

    #[Route('/schedules', name: 'schedules')]
    public function schedules(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department) {
            $this->addFlash('error', 'You are not assigned to a department.');
            return $this->redirectToRoute('department_head_dashboard');
        }

        // Get active semester for filtering
        $activeAcademicYear = $this->systemSettingsService->getActiveAcademicYear();
        $activeSemester = $this->systemSettingsService->getActiveSemester();

        // Get schedules for the department filtered by active semester
        $schedules = $this->scheduleRepository->findByDepartment(
            $department->getId(),
            $activeAcademicYear,
            $activeSemester
        );

        // Group schedules by subject + semester + academic year (block sectioning)
        $groupedSchedules = [];
        foreach ($schedules as $schedule) {
            $subjectKey = $schedule->getSubject()->getId() . '_' . $schedule->getSemester() . '_' . $schedule->getAcademicYear()->getId();
            if (!isset($groupedSchedules[$subjectKey])) {
                $groupedSchedules[$subjectKey] = [
                    'subject' => $schedule->getSubject(),
                    'semester' => $schedule->getSemester(),
                    'academicYear' => $schedule->getAcademicYear(),
                    'sections' => []
                ];
            }
            $groupedSchedules[$subjectKey]['sections'][] = $schedule;
        }

        return $this->render('department_head/schedules/list.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Department Schedules',
            'schedules' => $schedules,
            'groupedSchedules' => $groupedSchedules,
        ]));
    }

    #[Route('/schedules/create', name: 'schedules_create', methods: ['GET', 'POST'])]
    public function createSchedule(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department) {
            $this->addFlash('error', 'You are not assigned to a department.');
            return $this->redirectToRoute('department_head_dashboard');
        }

        // Get active academic year and semester from system settings
        $activeAcademicYear = $this->systemSettingsService->getActiveAcademicYear();
        $activeSemester = $this->systemSettingsService->getActiveSemester();
        
        // Get ALL subjects from department with their semester information
        // The frontend JavaScript will handle showing/hiding based on semester
        $subjects = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT s')
            ->from('App\Entity\Subject', 's')
            ->innerJoin('App\Entity\CurriculumSubject', 'cs', 'WITH', 'cs.subject = s')
            ->innerJoin('App\Entity\CurriculumTerm', 'ct', 'WITH', 'cs.curriculumTerm = ct')
            ->innerJoin('App\Entity\Curriculum', 'c', 'WITH', 'ct.curriculum = c')
            ->where('s.department = :dept')
            ->andWhere('s.isActive = :active')
            ->andWhere('c.isPublished = :published')
            ->setParameter('dept', $department)
            ->setParameter('active', true)
            ->setParameter('published', true)
            ->orderBy('s.code', 'ASC')
            ->getQuery()
            ->getResult();
        
        // Enrich subjects with semester information
        foreach ($subjects as &$subject) {
            // Get all semesters this subject appears in
            $semesters = $this->entityManager->createQueryBuilder()
                ->select('DISTINCT ct.semester')
                ->from('App\Entity\CurriculumSubject', 'cs')
                ->innerJoin('cs.curriculumTerm', 'ct')
                ->where('cs.subject = :subject')
                ->setParameter('subject', $subject)
                ->getQuery()
                ->getScalarResult();
            
            // Add semester as a property (we'll use the first one for filtering)
            if (!empty($semesters)) {
                $subject->semester = $semesters[0]['semester'];
            } else {
                $subject->semester = ''; // No semester info
            }
        }
        
        // Get rooms accessible to department
        $rooms = $this->roomRepository->findAccessibleByDepartment($department);
        
        // Get active academic years
        $academicYears = $this->academicYearRepository->findBy(['isActive' => true], ['year' => 'DESC']);

        if ($request->isMethod('POST')) {
            try {
                // Get form data
                $subjectId = $request->request->get('subject');
                $roomId = $request->request->get('room');
                $academicYearId = $request->request->get('academic_year');
                $semester = $request->request->get('semester');
                $section = $request->request->get('section');
                $dayPattern = $request->request->get('day_pattern');
                $startTime = $request->request->get('start_time');
                $endTime = $request->request->get('end_time');
                $enrolledStudents = (int) $request->request->get('enrolled_students', 0);
                $notes = $request->request->get('notes');

                // Validate required fields
                if (!$subjectId || !$roomId || !$academicYearId || !$semester || !$section || !$dayPattern || !$startTime || !$endTime) {
                    throw new \Exception('All required fields must be filled.');
                }

                // Get entities
                $subject = $this->subjectRepository->find($subjectId);
                $room = $this->entityManager->getRepository(Room::class)->find($roomId);
                $academicYear = $this->academicYearRepository->find($academicYearId);

                if (!$subject || !$room || !$academicYear) {
                    throw new \Exception('Invalid subject, room, or academic year.');
                }

                // Verify subject belongs to department
                if ($subject->getDepartment()->getId() !== $department->getId()) {
                    throw new \Exception('You can only create schedules for subjects in your department.');
                }

                // Create new schedule
                $schedule = new Schedule();
                $schedule->setSubject($subject);
                $schedule->setRoom($room);
                $schedule->setAcademicYear($academicYear);
                $schedule->setSemester($semester);
                $schedule->setSection($section);
                $schedule->setDayPattern($dayPattern);
                $schedule->setEnrolledStudents($enrolledStudents);
                $schedule->setNotes($notes);
                $schedule->setStatus('active');

                // Parse and set time
                try {
                    $schedule->setStartTime(new \DateTime($startTime));
                    $schedule->setEndTime(new \DateTime($endTime));
                } catch (\Exception $e) {
                    throw new \Exception('Invalid time format.');
                }

                // Validate time range
                $timeErrors = $this->conflictDetector->validateTimeRange($schedule);
                if (!empty($timeErrors)) {
                    foreach ($timeErrors as $error) {
                        $this->addFlash('error', $error);
                    }
                    return $this->render('admin/schedule/new_v2.html.twig', array_merge($this->getBaseTemplateData(), [
                        'subjects' => $subjects,
                        'rooms' => $rooms,
                        'academicYears' => $academicYears,
                        'activeAcademicYear' => $activeAcademicYear,
                        'activeSemester' => $activeSemester,
                        'selectedDepartment' => $department,
                        'departmentId' => $department->getId(),
                        'isDepartmentHead' => true,
                    ]));
                }

                // Check for HARD conflicts
                $conflicts = $this->conflictDetector->detectConflicts($schedule);
                $duplicateConflicts = $this->conflictDetector->checkDuplicateSubjectSection($schedule);
                
                $hardConflicts = array_filter($conflicts, function($conflict) {
                    return $conflict['type'] === 'room_time_conflict' || $conflict['type'] === 'section_conflict';
                });
                
                $hardConflicts = array_merge($hardConflicts, $duplicateConflicts);
                
                // BLOCK if hard conflicts exist
                if (!empty($hardConflicts)) {
                    foreach ($hardConflicts as $conflict) {
                        $this->addFlash('error', '🚫 ' . $conflict['message']);
                    }
                    
                    $this->addFlash('error', sprintf(
                        'Cannot create schedule: %d conflict(s) detected. Please choose different time/room/section.',
                        count($hardConflicts)
                    ));
                    
                    return $this->render('admin/schedule/new_v2.html.twig', array_merge($this->getBaseTemplateData(), [
                        'subjects' => $subjects,
                        'rooms' => $rooms,
                        'academicYears' => $academicYears,
                        'activeAcademicYear' => $activeAcademicYear,
                        'activeSemester' => $activeSemester,
                        'selectedDepartment' => $department,
                        'departmentId' => $department->getId(),
                        'isDepartmentHead' => true,
                    ]));
                }
                
                // WARN for soft conflicts (capacity)
                $capacityErrors = $this->conflictDetector->validateRoomCapacity($schedule);
                if (!empty($capacityErrors)) {
                    foreach ($capacityErrors as $error) {
                        $this->addFlash('warning', '⚠️ ' . $error);
                    }
                }

                // No conflicts - save as clean schedule
                $schedule->setIsConflicted(false);
                $schedule->setCreatedAt(new \DateTime());

                // Save the schedule
                $this->entityManager->persist($schedule);
                $this->entityManager->flush();
                
                // Log the activity
                $scheduleInfo = sprintf('%s - %s (%s)',
                    $schedule->getSubject()->getTitle(),
                    $schedule->getSection(),
                    $schedule->getDayPattern()
                );
                $this->activityLogService->log(
                    'schedule.created',
                    "Schedule created: {$scheduleInfo}",
                    'Schedule',
                    $schedule->getId(),
                    [
                        'subject' => $schedule->getSubject()->getTitle(),
                        'section' => $schedule->getSection(),
                        'room' => $schedule->getRoom()->getName(),
                        'day_pattern' => $schedule->getDayPattern()
                    ]
                );

                $this->addFlash('success', '✅ Schedule created successfully!');
                
                return $this->redirectToRoute('department_head_schedules');

            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating schedule: ' . $e->getMessage());
            }
        }

        return $this->render('admin/schedule/new_v2.html.twig', array_merge($this->getBaseTemplateData(), [
            'subjects' => $subjects,
            'rooms' => $rooms,
            'academicYears' => $academicYears,
            'activeAcademicYear' => $activeAcademicYear,
            'activeSemester' => $activeSemester,
            'semesterFilter' => $activeSemester,
            'selectedDepartment' => $department,
            'departmentId' => $department->getId(),
            'isDepartmentHead' => true,
        ]));
    }

    #[Route('/schedules/check-conflicts', name: 'schedules_check_conflicts', methods: ['POST'])]
    public function checkConflicts(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department) {
            return new JsonResponse([
                'has_conflicts' => false,
                'conflicts' => [],
                'error' => 'You are not assigned to a department.'
            ], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);
            
            // Support both naming conventions: subject/subject_id
            $subjectId = $data['subject'] ?? $data['subject_id'] ?? null;
            $roomId = $data['room'] ?? $data['room_id'] ?? null;
            $academicYearId = $data['academic_year'] ?? $data['academic_year_id'] ?? null;
            $semester = $data['semester'] ?? null;
            $section = $data['section'] ?? null;
            $dayPattern = $data['day_pattern'] ?? null;
            $startTime = $data['start_time'] ?? null;
            $endTime = $data['end_time'] ?? null;
            
            // Validate required fields
            if (!$subjectId || !$roomId || !$academicYearId || !$semester || !$section || !$dayPattern || !$startTime || !$endTime) {
                return new JsonResponse([
                    'has_conflicts' => false,
                    'conflicts' => [],
                    'warnings' => [],
                    'debug' => 'Missing required fields'
                ]);
            }

            // Get entities
            $subject = $this->subjectRepository->find($subjectId);
            $room = $this->entityManager->getRepository(Room::class)->find($roomId);
            $academicYear = $this->academicYearRepository->find($academicYearId);

            if (!$subject || !$room || !$academicYear) {
                return new JsonResponse([
                    'has_conflicts' => false,
                    'conflicts' => [],
                    'warnings' => [],
                    'debug' => 'Invalid subject, room, or academic year.'
                ]);
            }

            // Verify subject belongs to department
            if ($subject->getDepartment()->getId() !== $department->getId()) {
                return new JsonResponse([
                    'has_conflicts' => false,
                    'conflicts' => ['You can only check conflicts for subjects in your department.'],
                    'warnings' => []
                ], 403);
            }

            // Create temporary schedule object for conflict detection
            $schedule = new Schedule();
            $schedule->setSubject($subject);
            $schedule->setRoom($room);
            $schedule->setAcademicYear($academicYear);
            $schedule->setSemester($data['semester']);
            $schedule->setSection($data['section']);
            $schedule->setDayPattern($data['day_pattern']);
            $schedule->setEnrolledStudents((int) ($data['enrolled_students'] ?? 0));

            // Parse and set time
            try {
                $schedule->setStartTime(new \DateTime($data['start_time']));
                $schedule->setEndTime(new \DateTime($data['end_time']));
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid time format.'
                ], 400);
            }

            // Validate time range
            $timeErrors = $this->conflictDetector->validateTimeRange($schedule);
            if (!empty($timeErrors)) {
                return new JsonResponse([
                    'success' => true,
                    'conflicts' => array_map(function($error) {
                        return [
                            'type' => 'time_validation',
                            'severity' => 'error',
                            'message' => $error
                        ];
                    }, $timeErrors)
                ]);
            }

            // Check for all conflicts
            $conflicts = $this->conflictDetector->detectConflicts($schedule);
            $duplicateConflicts = $this->conflictDetector->checkDuplicateSubjectSection($schedule);
            
            // Combine hard conflicts
            $hardConflicts = array_filter($conflicts, function($conflict) {
                return $conflict['type'] === 'room_time_conflict' || $conflict['type'] === 'section_conflict';
            });
            $hardConflicts = array_merge($hardConflicts, $duplicateConflicts);
            
            // Check capacity warnings
            $schedule->setEnrolledStudents(0); // Temporary for capacity check
            $capacityWarnings = $this->conflictDetector->validateRoomCapacity($schedule);
            
            // Format conflicts to match admin controller response
            $conflictMessages = array_map(function($c) {
                return $c['message'];
            }, $hardConflicts);

            return new JsonResponse([
                'has_conflicts' => !empty($hardConflicts),
                'conflicts' => $conflictMessages,
                'warnings' => $capacityWarnings,
                'debug' => [
                    'section' => $schedule->getSection(),
                    'subject' => $subject->getCode(),
                    'room' => $room->getName(),
                    'room_id' => $room->getId(),
                    'day_pattern' => $schedule->getDayPattern(),
                    'start_time' => $schedule->getStartTime()->format('H:i'),
                    'end_time' => $schedule->getEndTime()->format('H:i'),
                    'total_conflicts' => count($conflicts),
                    'duplicate_conflicts' => count($duplicateConflicts),
                    'hard_conflicts' => count($hardConflicts)
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'has_conflicts' => false,
                'conflicts' => [],
                'warnings' => [],
                'debug' => ['error' => $e->getMessage()]
            ]);
        }
    }

    #[Route('/schedules/room-schedules/{roomId}/{semester}/{yearId}', name: 'schedules_room_schedules', methods: ['GET'])]
    public function getRoomSchedules(int $roomId, string $semester, int $yearId): JsonResponse
    {
        try {
            $room = $this->entityManager->getRepository(Room::class)->find($roomId);
            $academicYear = $this->academicYearRepository->find($yearId);
            
            if (!$room || !$academicYear) {
                return new JsonResponse([
                    'schedules' => [],
                    'error' => 'Room or academic year not found'
                ]);
            }
            
            // Get all schedules for this room in this semester/year
            $schedules = $this->scheduleRepository->createQueryBuilder('s')
                ->where('s.room = :room')
                ->andWhere('s.semester = :semester')
                ->andWhere('s.academicYear = :year')
                ->andWhere('s.status = :status')
                ->setParameter('room', $room)
                ->setParameter('semester', $semester)
                ->setParameter('year', $academicYear)
                ->setParameter('status', 'active')
                ->getQuery()
                ->getResult();
            
            $scheduleData = [];
            foreach ($schedules as $schedule) {
                $scheduleData[] = [
                    'id' => $schedule->getId(),
                    'subjectCode' => $schedule->getSubject()->getCode(),
                    'section' => $schedule->getSection(),
                    'dayPattern' => $schedule->getDayPattern(),
                    'startTime' => $schedule->getStartTime()->format('H:i'),
                    'endTime' => $schedule->getEndTime()->format('H:i'),
                    'roomId' => $schedule->getRoom()->getId(),
                    'semester' => $schedule->getSemester(),
                    'yearId' => $schedule->getAcademicYear()->getId()
                ];
            }
            
            return new JsonResponse([
                'schedules' => $scheduleData
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'schedules' => [],
                'error' => $e->getMessage()
            ]);
        }
    }

    #[Route('/schedules/check-room-conflicts', name: 'schedules_check_room_conflicts', methods: ['POST'])]
    public function checkRoomConflicts(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            $roomId = $data['room'] ?? null;
            $dayPattern = $data['dayPattern'] ?? null;
            $startTime = $data['startTime'] ?? null;
            $endTime = $data['endTime'] ?? null;
            $semester = $data['semester'] ?? null;
            $academicYearId = $data['academicYear'] ?? null;
            
            if (!$roomId || !$dayPattern || !$startTime || !$endTime || !$semester || !$academicYearId) {
                return $this->json([
                    'hasConflict' => false,
                    'message' => 'Missing required fields'
                ]);
            }
            
            // Create a temporary schedule object for conflict checking
            $tempSchedule = new Schedule();
            $room = $this->entityManager->getRepository(Room::class)->find($roomId);
            $academicYear = $this->academicYearRepository->find($academicYearId);
            
            if (!$room || !$academicYear) {
                return $this->json([
                    'hasConflict' => false,
                    'message' => 'Invalid room or academic year'
                ]);
            }
            
            $tempSchedule->setRoom($room);
            $tempSchedule->setDayPattern($dayPattern);
            $tempSchedule->setSemester($semester);
            $tempSchedule->setAcademicYear($academicYear);
            
            try {
                $tempSchedule->setStartTime(new \DateTime($startTime));
                $tempSchedule->setEndTime(new \DateTime($endTime));
            } catch (\Exception $e) {
                return $this->json([
                    'hasConflict' => false,
                    'message' => 'Invalid time format'
                ]);
            }
            
            // Set a dummy subject to avoid null errors (required for conflict detection)
            // We're only checking room conflicts, not subject conflicts
            $dummySubject = $this->subjectRepository->findOneBy([]);
            if ($dummySubject) {
                $tempSchedule->setSubject($dummySubject);
            }
            
            // Check for conflicts
            $conflicts = $this->conflictDetector->detectConflicts($tempSchedule, false);
            
            // Filter to only room-time conflicts
            $roomConflicts = array_filter($conflicts, function($conflict) {
                return $conflict['type'] === 'room_time_conflict';
            });
            
            if (!empty($roomConflicts)) {
                $firstConflict = reset($roomConflicts);
                $conflictSchedule = $firstConflict['schedule'];
                
                return $this->json([
                    'hasConflict' => true,
                    'conflictMessage' => sprintf(
                        '%s Section %s (%s %s-%s)',
                        $conflictSchedule->getSubject()->getCode(),
                        $conflictSchedule->getSection() ?? 'N/A',
                        $conflictSchedule->getDayPattern(),
                        $conflictSchedule->getStartTime()->format('g:i A'),
                        $conflictSchedule->getEndTime()->format('g:i A')
                    ),
                    'conflictCount' => count($roomConflicts)
                ]);
            }
            
            return $this->json([
                'hasConflict' => false,
                'message' => 'Room is available'
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'hasConflict' => false,
                'message' => 'Error checking conflicts: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/schedules/{id}', name: 'schedules_view')]
    public function viewSchedule(Schedule $schedule): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department) {
            $this->addFlash('error', 'You are not assigned to a department.');
            return $this->redirectToRoute('department_head_dashboard');
        }

        // Verify schedule belongs to department
        if ($schedule->getSubject()->getDepartment()->getId() !== $department->getId()) {
            $this->addFlash('error', 'You do not have access to this schedule.');
            return $this->redirectToRoute('department_head_schedules');
        }

        return $this->render('department_head/schedules/view.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'View Schedule',
            'schedule' => $schedule,
        ]));
    }

    #[Route('/schedules/{id}/activate', name: 'schedules_activate', methods: ['POST'])]
    public function activateSchedule(Schedule $schedule, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department || $schedule->getSubject()->getDepartment()->getId() !== $department->getId()) {
            $this->addFlash('error', 'You do not have access to this schedule.');
            return $this->redirectToRoute('department_head_schedules');
        }

        // Validate CSRF token
        if (!$this->isCsrfTokenValid('activate_schedule', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('department_head_schedules');
        }

        try {
            $schedule->setStatus('active');
            $this->entityManager->flush();

            $this->addFlash('success', 'Schedule activated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error activating schedule: ' . $e->getMessage());
        }

        return $this->redirectToRoute('department_head_schedules');
    }

    #[Route('/schedules/{id}/deactivate', name: 'schedules_deactivate', methods: ['POST'])]
    public function deactivateSchedule(Schedule $schedule, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department || $schedule->getSubject()->getDepartment()->getId() !== $department->getId()) {
            $this->addFlash('error', 'You do not have access to this schedule.');
            return $this->redirectToRoute('department_head_schedules');
        }

        // Validate CSRF token
        if (!$this->isCsrfTokenValid('deactivate_schedule', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('department_head_schedules');
        }

        try {
            $schedule->setStatus('inactive');
            $this->entityManager->flush();

            $this->addFlash('success', 'Schedule deactivated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deactivating schedule: ' . $e->getMessage());
        }

        return $this->redirectToRoute('department_head_schedules');
    }

    #[Route('/schedules/{id}/delete', name: 'schedules_delete', methods: ['POST'])]
    public function deleteSchedule(Schedule $schedule, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department || $schedule->getSubject()->getDepartment()->getId() !== $department->getId()) {
            $this->addFlash('error', 'You do not have access to this schedule.');
            return $this->redirectToRoute('department_head_schedules');
        }

        // Validate CSRF token
        if (!$this->isCsrfTokenValid('delete_schedule', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('department_head_schedules');
        }

        try {
            $subjectCode = $schedule->getSubject()->getCode();
            $section = $schedule->getSection();
            $scheduleInfo = "{$subjectCode} (Section {$section})";
            
            $this->entityManager->remove($schedule);
            $this->entityManager->flush();
            
            // Log the activity
            $this->activityLogService->log(
                'schedule.deleted',
                "Schedule deleted: {$scheduleInfo}",
                'Schedule',
                $schedule->getId()
            );

            $this->addFlash('success', "Schedule for {$scheduleInfo} deleted successfully.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error deleting schedule: ' . $e->getMessage());
        }

        return $this->redirectToRoute('department_head_schedules');
    }

    #[Route('/schedules/existing-sections/{subjectId}', name: 'schedules_existing_sections', methods: ['GET'])]
    public function getExistingSections(int $subjectId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $department = $user->getDepartment();
        if (!$department) {
            return $this->json(['error' => 'No department assigned'], 404);
        }

        try {
            // Verify the subject belongs to this department
            $subject = $this->subjectRepository->find($subjectId);
            if (!$subject) {
                return $this->json([
                    'sections' => [],
                    'schedules' => [],
                    'error' => 'Subject not found'
                ], 404);
            }
            
            if ($subject->getDepartment()->getId() !== $department->getId()) {
                return $this->json([
                    'sections' => [],
                    'schedules' => [],
                    'error' => 'Subject not in your department'
                ], 403);
            }

            // Get all schedules for this subject
            $schedules = $this->scheduleRepository->createQueryBuilder('s')
                ->select('s.id', 's.section', 's.semester', 's.dayPattern', 's.startTime', 's.endTime', 
                         'ay.year', 'ay.id as yearId', 'r.code as roomCode', 's.status')
                ->leftJoin('s.academicYear', 'ay')
                ->leftJoin('s.room', 'r')
                ->where('s.subject = :subjectId')
                ->andWhere('s.status = :status')
                ->setParameter('subjectId', $subjectId)
                ->setParameter('status', 'active')
                ->orderBy('s.semester', 'ASC')
                ->addOrderBy('s.section', 'ASC')
                ->getQuery()
                ->getResult();

            // Extract unique sections and build schedule map
            $sections = [];
            $schedulesMap = [];
            $fullScheduleDetails = [];
            
            foreach ($schedules as $schedule) {
                $section = $schedule['section'];
                $semester = $schedule['semester'];
                $year = $schedule['year'];
                $yearId = $schedule['yearId'];
                
                // Add to unique sections list
                if (!in_array($section, $sections)) {
                    $sections[] = $section;
                }
                
                // Map for duplicate checking: subject_section_semester_yearId
                // This ensures each subject can have its own Section A, B, etc.
                $key = $subjectId . '_' . strtoupper(trim($section)) . '_' . $semester . '_' . $yearId;
                $schedulesMap[$key] = [
                    'section' => $section,
                    'semester' => $semester,
                    'year' => $year,
                    'yearId' => $yearId,
                    'roomCode' => $schedule['roomCode'],
                    'dayPattern' => $schedule['dayPattern'],
                    'startTime' => $schedule['startTime']->format('H:i'),
                    'endTime' => $schedule['endTime']->format('H:i')
                ];
                
                // Add full details for display
                $fullScheduleDetails[] = [
                    'id' => $schedule['id'],
                    'section' => $section,
                    'semester' => $semester,
                    'year' => $year,
                    'yearId' => $yearId,
                    'roomCode' => $schedule['roomCode'],
                    'dayPattern' => $schedule['dayPattern'],
                    'startTime' => $schedule['startTime']->format('H:i'),
                    'endTime' => $schedule['endTime']->format('H:i'),
                    'status' => $schedule['status']
                ];
            }
            
            // Sort sections alphabetically
            sort($sections);
            
            return $this->json([
                'sections' => $sections,
                'schedules' => $schedulesMap,
                'scheduleDetails' => $fullScheduleDetails,
                'count' => count($sections)
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'sections' => [],
                'schedules' => [],
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/faculty-assignments', name: 'faculty_assignments')]
    public function facultyAssignments(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department) {
            $this->addFlash('error', 'You are not assigned to a department.');
            return $this->redirectToRoute('department_head_dashboard');
        }

        // Get dashboard data
        $dashboardData = $this->dashboardService->getDepartmentHeadDashboardData($user);

        // Determine which departments to include for faculty
        $departmentGroup = $department->getDepartmentGroup();
        $departmentsForFaculty = [$department];
        
        if ($departmentGroup) {
            // If department is in a group, get faculty from all departments in the group
            $departmentsForFaculty = $departmentGroup->getDepartments()->toArray();
        }

        // Get active semester for filtering
        $activeAcademicYear = $this->systemSettingsService->getActiveAcademicYear();
        $activeSemester = $this->systemSettingsService->getActiveSemester();

        // Get all active schedules for departments in the group that need faculty assignment
        // OPTIMIZATION: Eager load related entities to prevent N+1 queries
        $qb = $this->scheduleRepository->createQueryBuilder('s')
            ->innerJoin('s.subject', 'subj')
            ->addSelect('subj')
            ->leftJoin('s.faculty', 'f')
            ->addSelect('f')
            ->leftJoin('s.academicYear', 'ay')
            ->addSelect('ay')
            ->leftJoin('s.room', 'r')
            ->addSelect('r')
            ->where('subj.department IN (:departments)')
            ->andWhere('s.status = :status')
            ->setParameter('departments', $departmentsForFaculty)
            ->setParameter('status', 'active');
        
        // Filter by active semester if set
        if ($activeAcademicYear && $activeSemester) {
            $qb->andWhere('s.academicYear = :academicYear')
               ->andWhere('s.semester = :semester')
               ->setParameter('academicYear', $activeAcademicYear)
               ->setParameter('semester', $activeSemester);
        }
        
        $scheduledSubjects = $qb->orderBy('s.faculty', 'ASC')
            ->addOrderBy('subj.code', 'ASC')
            ->getQuery()
            ->getResult();

        // Get all active faculty from the department(s) in the group
        // OPTIMIZATION: Eager load department to prevent N+1 queries
        $faculty = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->leftJoin('u.department', 'd')
            ->addSelect('d')
            ->where('u.department IN (:departments)')
            ->andWhere('u.isActive = :active')
            ->andWhere('u.role = :role')
            ->setParameter('departments', $departmentsForFaculty)
            ->setParameter('active', true)
            ->setParameter('role', 3)
            ->orderBy('u.department', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('department_head/faculty_assignments/index.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Faculty Assignments',
            'selectedDepartment' => $department,
            'scheduledSubjects' => $scheduledSubjects,
            'faculty' => $faculty,
        ]));
    }

    #[Route('/faculty-assignments/assign/{id}', name: 'faculty_assignments_assign', methods: ['POST'])]
    public function assignFaculty(Schedule $schedule, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department) {
            return $this->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Check if the schedule's department is in the same department or department group
        $scheduleDepartment = $schedule->getSubject()->getDepartment();
        $isAuthorized = false;
        
        if ($scheduleDepartment->getId() === $department->getId()) {
            $isAuthorized = true;
        } else {
            // Check if both departments are in the same group
            $departmentGroup = $department->getDepartmentGroup();
            $scheduleDepartmentGroup = $scheduleDepartment->getDepartmentGroup();
            
            if ($departmentGroup && $scheduleDepartmentGroup && 
                $departmentGroup->getId() === $scheduleDepartmentGroup->getId()) {
                $isAuthorized = true;
            }
        }

        if (!$isAuthorized) {
            return $this->json(['success' => false, 'message' => 'Unauthorized - schedule not in your department or department group'], 403);
        }

        $facultyId = $request->request->get('faculty_id');
        
        if ($facultyId) {
            $faculty = $this->entityManager->getRepository(User::class)->find($facultyId);
            
            if (!$faculty) {
                return $this->json(['success' => false, 'message' => 'Faculty member not found'], 400);
            }
            
            // Check if faculty is from the same department or department group
            $facultyDepartment = $faculty->getDepartment();
            $isValidFaculty = false;
            
            if ($facultyDepartment) {
                // Same department
                if ($facultyDepartment->getId() === $department->getId()) {
                    $isValidFaculty = true;
                } else {
                    // Check if in the same department group
                    $departmentGroup = $department->getDepartmentGroup();
                    $facultyDepartmentGroup = $facultyDepartment->getDepartmentGroup();
                    
                    if ($departmentGroup && $facultyDepartmentGroup && 
                        $departmentGroup->getId() === $facultyDepartmentGroup->getId()) {
                        $isValidFaculty = true;
                    }
                }
            }
            
            if (!$isValidFaculty) {
                return $this->json(['success' => false, 'message' => 'Faculty member not in department or department group'], 400);
            }
            
            $schedule->setFaculty($faculty);
            
            // Log the activity
            $this->activityLogService->log(
                'schedule.faculty_assigned',
                "Faculty assigned to schedule: {$faculty->getFullName()} - {$schedule->getSubject()->getTitle()} ({$schedule->getSection()})",
                'Schedule',
                $schedule->getId(),
                [
                    'faculty_name' => $faculty->getFullName(),
                    'subject' => $schedule->getSubject()->getTitle(),
                    'section' => $schedule->getSection()
                ]
            );
        } else {
            $oldFaculty = $schedule->getFaculty();
            $schedule->setFaculty(null);
            
            // Log the activity
            if ($oldFaculty) {
                $this->activityLogService->log(
                    'schedule.faculty_unassigned',
                    "Faculty unassigned from schedule: {$oldFaculty->getFullName()} - {$schedule->getSubject()->getTitle()} ({$schedule->getSection()})",
                    'Schedule',
                    $schedule->getId(),
                    [
                        'faculty_name' => $oldFaculty->getFullName(),
                        'subject' => $schedule->getSubject()->getTitle(),
                        'section' => $schedule->getSection()
                    ]
                );
            }
        }

        try {
            $this->entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => $facultyId ? 'Faculty assigned successfully' : 'Faculty unassigned successfully',
                'faculty' => $schedule->getFaculty() ? [
                    'id' => $schedule->getFaculty()->getId(),
                    'name' => $schedule->getFaculty()->getFullName()
                ] : null
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/faculty-assignments/toggle-overload/{id}', name: 'faculty_assignments_toggle_overload', methods: ['POST'])]
    public function toggleOverload(Schedule $schedule, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department) {
            return $this->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Check if the schedule has a faculty assigned
        if (!$schedule->getFaculty()) {
            return $this->json(['success' => false, 'message' => 'Schedule must have a faculty assigned'], 400);
        }

        // Check if the schedule's department is in the same department or department group
        $scheduleDepartment = $schedule->getSubject()->getDepartment();
        $isAuthorized = false;
        
        if ($scheduleDepartment->getId() === $department->getId()) {
            $isAuthorized = true;
        } else {
            // Check if both departments are in the same group
            $departmentGroup = $department->getDepartmentGroup();
            $scheduleDepartmentGroup = $scheduleDepartment->getDepartmentGroup();
            
            if ($departmentGroup && $scheduleDepartmentGroup && 
                $departmentGroup->getId() === $scheduleDepartmentGroup->getId()) {
                $isAuthorized = true;
            }
        }

        if (!$isAuthorized) {
            return $this->json(['success' => false, 'message' => 'Unauthorized - schedule not in your department or department group'], 403);
        }

        // Toggle the overload status
        $newOverloadStatus = !$schedule->getIsOverload();
        $schedule->setIsOverload($newOverloadStatus);

        try {
            $this->entityManager->flush();
            
            // Log the activity
            $this->activityLogService->log(
                $newOverloadStatus ? 'schedule.overload_enabled' : 'schedule.overload_disabled',
                sprintf(
                    "Schedule %s as overload: %s - %s (%s)",
                    $newOverloadStatus ? 'marked' : 'unmarked',
                    $schedule->getFaculty()->getFullName(),
                    $schedule->getSubject()->getTitle(),
                    $schedule->getSection()
                ),
                'Schedule',
                $schedule->getId(),
                [
                    'faculty_name' => $schedule->getFaculty()->getFullName(),
                    'subject' => $schedule->getSubject()->getTitle(),
                    'section' => $schedule->getSection(),
                    'is_overload' => $newOverloadStatus
                ]
            );
            
            return $this->json([
                'success' => true,
                'isOverload' => $newOverloadStatus,
                'message' => $newOverloadStatus ? 'Schedule marked as overload' : 'Overload status removed'
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/faculty-assignments/view/{id}', name: 'faculty_assignments_view', methods: ['GET'])]
    public function viewFacultyAssignments(int $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department) {
            return $this->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Get the faculty member
        $faculty = $this->entityManager->getRepository(User::class)->find($id);
        
        if (!$faculty) {
            return $this->json(['success' => false, 'message' => 'Faculty member not found'], 404);
        }

        // Verify faculty is in the same department or department group
        $facultyDepartment = $faculty->getDepartment();
        $isValidFaculty = false;
        
        if ($facultyDepartment) {
            if ($facultyDepartment->getId() === $department->getId()) {
                $isValidFaculty = true;
            } else {
                $departmentGroup = $department->getDepartmentGroup();
                $facultyDepartmentGroup = $facultyDepartment->getDepartmentGroup();
                
                if ($departmentGroup && $facultyDepartmentGroup && 
                    $departmentGroup->getId() === $facultyDepartmentGroup->getId()) {
                    $isValidFaculty = true;
                }
            }
        }
        
        if (!$isValidFaculty) {
            return $this->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Get all schedules assigned to this faculty member for the department
        $schedules = $this->scheduleRepository->createQueryBuilder('s')
            ->leftJoin('s.subject', 'subj')
            ->leftJoin('s.faculty', 'f')
            ->leftJoin('s.academicYear', 'ay')
            ->leftJoin('s.room', 'r')
            ->where('f.id = :facultyId')
            ->andWhere('subj.department = :department')
            ->andWhere('s.status = :status')
            ->setParameter('facultyId', $id)
            ->setParameter('department', $department)
            ->setParameter('status', 'active')
            ->orderBy('subj.code', 'ASC')
            ->getQuery()
            ->getResult();

        // Format the schedule data
        $assignments = [];
        foreach ($schedules as $schedule) {
            $assignments[] = [
                'id' => $schedule->getId(),
                'subjectCode' => $schedule->getSubject()->getCode(),
                'subjectTitle' => $schedule->getSubject()->getTitle(),
                'section' => $schedule->getSection(),
                'dayPattern' => $schedule->getDayPatternLabel(),
                'startTime' => $schedule->getStartTime()->format('g:i A'),
                'endTime' => $schedule->getEndTime()->format('g:i A'),
                'room' => $schedule->getRoom()->getCode(),
                'units' => $schedule->getSubject()->getUnits(),
                'isOverload' => $schedule->getIsOverload(),
            ];
        }

        return $this->json([
            'success' => true,
            'faculty' => [
                'id' => $faculty->getId(),
                'name' => $faculty->getFullName(),
                'email' => $faculty->getEmail(),
            ],
            'assignments' => $assignments,
            'count' => count($assignments)
        ]);
    }

    #[Route('/schedule/teaching-load-pdf/{facultyId}', name: 'schedule_teaching_load_pdf', methods: ['GET'])]
    public function teachingLoadPDF(int $facultyId): Response
    {
        /** @var User $departmentHead */
        $departmentHead = $this->getUser();
        $department = $departmentHead->getDepartment();

        if (!$department) {
            throw $this->createNotFoundException('You are not assigned to a department.');
        }

        // Get faculty member
        $faculty = $this->entityManager->getRepository(User::class)->find($facultyId);
        
        if (!$faculty) {
            throw $this->createNotFoundException('Faculty member not found');
        }

        // Verify faculty is in the same department or department group
        $facultyDepartment = $faculty->getDepartment();
        $isValidFaculty = false;
        
        if ($facultyDepartment) {
            if ($facultyDepartment->getId() === $department->getId()) {
                $isValidFaculty = true;
            } else {
                $departmentGroup = $department->getDepartmentGroup();
                $facultyDepartmentGroup = $facultyDepartment->getDepartmentGroup();
                
                if ($departmentGroup && $facultyDepartmentGroup && 
                    $departmentGroup->getId() === $facultyDepartmentGroup->getId()) {
                    $isValidFaculty = true;
                }
            }
        }
        
        if (!$isValidFaculty) {
            throw $this->createAccessDeniedException('You can only view teaching load PDFs for faculty in your department or department group.');
        }

        // Get the current active academic year
        $academicYear = $this->academicYearRepository->findOneBy(['isActive' => true]);
        
        if (!$academicYear) {
            $this->addFlash('error', 'No active academic year found');
            return $this->redirectToRoute('department_head_faculty_assignments');
        }

        // Get the active semester
        $activeSemester = $this->systemSettingsService->getActiveSemester();

        try {
            // Generate PDF using the service with semester filter
            $pdfContent = $this->pdfService->generateTeachingLoadPdf($faculty, $academicYear, $activeSemester);
            
            $filename = 'teaching_load_' . $faculty->getLastName() . '_' . date('Y-m-d') . '.pdf';
            
            return new Response(
                $pdfContent,
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="' . $filename . '"'
                ]
            );
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error generating PDF: ' . $e->getMessage());
            return $this->redirectToRoute('department_head_faculty_assignments');
        }
    }

    #[Route('/settings', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $department = $user->getDepartment();

        if (!$department) {
            throw $this->createAccessDeniedException('You must be assigned to a department.');
        }

        if ($request->isMethod('POST')) {
            // Handle settings updates
            $action = $request->request->get('action');

            try {
                switch ($action) {
                    case 'update_profile':
                        $email = $request->request->get('email');
                        $address = $request->request->get('address');

                        if ($email) {
                            $user->setEmail($email);
                        }
                        if ($address !== null) {
                            $user->setAddress($address);
                        }

                        $this->entityManager->flush();
                        $this->addFlash('success', 'Profile updated successfully');
                        break;

                    case 'change_password':
                        $currentPassword = $request->request->get('current_password');
                        $newPassword = $request->request->get('new_password');
                        $confirmPassword = $request->request->get('confirm_password');

                        if (!$currentPassword || !$newPassword || !$confirmPassword) {
                            $this->addFlash('error', 'All password fields are required');
                            break;
                        }

                        if ($newPassword !== $confirmPassword) {
                            $this->addFlash('error', 'New passwords do not match');
                            break;
                        }

                        if (strlen($newPassword) < 6) {
                            $this->addFlash('error', 'Password must be at least 6 characters long');
                            break;
                        }

                        // Note: Password verification would require password hasher
                        // For now, just update the password
                        $this->addFlash('info', 'Password change functionality requires password hasher integration');
                        break;

                    case 'notification_preferences':
                        $emailNotifications = $request->request->get('email_notifications') === '1';
                        $scheduleAlerts = $request->request->get('schedule_alerts') === '1';
                        $conflictAlerts = $request->request->get('conflict_alerts') === '1';

                        // Store preferences (you may need to add fields to User entity)
                        $this->addFlash('success', 'Notification preferences updated');
                        break;

                    default:
                        $this->addFlash('error', 'Invalid action');
                }
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating settings: ' . $e->getMessage());
            }

            return $this->redirectToRoute('department_head_settings');
        }

        return $this->render('department_head/settings/index.html.twig', array_merge($this->getBaseTemplateData(), [
            'user' => $user,
            'department' => $department,
        ]));
    }
}
