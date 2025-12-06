<?php

namespace App\Controller;

use App\Entity\Schedule;
use App\Repository\ScheduleRepository;
use App\Repository\RoomRepository;
use App\Repository\SubjectRepository;
use App\Repository\AcademicYearRepository;
use App\Service\SubjectService;
use App\Service\ActivityLogService;
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

    public function __construct(
        EntityManagerInterface $entityManager,
        ScheduleRepository $scheduleRepository,
        RoomRepository $roomRepository,
        SubjectRepository $subjectRepository,
        AcademicYearRepository $academicYearRepository,
        SubjectService $subjectService,
        ActivityLogService $activityLogService
    ) {
        $this->entityManager = $entityManager;
        $this->scheduleRepository = $scheduleRepository;
        $this->roomRepository = $roomRepository;
        $this->subjectRepository = $subjectRepository;
        $this->academicYearRepository = $academicYearRepository;
        $this->subjectService = $subjectService;
        $this->activityLogService = $activityLogService;
    }

    #[Route('/', name: 'app_schedule_index', methods: ['GET'])]
    public function index(Request $request, \App\Service\ScheduleConflictDetector $conflictDetector): Response
    {
        // LOG: This should NOT be called when reloading Faculty Loading
        error_log('!!! SCHEDULES INDEX CALLED !!! URL: ' . $request->getRequestUri());
        error_log('!!! Route: ' . $request->attributes->get('_route'));
        
        $user = $this->getUser();
        $session = $request->getSession();
        
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
        
        // Get schedules - filter by department if one is selected
        if ($selectedDepartment) {
            $schedules = $this->scheduleRepository->findByDepartment($selectedDepartment->getId());
        } else {
            $schedules = $this->scheduleRepository->findAllWithRelations();
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

        return $this->render('admin/schedule/index.html.twig', [
            'schedules' => $schedules,
            'departments' => $departments,
            'rooms' => $rooms,
            'selectedDepartment' => $selectedDepartment,
        ]);
    }

    #[Route('/select-department', name: 'app_schedule_select_department', methods: ['GET'])]
    public function selectDepartment(): Response
    {
        $departments = $this->entityManager->getRepository(\App\Entity\Department::class)->findAll();

        return $this->render('admin/schedule/select_department.html.twig', [
            'departments' => $departments,
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
        }

        // Get subjects for the selected department
        $subjects = [];
        if ($selectedDepartment) {
            // Get semester filter from user's database preference first, then session
            $user = $this->getUser();
            
            // Load from database if not in session
            if (!$session->has('semester_filter') && $user instanceof \App\Entity\User) {
                $preferredFilter = $user->getPreferredSemesterFilter();
                if ($preferredFilter) {
                    $session->set('semester_filter', $preferredFilter);
                }
            }
            
            $semesterFilter = $session->get('semester_filter');
            
            // Build filters for SubjectService
            $filters = [
                'department_id' => $selectedDepartment->getId(),
            ];
            
            // Add semester filter if it exists
            if ($semesterFilter) {
                $filters['semester'] = $semesterFilter;
            }
            
            // Use SubjectService to get filtered subjects
            $subjects = $this->subjectService->getSubjects($filters);
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
                $schedule = new Schedule();
                
                // Get and validate form data
                $subjectId = $request->request->get('subject');
                $academicYearId = $request->request->get('academic_year');
                $semester = $request->request->get('semester');
                $roomId = $request->request->get('room');
                $section = trim($request->request->get('section'));
                $dayPattern = $request->request->get('day_pattern');
                $startTime = $request->request->get('start_time');
                $endTime = $request->request->get('end_time');
                $enrolledStudents = (int) $request->request->get('enrolled_students', 0);
                $notes = trim($request->request->get('notes'));

                // Validation: Check required fields
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
                if (empty($roomId)) {
                    $errors[] = 'Room is required.';
                }
                if (empty($dayPattern)) {
                    $errors[] = 'Day Pattern is required.';
                }
                if (empty($startTime)) {
                    $errors[] = 'Start Time is required.';
                }
                if (empty($endTime)) {
                    $errors[] = 'End Time is required.';
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
                        'semesterFilter' => $session->get('semester_filter'),
                    ]);
                }

                // Find entities
                $subject = $this->subjectRepository->find($subjectId);
                $academicYear = $this->academicYearRepository->find($academicYearId);
                $room = $this->roomRepository->find($roomId);

                if (!$subject) {
                    throw new \Exception('Invalid subject selected.');
                }
                if (!$academicYear) {
                    throw new \Exception('Invalid academic year selected.');
                }
                if (!$room) {
                    throw new \Exception('Invalid room selected.');
                }

                // Set basic properties
                $schedule->setSubject($subject);
                $schedule->setAcademicYear($academicYear);
                $schedule->setSemester($semester);
                $schedule->setRoom($room);
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
                $timeErrors = $conflictDetector->validateTimeRange($schedule);
                if (!empty($timeErrors)) {
                    foreach ($timeErrors as $error) {
                        $this->addFlash('error', $error);
                    }
                    return $this->render('admin/schedule/new_v2.html.twig', [
                        'subjects' => $subjects,
                        'rooms' => $rooms,
                        'academicYears' => $academicYears,
                        'selectedDepartment' => $selectedDepartment,
                        'departmentId' => $departmentId,
                        'semesterFilter' => $session->get('semester_filter'),
                    ]);
                }

                // Check for HARD conflicts (room-time conflicts)
                $conflicts = $conflictDetector->detectConflicts($schedule);
                
                // Check for duplicate subject-section
                $duplicateConflicts = $conflictDetector->checkDuplicateSubjectSection($schedule);
                
                // Combine hard conflicts
                $hardConflicts = array_filter($conflicts, function($conflict) {
                    return $conflict['type'] === 'room_time_conflict';
                });
                
                // Add duplicate subject-section to hard conflicts
                $hardConflicts = array_merge($hardConflicts, $duplicateConflicts);
                
                // Add section time conflicts to hard conflicts (students can't be in 2 places)
                $sectionTimeConflicts = array_filter($conflicts, function($conflict) {
                    return $conflict['type'] === 'section_conflict';
                });
                $hardConflicts = array_merge($hardConflicts, $sectionTimeConflicts);
                
                // BLOCK if hard conflicts exist
                if (!empty($hardConflicts)) {
                    foreach ($hardConflicts as $conflict) {
                        $this->addFlash('error', '🚫 ' . $conflict['message']);
                    }
                    
                    $this->addFlash('error', sprintf(
                        'Cannot create schedule: %d conflict(s) detected. Please choose different time/room/section.',
                        count($hardConflicts)
                    ));
                    
                    return $this->render('admin/schedule/new_v2.html.twig', [
                        'subjects' => $subjects,
                        'rooms' => $rooms,
                        'academicYears' => $academicYears,
                        'selectedDepartment' => $selectedDepartment,
                        'departmentId' => $departmentId,
                        'semesterFilter' => $session->get('semester_filter'),
                    ]);
                }
                
                // WARN for soft conflicts (capacity)
                $capacityErrors = $conflictDetector->validateRoomCapacity($schedule);
                if (!empty($capacityErrors)) {
                    foreach ($capacityErrors as $error) {
                        $this->addFlash('warning', '⚠️ ' . $error);
                    }
                }

                // No conflicts - save as clean schedule
                $schedule->setIsConflicted(false);

                // Save the schedule
                $this->entityManager->persist($schedule);
                $this->entityManager->flush();

                // Log the activity
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

                $this->addFlash('success', '✅ Schedule created successfully!');
                
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
            'semesterFilter' => $session->get('semester_filter'),
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
    public function edit(Request $request, Schedule $schedule): Response
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
                $schedule->setSubject($subject);
                $schedule->setAcademicYear($academicYear);
                $schedule->setSemester($semester);
                $schedule->setRoom($room);
                $schedule->setSection($section);
                $schedule->setDayPattern($dayPattern);
                $schedule->setStartTime(new \DateTime($startTime));
                $schedule->setEndTime(new \DateTime($endTime));
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
            } else {
                $this->addFlash('error', 'Please fill in all required fields.');
            }
        }

        return $this->render('admin/schedule/edit.html.twig', [
            'schedule' => $schedule,
            'subjects' => $subjects,
            'rooms' => $rooms,
            'academicYears' => $academicYears,
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
            
            // Parse times
            $schedule->setStartTime(new \DateTime($data['start_time']));
            $schedule->setEndTime(new \DateTime($data['end_time']));
            
            // Check for conflicts - exclude self if editing existing schedule
            $conflicts = $conflictDetector->detectConflicts($schedule, $isEditingExisting);
            $duplicateConflicts = $conflictDetector->checkDuplicateSubjectSection($schedule, $isEditingExisting);
            
            // Combine hard conflicts
            $hardConflicts = array_filter($conflicts, function($conflict) {
                return $conflict['type'] === 'room_time_conflict' || $conflict['type'] === 'section_conflict';
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
                ->select('s.section', 's.semester', 'ay.year', 'ay.id as yearId')
                ->leftJoin('s.academicYear', 'ay')
                ->where('s.subject = :subjectId')
                ->andWhere('s.status = :status')
                ->setParameter('subjectId', $subjectId)
                ->setParameter('status', 'active')
                ->getQuery()
                ->getResult();

            // Extract unique sections
            $sections = [];
            $schedulesMap = [];
            
            foreach ($schedules as $schedule) {
                $section = $schedule['section'];
                $semester = $schedule['semester'];
                $year = $schedule['year'];
                $yearId = $schedule['yearId'];
                
                // Add to unique sections list
                if (!in_array($section, $sections)) {
                    $sections[] = $section;
                }
                
                // Map for duplicate checking: section_semester_yearId
                $key = strtoupper(trim($section)) . '_' . $semester . '_' . $yearId;
                $schedulesMap[$key] = [
                    'section' => $section,
                    'semester' => $semester,
                    'year' => $year,
                    'yearId' => $yearId
                ];
            }
            
            // Sort sections alphabetically
            sort($sections);
            
            return $this->json([
                'sections' => $sections,
                'schedules' => $schedulesMap,
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

    #[Route('/faculty-loading', name: 'app_schedule_faculty_loading', methods: ['GET'])]
    public function facultyLoading(Request $request): Response
    {
        $session = $request->getSession();
        
        // LOG: Request start
        error_log('=== FACULTY LOADING REQUEST START ===');
        error_log('URL: ' . $request->getRequestUri());
        error_log('Route: ' . $request->attributes->get('_route'));
        error_log('Method: ' . $request->getMethod());
        error_log('Referer: ' . ($request->headers->get('referer') ?? 'NONE'));
        
        $departmentId = $request->query->get('department');
        $changeDepartment = $request->query->get('change'); // Check if user wants to change department
        
        error_log('Department from URL: ' . ($departmentId ?? 'NULL'));
        error_log('Change param: ' . ($changeDepartment !== null ? 'YES' : 'NO'));
        error_log('Session faculty_loading_department_id: ' . ($session->get('faculty_loading_department_id') ?? 'NULL'));
        error_log('Session selected_department_id (schedules): ' . ($session->get('selected_department_id') ?? 'NULL'));
        
        // If "change" parameter is present, clear session to force modal display
        if ($changeDepartment !== null) {
            $session->remove('faculty_loading_department_id');
            $departmentId = null;
            error_log('ACTION: Clearing session - change department requested');
        }
        // If no department in URL, DON'T use session - always require explicit department selection
        elseif (!$departmentId) {
            // Clear session when accessing without department parameter
            // This ensures modal is always shown when navigating from sidebar
            $session->remove('faculty_loading_department_id');
            error_log('ACTION: No department in URL - clearing session to show modal');
        } else {
            error_log('ACTION: Using department from URL: ' . $departmentId);
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
                error_log('ACTION: Stored department ' . $departmentId . ' in faculty_loading_department_id session');
                
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
                
                // Get all schedules with subjects from ALL departments in the group (not just selected department)
                // This ensures faculty assignments are visible across grouped departments
                // OPTIMIZATION: Eager load subject and faculty to prevent N+1 queries
                $scheduledSubjects = $this->scheduleRepository->createQueryBuilder('s')
                    ->innerJoin('s.subject', 'subj')
                    ->addSelect('subj')
                    ->leftJoin('s.faculty', 'f')
                    ->addSelect('f')
                    ->where('subj.department IN (:departments)')
                    ->setParameter('departments', $departmentsForFaculty)
                    ->orderBy('subj.code', 'ASC')
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
        
        // LOG: Final state before rendering
        error_log('FINAL STATE - Department ID: ' . ($departmentId ?: 'NULL'));
        error_log('FINAL STATE - Selected Department: ' . ($selectedDepartment ? $selectedDepartment->getName() : 'NULL'));
        error_log('FINAL STATE - Rendering faculty_loading.html.twig');
        error_log('=== FACULTY LOADING REQUEST END ===');
        
        $response = $this->render('admin/schedule/faculty_loading.html.twig', [
            'departments' => $departments,
            'selectedDepartment' => $selectedDepartment,
            'faculty' => $faculty,
            'scheduledSubjects' => $scheduledSubjects,
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

        try {
            // Generate PDF using the service
            $pdfContent = $pdfService->generateTeachingLoadPdf($faculty, $academicYear);
            
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
}