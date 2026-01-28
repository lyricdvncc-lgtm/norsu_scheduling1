<?php

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\CurriculumSubject;
use App\Repository\ScheduleRepository;
use App\Repository\RoomRepository;
use App\Repository\SubjectRepository;
use App\Repository\AcademicYearRepository;
use App\Service\SubjectService;
use App\Service\ActivityLogService;
use App\Service\SystemSettingsService;
use App\Service\DashboardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/schedule')]
class ScheduleController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private ScheduleRepository $scheduleRepository;
    private RoomRepository $roomRepository;
    private SubjectRepository $subjectRepository;
    private AcademicYearRepository $academicYearRepository;
    private SubjectService $subjectService;
    private ActivityLogService $activityLogService;
    private SystemSettingsService $systemSettingsService;
    private DashboardService $dashboardService;

    public function __construct(
        EntityManagerInterface $entityManager,
        ScheduleRepository $scheduleRepository,
        RoomRepository $roomRepository,
        SubjectRepository $subjectRepository,
        AcademicYearRepository $academicYearRepository,
        SubjectService $subjectService,
        ActivityLogService $activityLogService,
        SystemSettingsService $systemSettingsService,
        DashboardService $dashboardService
    ) {
        $this->entityManager = $entityManager;
        $this->scheduleRepository = $scheduleRepository;
        $this->roomRepository = $roomRepository;
        $this->subjectRepository = $subjectRepository;
        $this->academicYearRepository = $academicYearRepository;
        $this->subjectService = $subjectService;
        $this->activityLogService = $activityLogService;
        $this->systemSettingsService = $systemSettingsService;
        $this->dashboardService = $dashboardService;
    }

    #[Route('/', name: 'app_schedule_index', methods: ['GET'])]
    public function index(Request $request, \App\Service\ScheduleConflictDetector $conflictDetector): Response
    {
        $user = $this->getUser();
        $session = $request->getSession();
        
        // Get active academic year and semester
        $activeYear = $this->systemSettingsService->getActiveAcademicYear();
        $activeSemester = $this->systemSettingsService->getActiveSemester();
        
        // Get department from query parameter or session
        $departmentId = $request->query->get('department');
        $selectedDepartment = null;
        
        if ($departmentId) {
            // Store in session for persistence
            $session->set('selected_department_id', $departmentId);
            $selectedDepartment = $this->entityManager->getRepository(\App\Entity\Department::class)->find($departmentId);
        } elseif ($session->has('selected_department_id')) {
            // Retrieve from session if available
            $departmentId = $session->get('selected_department_id');
            $selectedDepartment = $this->entityManager->getRepository(\App\Entity\Department::class)->find($departmentId);
        }
        
        // Check if a room filter is requested
        $roomFilter = $request->query->get('room');
        
        // Get schedules - if room filter is specified, ignore department filter
        // Otherwise, filter by department if one is selected
        // Always filter by active academic year and semester
        if ($roomFilter) {
            // When filtering by room, show ALL schedules in that room (cross-department)
            $schedules = $this->scheduleRepository->findByRoom(
                $roomFilter,
                $activeYear,
                $activeSemester
            );
        } elseif ($selectedDepartment) {
            $schedules = $this->scheduleRepository->findByDepartment(
                $selectedDepartment->getId(),
                $activeYear,
                $activeSemester
            );
        } else {
            $schedules = $this->scheduleRepository->findAllWithRelations(
                $activeYear,
                $activeSemester
            );
        }

        // Detect conflicts for each schedule
        foreach ($schedules as $schedule) {
            // detectConflicts checks room-time conflicts and section conflicts
            // Pass true to exclude the schedule itself from conflict detection
            $conflicts = $conflictDetector->detectConflicts($schedule, true);
            
            // Check duplicate subject-section (also exclude self)
            $duplicateConflicts = $conflictDetector->checkDuplicateSubjectSection($schedule, true);
            
            // Mark as conflicted if any hard conflicts exist
            $hasConflict = !empty($conflicts) || !empty($duplicateConflicts);
            $schedule->setIsConflicted($hasConflict);
        }

        // Get filter options
        $departments = $this->entityManager->getRepository(\App\Entity\Department::class)->findAll();
        
        // Filter rooms by selected department (if any)
        if ($selectedDepartment) {
            $rooms = $this->roomRepository->findAccessibleByDepartment($selectedDepartment);
        } else {
            $rooms = $this->roomRepository->findAll();
        }
        
        // Get the selected room info if filtering by room
        $selectedRoom = null;
        if ($roomFilter) {
            $selectedRoom = $this->entityManager->getRepository(\App\Entity\Room::class)->find($roomFilter);
        }
        
        // Group schedules by subject for block display
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

        return $this->render('admin/schedule/index.html.twig', [
            'schedules' => $schedules,
            'groupedSchedules' => $groupedSchedules,
            'departments' => $departments,
            'rooms' => $rooms,
            'selectedDepartment' => $selectedDepartment,
            'selectedRoom' => $selectedRoom,
            'activeSemesterDisplay' => $this->systemSettingsService->getActiveSemesterDisplay(),
            'hasActiveSemester' => $this->systemSettingsService->hasActiveSemester(),
            'dashboard_data' => $this->dashboardService->getAdminDashboardData(),
        ]);
    }

    #[Route('/select-department', name: 'app_schedule_select_department', methods: ['GET'])]
    public function selectDepartment(): Response
    {
        $departments = $this->entityManager->getRepository(\App\Entity\Department::class)->findAll();

        return $this->render('admin/schedule/select_department.html.twig', [
            'departments' => $departments,
            'dashboard_data' => $this->dashboardService->getAdminDashboardData(),
        ]);
    }

    #[Route('/new', name: 'app_schedule_new', methods: ['GET', 'POST'])]
    public function new(Request $request, \App\Service\ScheduleConflictDetector $conflictDetector): Response
    {
        // Get department filter from query parameter or session
        $session = $request->getSession();
        $departmentId = $request->query->get('department') ?? $session->get('selected_department_id');
        
        // If no department is selected, redirect to index which will show the modal
        if (!$departmentId && $request->isMethod('GET')) {
            return $this->redirectToRoute('app_schedule_index');
        }

        // Get selected department
        $selectedDepartment = null;
        if ($departmentId) {
            $selectedDepartment = $this->entityManager->getRepository(\App\Entity\Department::class)->find($departmentId);
            
            // If department not found, log and redirect
            if (!$selectedDepartment) {
                $this->addFlash('error', 'Department not found. Please select a valid department.');
                return $this->redirectToRoute('app_schedule_index');
            }
        }

        // Get active semester and academic year
        $activeAcademicYear = $this->systemSettingsService->getActiveAcademicYear();
        $activeSemester = $this->systemSettingsService->getActiveSemester();

        // Get subjects for the selected department
        $subjects = [];
        if ($selectedDepartment) {
            // Get ALL subjects with their semester information from curriculum terms
            // This allows the frontend filter to show/hide subjects by semester
            $subjects = $this->entityManager->createQueryBuilder()
                ->select('DISTINCT s, ct.semester as HIDDEN semester')
                ->from('App\Entity\Subject', 's')
                ->leftJoin('App\Entity\CurriculumSubject', 'cs', 'WITH', 'cs.subject = s')
                ->leftJoin('cs.curriculumTerm', 'ct')
                ->leftJoin('cs.curriculum', 'c')
                ->where('s.deletedAt IS NULL')
                ->andWhere('s.isActive = :active')
                ->andWhere(
                    's.department = :dept OR c.department = :dept'
                )
                ->setParameter('dept', $selectedDepartment)
                ->setParameter('active', true)
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
        }
        
        // Filter rooms by selected department
        if ($selectedDepartment) {
            $rooms = $this->roomRepository->findAccessibleByDepartment($selectedDepartment);
        } else {
            $rooms = $this->roomRepository->findAll();
        }
        
        $academicYears = $this->academicYearRepository->findBy(['isActive' => true], ['year' => 'DESC']);

        if ($request->isMethod('POST')) {
            try {
                // Check if we're creating multiple sections
                $sectionsData = $request->request->all('sections');
                $subjectId = $request->request->get('subject');
                $academicYearId = $request->request->get('academic_year');
                $semester = $request->request->get('semester');
                
                // Validation: Check required common fields
                $errors = [];
                
                if (empty($subjectId)) {
                    $errors[] = 'Subject is required.';
                }
                if (empty($academicYearId)) {
                    $errors[] = 'Academic Year is required.';
                }
                if (empty($semester)) {
                    $errors[] = 'Semester is required.';
                }
                if (empty($sectionsData) || !is_array($sectionsData)) {
                    $errors[] = 'At least one section is required.';
                }

                if (!empty($errors)) {
                    foreach ($errors as $error) {
                        $this->addFlash('error', $error);
                    }
                    return $this->render('admin/schedule/new_v2.html.twig', [
                        'subjects' => $subjects,
                        'rooms' => $rooms,
                        'academicYears' => $academicYears,
                        'selectedDepartment' => $selectedDepartment,
                        'departmentId' => $departmentId,
                        'semesterFilter' => $activeSemester,
                        'activeAcademicYear' => $activeAcademicYear,
                        'activeSemester' => $activeSemester,
                        'dashboard_data' => $this->dashboardService->getAdminDashboardData(),
                    ]);
                }

                // Find common entities
                $subject = $this->subjectRepository->find($subjectId);
                $academicYear = $this->academicYearRepository->find($academicYearId);

                if (!$subject) {
                    throw new \Exception('Invalid subject selected.');
                }
                if (!$academicYear) {
                    throw new \Exception('Invalid academic year selected.');
                }

                // Process all sections
                $createdSchedules = [];
                $allConflicts = [];
                
                foreach ($sectionsData as $index => $sectionData) {
                    $schedule = new Schedule();
                    
                    // Validate section data
                    $sectionName = trim($sectionData['section'] ?? '');
                    $roomId = $sectionData['room'] ?? null;
                    $dayPattern = $sectionData['day_pattern'] ?? '';
                    $startTime = $sectionData['start_time'] ?? '';
                    $endTime = $sectionData['end_time'] ?? '';
                    $enrolledStudents = (int) ($sectionData['enrolled_students'] ?? 0);
                    
                    if (empty($sectionName) || empty($roomId) || empty($dayPattern) || empty($startTime) || empty($endTime)) {
                        $errors[] = "Section " . ($index + 1) . ": All fields are required.";
                        continue;
                    }
                    
                    $room = $this->roomRepository->find($roomId);
                    if (!$room) {
                        $errors[] = "Section {$sectionName}: Invalid room selected.";
                        continue;
                    }

                    // Set schedule properties
                    $schedule->setSubject($subject);
                    $schedule->setAcademicYear($academicYear);
                    $schedule->setSemester($semester);
                    $schedule->setRoom($room);
                    $schedule->setSection($sectionName);
                    $schedule->setDayPattern($dayPattern);
                    $schedule->setEnrolledStudents($enrolledStudents);
                    $schedule->setNotes('');
                    $schedule->setStatus('active');

                    // Parse and set time
                    try {
                        $schedule->setStartTime(new \DateTime($startTime));
                        $schedule->setEndTime(new \DateTime($endTime));
                    } catch (\Exception $e) {
                        $errors[] = "Section {$sectionName}: Invalid time format.";
                        continue;
                    }

                    // Validate time range
                    $timeErrors = $conflictDetector->validateTimeRange($schedule);
                    if (!empty($timeErrors)) {
                        foreach ($timeErrors as $error) {
                            $errors[] = "Section {$sectionName}: " . $error;
                        }
                        continue;
                    }

                    // Check for conflicts
                    $conflicts = $conflictDetector->detectConflicts($schedule);
                    $duplicateConflicts = $conflictDetector->checkDuplicateSubjectSection($schedule);
                    
                    // Combine hard conflicts
                    $hardConflicts = array_filter($conflicts, function($conflict) {
                        return in_array($conflict['type'], [
                            'room_time_conflict',
                            'section_conflict',
                            'block_sectioning_conflict'
                        ]);
                    });
                    $hardConflicts = array_merge($hardConflicts, $duplicateConflicts);
                    
                    if (!empty($hardConflicts)) {
                        foreach ($hardConflicts as $conflict) {
                            $allConflicts[] = "Section {$sectionName}: " . $conflict['message'];
                        }
                        continue;
                    }
                    
                    // Warn for soft conflicts
                    $capacityErrors = $conflictDetector->validateRoomCapacity($schedule);
                    if (!empty($capacityErrors)) {
                        foreach ($capacityErrors as $error) {
                            $this->addFlash('warning', "⚠️ Section {$sectionName}: " . $error);
                        }
                    }

                    $schedule->setIsConflicted(false);
                    $createdSchedules[] = $schedule;
                }

                // If there are any errors or conflicts, don't save anything
                if (!empty($errors) || !empty($allConflicts)) {
                    foreach ($errors as $error) {
                        $this->addFlash('error', $error);
                    }
                    foreach ($allConflicts as $conflict) {
                        $this->addFlash('error', '🚫 ' . $conflict);
                    }
                    $this->addFlash('error', sprintf(
                        'Cannot create schedules: %d error(s) detected. Please fix all issues.',
                        count($errors) + count($allConflicts)
                    ));
                    
                    return $this->render('admin/schedule/new_v2.html.twig', [
                        'subjects' => $subjects,
                        'rooms' => $rooms,
                        'academicYears' => $academicYears,
                        'selectedDepartment' => $selectedDepartment,
                        'departmentId' => $departmentId,
                        'semesterFilter' => $activeSemester,
                        'activeAcademicYear' => $activeAcademicYear,
                        'activeSemester' => $activeSemester,
                        'dashboard_data' => $this->dashboardService->getAdminDashboardData(),
                    ]);
                }

                // Save all schedules
                foreach ($createdSchedules as $schedule) {
                    $this->entityManager->persist($schedule);
                }
                $this->entityManager->flush();

                // Log activities
                foreach ($createdSchedules as $schedule) {
                    $scheduleInfo = sprintf('%s - %s (%s)',
                        $schedule->getSubject()->getTitle(),
                        $schedule->getSection(),
                        $schedule->getDayPattern()
                    );
                    $this->activityLogService->logScheduleActivity(
                        'schedule.created',
                        $schedule->getId(),
                        $scheduleInfo,
                        [
                            'subject' => $schedule->getSubject()->getTitle(),
                            'section' => $schedule->getSection(),
                            'room' => $schedule->getRoom()->getName(),
                            'day_pattern' => $schedule->getDayPattern()
                        ]
                    );
                }

                $successMsg = count($createdSchedules) === 1 
                    ? '✅ Schedule created successfully!' 
                    : sprintf('✅ Successfully created %d sections!', count($createdSchedules));
                $this->addFlash('success', $successMsg);
                
                return $this->redirectToRoute('app_schedule_index', ['department' => $departmentId]);

            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating schedule: ' . $e->getMessage());
            }
        }

        return $this->render('admin/schedule/new_v2.html.twig', [
            'subjects' => $subjects,
            'rooms' => $rooms,
            'academicYears' => $academicYears,
            'selectedDepartment' => $selectedDepartment,
            'departmentId' => $departmentId,
            'semesterFilter' => $activeSemester,
            'activeAcademicYear' => $activeAcademicYear,
            'activeSemester' => $activeSemester,
            'dashboard_data' => $this->dashboardService->getAdminDashboardData(),
        ]);
    }

    #[Route('/{id}', name: 'app_schedule_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $schedule = $this->scheduleRepository->find($id);
        
        if (!$schedule) {
            $this->addFlash('error', 'Schedule not found.');
            return $this->redirectToRoute('app_schedule_index');
        }
        
        // Redirect to edit page since we don't have a separate show view
        return $this->redirectToRoute('app_schedule_edit', ['id' => $schedule->getId()]);
    }

    #[Route('/{id}/edit', name: 'app_schedule_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Schedule $schedule, \App\Service\ScheduleConflictDetector $conflictDetector): Response
    {
        // Get the department from the schedule's subject
        $scheduleDepartment = $schedule->getSubject() ? $schedule->getSubject()->getDepartment() : null;
        
        // Get all subjects and rooms filtered by department
        $subjects = $this->subjectRepository->findAll();
        
        // Filter rooms by schedule's department
        if ($scheduleDepartment) {
            $rooms = $this->roomRepository->findAccessibleByDepartment($scheduleDepartment);
        } else {
            $rooms = $this->roomRepository->findAll();
        }
        
        $academicYears = $this->academicYearRepository->findBy(['isActive' => true], ['year' => 'DESC']);

        if ($request->isMethod('POST')) {
            $subjectId = $request->request->get('subject');
            $academicYearId = $request->request->get('academic_year');
            $semester = $request->request->get('semester');
            $roomId = $request->request->get('room');
            $section = $request->request->get('section');
            $dayPattern = $request->request->get('day_pattern');
            $startTime = $request->request->get('start_time');
            $endTime = $request->request->get('end_time');
            $notes = $request->request->get('notes');
            $enrolledStudents = $request->request->get('enrolled_students');

            $subject = $this->subjectRepository->find($subjectId);
            $academicYear = $this->academicYearRepository->find($academicYearId);
            $room = $this->roomRepository->find($roomId);

            if ($subject && $academicYear && $room && $semester && $dayPattern && $startTime && $endTime) {
                // Store original values for rollback if needed
                $originalSubject = $schedule->getSubject();
                $originalAcademicYear = $schedule->getAcademicYear();
                $originalSemester = $schedule->getSemester();
                $originalRoom = $schedule->getRoom();
                $originalSection = $schedule->getSection();
                $originalDayPattern = $schedule->getDayPattern();
                $originalStartTime = $schedule->getStartTime();
                $originalEndTime = $schedule->getEndTime();
                
                // Apply new values temporarily for conflict checking
                $schedule->setSubject($subject);
                $schedule->setAcademicYear($academicYear);
                $schedule->setSemester($semester);
                $schedule->setRoom($room);
                $schedule->setSection($section);
                $schedule->setDayPattern($dayPattern);
                $schedule->setStartTime(new \DateTime($startTime));
                $schedule->setEndTime(new \DateTime($endTime));
                
                // Check for conflicts (excluding self)
                $conflicts = $conflictDetector->detectConflicts($schedule, true);
                $duplicateConflicts = $conflictDetector->checkDuplicateSubjectSection($schedule, true);
                
                // Combine hard conflicts
                $hardConflicts = array_filter($conflicts, function($conflict) {
                    return in_array($conflict['type'], [
                        'room_time_conflict',
                        'section_conflict',
                        'block_sectioning_conflict'
                    ]);
                });
                $hardConflicts = array_merge($hardConflicts, $duplicateConflicts);
                
                if (!empty($hardConflicts)) {
                    // Rollback changes
                    $schedule->setSubject($originalSubject);
                    $schedule->setAcademicYear($originalAcademicYear);
                    $schedule->setSemester($originalSemester);
                    $schedule->setRoom($originalRoom);
                    $schedule->setSection($originalSection);
                    $schedule->setDayPattern($originalDayPattern);
                    $schedule->setStartTime($originalStartTime);
                    $schedule->setEndTime($originalEndTime);
                    
                    // Show conflict errors
                    foreach ($hardConflicts as $conflict) {
                        $this->addFlash('error', '🚫 ' . $conflict['message']);
                    }
                    $this->addFlash('error', sprintf(
                        'Cannot update schedule: %d conflict(s) detected. Please resolve all conflicts.',
                        count($hardConflicts)
                    ));
                } else {
                    // No conflicts, proceed with update
                    $schedule->setNotes($notes);
                    $schedule->setEnrolledStudents($enrolledStudents ? (int)$enrolledStudents : null);
                    $schedule->setUpdatedAt(new \DateTime());

                    $this->entityManager->flush();

                    // Log the activity
                    $scheduleInfo = sprintf('%s - %s (%s)',
                        $schedule->getSubject()->getTitle(),
                        $schedule->getSection(),
                        $schedule->getDayPattern()
                    );
                    $this->activityLogService->logScheduleActivity(
                        'schedule.updated',
                        $schedule->getId(),
                        $scheduleInfo,
                        [
                            'subject' => $schedule->getSubject()->getTitle(),
                            'section' => $schedule->getSection()
                        ]
                    );

                    $this->addFlash('success', 'Schedule updated successfully!');
                    
                    // Redirect back to schedule list with department filter preserved
                    $departmentId = $scheduleDepartment ? $scheduleDepartment->getId() : null;
                    if ($departmentId) {
                        return $this->redirectToRoute('app_schedule_index', ['department' => $departmentId]);
                    }
                    return $this->redirectToRoute('app_schedule_index');
                }
            } else {
                $this->addFlash('error', 'Please fill in all required fields.');
            }
        }

        return $this->render('admin/schedule/edit.html.twig', [
            'schedule' => $schedule,
            'subjects' => $subjects,
            'rooms' => $rooms,
            'academicYears' => $academicYears,
            'dashboard_data' => $this->dashboardService->getAdminDashboardData(),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_schedule_delete', methods: ['POST'])]
    public function delete(Request $request, Schedule $schedule): Response
    {
        // Get department before deleting
        $scheduleDepartment = $schedule->getSubject() ? $schedule->getSubject()->getDepartment() : null;
        $departmentId = $scheduleDepartment ? $scheduleDepartment->getId() : null;
        
        if ($this->isCsrfTokenValid('delete'.$schedule->getId(), $request->request->get('_token'))) {
            // Log the activity before deletion
            $scheduleInfo = sprintf('%s - %s (%s)',
                $schedule->getSubject()->getTitle(),
                $schedule->getSection(),
                $schedule->getDayPattern()
            );
            $scheduleId = $schedule->getId();
            
            $this->entityManager->remove($schedule);
            $this->entityManager->flush();
            
            $this->activityLogService->log(
                'schedule.deleted',
                "Schedule deleted: {$scheduleInfo}",
                'Schedule',
                $scheduleId
            );
            
            $this->addFlash('success', 'Schedule deleted successfully!');
        }

        // Redirect back to schedule list with department filter preserved
        if ($departmentId) {
            return $this->redirectToRoute('app_schedule_index', ['department' => $departmentId]);
        }
        return $this->redirectToRoute('app_schedule_index');
    }

    #[Route('/clear-semester-filter', name: 'admin_subjects_clear_semester_filter', methods: ['GET'])]
    public function clearSemesterFilter(Request $request): Response
    {
        $session = $request->getSession();
        $session->remove('semester_filter');
        
        $this->addFlash('success', 'Semester filter cleared. Showing all subjects.');
        
        // Preserve department filter when redirecting back
        $departmentId = $session->get('selected_department_id');
        if ($departmentId) {
            return $this->redirectToRoute('app_schedule_new', ['department' => $departmentId]);
        }
        
        return $this->redirectToRoute('app_schedule_new');
    }

    #[Route('/api/colleges', name: 'app_schedule_api_colleges', methods: ['GET'])]
    public function getColleges(): Response
    {
        $colleges = $this->entityManager->getRepository(\App\Entity\College::class)->findAll();
        
        $data = array_map(function($college) {
            return [
                'id' => $college->getId(),
                'code' => $college->getCode(),
                'name' => $college->getName(),
            ];
        }, $colleges);

        return $this->json($data);
    }

    #[Route('/api/departments', name: 'app_schedule_api_departments', methods: ['GET'])]
    public function getDepartments(): Response
    {
        $departments = $this->entityManager->getRepository(\App\Entity\Department::class)->findAll();
        
        $data = array_map(function($dept) {
            return [
                'id' => $dept->getId(),
                'name' => $dept->getName(),
                'college' => $dept->getCollege() ? $dept->getCollege()->getName() : null,
                'collegeId' => $dept->getCollege() ? $dept->getCollege()->getId() : null,
            ];
        }, $departments);

        return $this->json($data);
    }

    #[Route('/check-conflict', name: 'app_schedule_check_conflict', methods: ['POST'])]
    public function checkConflict(Request $request, \App\Service\ScheduleConflictDetector $conflictDetector): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Create a temporary schedule object for conflict checking
        $schedule = new Schedule();
        $isEditingExisting = false;
        
        // If editing an existing schedule, set its ID to exclude from conflict checks
        if (isset($data['schedule_id']) && $data['schedule_id']) {
            $existingSchedule = $this->scheduleRepository->find($data['schedule_id']);
            if ($existingSchedule) {
                $schedule = $existingSchedule; // Use existing schedule so it excludes itself
                $isEditingExisting = true;
            }
        }
        
        try {
            // Set basic properties
            $subject = $this->subjectRepository->find($data['subject']);
            $room = $this->roomRepository->find($data['room']);
            $academicYear = $this->academicYearRepository->find($data['academic_year']);
            
            if (!$subject || !$room || !$academicYear) {
                return $this->json([
                    'has_conflicts' => false,
                    'conflicts' => [],
                    'warnings' => [],
                    'debug' => 'Missing required entities'
                ]);
            }

            $schedule->setSubject($subject);
            $schedule->setRoom($room);
            $schedule->setAcademicYear($academicYear);
            $schedule->setSemester($data['semester']);
            $schedule->setSection($data['section'] ?? '');
            $schedule->setDayPattern($data['day_pattern']);
            $schedule->setStatus('active');
            
            // Set curriculum subject if provided (for block sectioning conflict detection)
            if (isset($data['curriculum_subject_id']) && $data['curriculum_subject_id']) {
                $curriculumSubject = $this->entityManager->getRepository(CurriculumSubject::class)->find($data['curriculum_subject_id']);
                if ($curriculumSubject) {
                    $schedule->setCurriculumSubject($curriculumSubject);
                }
            }
            
            // Parse times
            $schedule->setStartTime(new \DateTime($data['start_time']));
            $schedule->setEndTime(new \DateTime($data['end_time']));
            
            // Check for conflicts - exclude self if editing existing schedule
            $conflicts = $conflictDetector->detectConflicts($schedule, $isEditingExisting);
            $duplicateConflicts = $conflictDetector->checkDuplicateSubjectSection($schedule, $isEditingExisting);
            
            // Combine hard conflicts - include block sectioning conflicts
            $hardConflicts = array_filter($conflicts, function($conflict) {
                return $conflict['type'] === 'room_time_conflict' 
                    || $conflict['type'] === 'section_conflict'
                    || $conflict['type'] === 'block_sectioning_conflict';
            });
            $hardConflicts = array_merge($hardConflicts, $duplicateConflicts);
            
            // Check capacity warnings
            $schedule->setEnrolledStudents(0); // Temporary for capacity check
            $capacityWarnings = $conflictDetector->validateRoomCapacity($schedule);
            
            // Format conflicts
            $conflictMessages = array_map(function($c) {
                return $c['message'];
            }, $hardConflicts);
            
            return $this->json([
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
            return $this->json([
                'has_conflicts' => false,
                'conflicts' => [],
                'warnings' => [],
                                'error' => $e->getMessage()
            ]);
        }
    }

    #[Route('/existing-sections/{subjectId}', name: 'app_schedule_existing_sections', methods: ['GET'])]
    public function getExistingSections(int $subjectId): JsonResponse
    {
        try {
            // Get all active schedules for this subject
            $schedules = $this->scheduleRepository->createQueryBuilder('s')
                ->select('s.section', 's.semester', 's.dayPattern', 
                         's.startTime', 's.endTime',
                         'r.id as roomId', 'r.code as roomCode',
                         'sub.code as subjectCode', 'sub.id as subjectId',
                         'ay.year', 'ay.id as yearId')
                ->leftJoin('s.academicYear', 'ay')
                ->leftJoin('s.room', 'r')
                ->leftJoin('s.subject', 'sub')
                ->where('s.subject = :subjectId')
                ->andWhere('s.status = :status')
                ->setParameter('subjectId', $subjectId)
                ->setParameter('status', 'active')
                ->getQuery()
                ->getResult();
            
            // Also get all schedules from OTHER subjects (for block sectioning conflict detection)
            // We need to check if same section at same time exists in other subjects
            $otherSchedules = $this->scheduleRepository->createQueryBuilder('s')
                ->select('s.section', 's.semester', 's.dayPattern', 
                         's.startTime', 's.endTime',
                         'r.id as roomId', 'r.code as roomCode',
                         'sub.code as subjectCode', 'sub.id as subjectId',
                         'ay.year', 'ay.id as yearId')
                ->leftJoin('s.academicYear', 'ay')
                ->leftJoin('s.room', 'r')
                ->leftJoin('s.subject', 'sub')
                ->where('s.subject != :subjectId')
                ->andWhere('s.status = :status')
                ->setParameter('subjectId', $subjectId)
                ->setParameter('status', 'active')
                ->getQuery()
                ->getResult();
            
            // Merge both result sets for comprehensive conflict checking
            $allSchedules = array_merge($schedules, $otherSchedules);
            
            // Extract unique sections
            $sections = [];
            $schedulesMap = [];
            
            foreach ($allSchedules as $schedule) {
                $section = $schedule['section'];
                $semester = $schedule['semester'];
                $year = $schedule['year'];
                $yearId = $schedule['yearId'];
                $scheduleSubjectId = $schedule['subjectId'];
                
                // Format time values
                $startTime = $schedule['startTime'];
                $endTime = $schedule['endTime'];
                
                // Convert DateTime objects to H:i format if needed
                if ($startTime instanceof \DateTime) {
                    $startTime = $startTime->format('H:i');
                }
                if ($endTime instanceof \DateTime) {
                    $endTime = $endTime->format('H:i');
                }
                
                // Add to unique sections list (only for current subject)
                if ($scheduleSubjectId == $subjectId && !in_array($section, $sections)) {
                    $sections[] = $section;
                }
                
                // Map for conflict checking: subject_section_semester_yearId
                // Include subject ID so we can distinguish between subjects
                $key = $scheduleSubjectId . '_' . strtoupper(trim($section)) . '_' . $semester . '_' . $yearId;
                
                $schedulesMap[$key] = [
                    'section' => $section,
                    'semester' => $semester,
                    'year' => $year,
                    'yearId' => $yearId,
                    'dayPattern' => $schedule['dayPattern'] ?? '',
                    'startTime' => $startTime ?? '',
                    'endTime' => $endTime ?? '',
                    'roomId' => $schedule['roomId'] ?? null,
                    'roomCode' => $schedule['roomCode'] ?? '',
                    'subjectCode' => $schedule['subjectCode'] ?? '',
                    'subjectId' => $scheduleSubjectId
                ];
            }
            
            // Sort sections alphabetically
            sort($sections);
            
            return $this->json([
                'sections' => $sections,
                'schedules' => $schedulesMap,
                'count' => count($sections),
                'success' => true
            ]);
            
        } catch (\Exception $e) {
            // Log the full error for debugging
            error_log('Error in getExistingSections: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return $this->json([
                'sections' => [],
                'schedules' => [],
                'error' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }

    #[Route('/room-schedules/{roomId}/{semester}/{academicYearId}', name: 'app_schedule_room_schedules', methods: ['GET'])]
    public function getRoomSchedules(int $roomId, string $semester, int $academicYearId): JsonResponse
    {
        try {
            // Get all active schedules for this room in the specified semester/year
            $schedules = $this->scheduleRepository->createQueryBuilder('s')
                ->select('s.section', 's.dayPattern', 
                         's.startTime', 's.endTime',
                         'sub.code as subjectCode',
                         'sub.title as subjectTitle')
                ->leftJoin('s.subject', 'sub')
                ->where('s.room = :roomId')
                ->andWhere('s.semester = :semester')
                ->andWhere('s.academicYear = :academicYearId')
                ->andWhere('s.status = :status')
                ->setParameter('roomId', $roomId)
                ->setParameter('semester', $semester)
                ->setParameter('academicYearId', $academicYearId)
                ->setParameter('status', 'active')
                ->getQuery()
                ->getResult();
            
            // Format the schedules
            $formattedSchedules = [];
            foreach ($schedules as $schedule) {
                $startTime = $schedule['startTime'];
                $endTime = $schedule['endTime'];
                
                // Convert DateTime objects to H:i format if needed
                if ($startTime instanceof \DateTime) {
                    $startTime = $startTime->format('H:i');
                }
                if ($endTime instanceof \DateTime) {
                    $endTime = $endTime->format('H:i');
                }
                
                $formattedSchedules[] = [
                    'subjectCode' => $schedule['subjectCode'] ?? '',
                    'subjectTitle' => $schedule['subjectTitle'] ?? '',
                    'section' => $schedule['section'] ?? '',
                    'dayPattern' => $schedule['dayPattern'] ?? '',
                    'startTime' => $startTime ?? '',
                    'endTime' => $endTime ?? ''
                ];
            }
            
            return $this->json([
                'schedules' => $formattedSchedules,
                'count' => count($formattedSchedules),
                'success' => true
            ]);
            
        } catch (\Exception $e) {
            error_log('Error in getRoomSchedules: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return $this->json([
                'schedules' => [],
                'error' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }

    #[Route('/check-room-conflicts', name: 'app_schedule_check_room_conflicts', methods: ['POST'])]
    public function checkRoomConflicts(Request $request, \App\Service\ScheduleConflictDetector $conflictDetector): JsonResponse
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
            $room = $this->roomRepository->find($roomId);
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
            $dummySubject = $this->entityManager->getRepository(\App\Entity\Subject::class)->findOneBy([]);
            if ($dummySubject) {
                $tempSchedule->setSubject($dummySubject);
            }
            
            // Check for conflicts
            $conflicts = $conflictDetector->detectConflicts($tempSchedule, false);
            
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/faculty-loading', name: 'app_schedule_faculty_loading', methods: ['GET'])]
    public function facultyLoading(Request $request): Response
    {
        $session = $request->getSession();
        
        $departmentId = $request->query->get('department');
        $changeDepartment = $request->query->get('change'); // Check if user wants to change department
        
        // If "change" parameter is present, clear session to force modal display
        if ($changeDepartment !== null) {
            $session->remove('faculty_loading_department_id');
            $departmentId = null;
        }
        // If no department in URL, DON'T use session - always require explicit department selection
        elseif (!$departmentId) {
            // Clear session when accessing without department parameter
            // This ensures modal is always shown when navigating from sidebar
            $session->remove('faculty_loading_department_id');
        }
        
        $selectedDepartment = null;
        $faculty = [];
        $scheduledSubjects = [];
        
        if ($departmentId) {
            $selectedDepartment = $this->entityManager->getRepository(\App\Entity\Department::class)->find($departmentId);
            
            // Only proceed if department exists
            if ($selectedDepartment) {
                // Store in session for future refreshes (using separate key for faculty loading)
                $session->set('faculty_loading_department_id', $departmentId);
                
                // Determine which departments to include for faculty
                $departmentGroup = $selectedDepartment->getDepartmentGroup();
                $departmentsForFaculty = [$selectedDepartment];
                
                if ($departmentGroup) {
                    // If department is in a group, get faculty from all departments in the group
                    $departmentsForFaculty = $departmentGroup->getDepartments()->toArray();
                }
                
                // Get all faculty members from the department(s) (role = 3 is Faculty)
                // OPTIMIZATION: Eager load department to prevent N+1 queries
                $faculty = $this->entityManager->getRepository(\App\Entity\User::class)
                    ->createQueryBuilder('u')
                    ->leftJoin('u.department', 'd')
                    ->addSelect('d')
                    ->where('u.department IN (:departments)')
                    ->andWhere('u.role = :roleId')
                    ->setParameter('departments', $departmentsForFaculty)
                    ->setParameter('roleId', 3)
                    ->orderBy('u.department', 'ASC')
                    ->addOrderBy('u.firstName', 'ASC')
                    ->getQuery()
                    ->getResult();
                
                // Get active semester for filtering
                $activeYear = $this->systemSettingsService->getActiveAcademicYear();
                $activeSemester = $this->systemSettingsService->getActiveSemester();
                
                // Get all schedules with subjects from ALL departments in the group (not just selected department)
                // This ensures faculty assignments are visible across grouped departments
                // OPTIMIZATION: Eager load subject and faculty to prevent N+1 queries
                $qb = $this->scheduleRepository->createQueryBuilder('s')
                    ->innerJoin('s.subject', 'subj')
                    ->addSelect('subj')
                    ->leftJoin('s.faculty', 'f')
                    ->addSelect('f')
                    ->where('subj.department IN (:departments)')
                    ->setParameter('departments', $departmentsForFaculty);
                
                // Filter by active semester if available
                if ($activeYear && $activeSemester) {
                    $qb->andWhere('s.academicYear = :year')
                       ->andWhere('s.semester = :semester')
                       ->setParameter('year', $activeYear)
                       ->setParameter('semester', $activeSemester);
                }
                
                $scheduledSubjects = $qb->orderBy('subj.code', 'ASC')
                    ->addOrderBy('s.section', 'ASC')
                    ->getQuery()
                    ->getResult();
            } else {
                // Invalid department ID, clear session
                $session->remove('faculty_loading_department_id');
            }
        }
        
        // Get all departments sorted by name
        $departments = $this->entityManager->getRepository(\App\Entity\Department::class)
            ->createQueryBuilder('d')
            ->leftJoin('d.college', 'c')
            ->addSelect('c')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
        
        $response = $this->render('admin/schedule/faculty_loading.html.twig', [
            'departments' => $departments,
            'selectedDepartment' => $selectedDepartment,
            'faculty' => $faculty,
            'scheduledSubjects' => $scheduledSubjects,
            'activeSemesterDisplay' => $this->systemSettingsService->getActiveSemesterDisplay(),
            'hasActiveSemester' => $this->systemSettingsService->hasActiveSemester(),
            'dashboard_data' => $this->dashboardService->getAdminDashboardData(),
        ]);
        
        // Add cache-control headers to prevent browser from caching this page
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        
        return $response;
    }

    #[Route('/faculty-loading/assign', name: 'app_schedule_faculty_loading_assign', methods: ['POST'])]
    public function assignFacultyToSchedule(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $scheduleId = $data['schedule_id'] ?? null;
            $facultyId = $data['faculty_id'] ?? null;
            
            if (!$scheduleId) {
                return $this->json(['success' => false, 'message' => 'Schedule ID is required'], 400);
            }
            
            $schedule = $this->scheduleRepository->find($scheduleId);
            if (!$schedule) {
                return $this->json(['success' => false, 'message' => 'Schedule not found'], 404);
            }
            
            if ($facultyId) {
                $faculty = $this->entityManager->getRepository(\App\Entity\User::class)->find($facultyId);
                if (!$faculty) {
                    return $this->json(['success' => false, 'message' => 'Faculty member not found'], 404);
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
                // Unassign faculty
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
            
            $this->entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => $facultyId ? 'Faculty assigned successfully' : 'Faculty unassigned successfully',
                'faculty_name' => $facultyId ? $faculty->getFirstName() . ' ' . $faculty->getLastName() : null
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    #[Route('/teaching-load-pdf/{facultyId}', name: 'app_schedule_teaching_load_pdf', methods: ['GET'])]
    public function teachingLoadPDF(int $facultyId, \App\Service\TeachingLoadPdfService $pdfService): Response
    {
        // Get faculty member
        $faculty = $this->entityManager->getRepository(\App\Entity\User::class)->find($facultyId);
        
        if (!$faculty) {
            throw $this->createNotFoundException('Faculty member not found');
        }

        // Get the current active academic year
        $academicYear = $this->academicYearRepository->findOneBy(['isActive' => true]);
        
        if (!$academicYear) {
            $this->addFlash('error', 'No active academic year found');
            return $this->redirectToRoute('app_schedule_faculty_loading');
        }

        // Get the active semester
        $activeSemester = $this->systemSettingsService->getActiveSemester();

        try {
            // Generate PDF using the service with semester filter
            $pdfContent = $pdfService->generateTeachingLoadPdf($faculty, $academicYear, $activeSemester);
            
            $filename = 'teaching_load_' . $faculty->getLastName() . '_' . date('Y-m-d') . '.pdf';
            
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
            return $this->redirectToRoute('app_schedule_faculty_loading');
        }
    }

    #[Route('/faculty-loading/toggle-overload/{id}', name: 'app_schedule_faculty_loading_toggle_overload', methods: ['POST'])]
    public function toggleFacultyLoadingOverload(Schedule $schedule): Response
    {
        // Check if the schedule has a faculty assigned
        if (!$schedule->getFaculty()) {
            return $this->json(['success' => false, 'message' => 'Schedule must have a faculty assigned'], 400);
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
}
