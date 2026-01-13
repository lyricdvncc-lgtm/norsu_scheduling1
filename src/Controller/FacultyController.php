<?php

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\AcademicYear;
use App\Entity\User;
use App\Form\FacultyProfileFormType;
use App\Service\SystemSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use TCPDF;

#[Route('/faculty', name: 'faculty_')]
#[IsGranted('ROLE_FACULTY')]
class FacultyController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private SystemSettingsService $systemSettingsService;

    public function __construct(
        EntityManagerInterface $entityManager,
        SystemSettingsService $systemSettingsService
    ) {
        $this->entityManager = $entityManager;
        $this->systemSettingsService = $systemSettingsService;
    }

    private function getBaseTemplateData(): array
    {
        return [
            'activeSemesterDisplay' => $this->systemSettingsService->getActiveSemesterDisplay(),
            'hasActiveSemester' => $this->systemSettingsService->hasActiveSemester(),
        ];
    }

    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Get current academic year and active semester
        $currentAcademicYear = $this->entityManager->getRepository(AcademicYear::class)
            ->findOneBy(['isCurrent' => true]);
        
        $activeSemester = $currentAcademicYear?->getCurrentSemester();
        
        // Get today's day of week
        $today = new \DateTime();
        $dayOfWeek = $today->format('l'); // Monday, Tuesday, etc.
        
        // Map full day name to possible day patterns
        $dayPatterns = $this->getDayPatternsForDay($dayOfWeek);
        
        // Fetch today's schedules
        $todaySchedules = [];
        if ($currentAcademicYear && $activeSemester && !empty($dayPatterns)) {
            $qb = $this->entityManager->getRepository(Schedule::class)
                ->createQueryBuilder('s')
                ->leftJoin('s.subject', 'sub')
                ->leftJoin('s.room', 'r')
                ->leftJoin('s.academicYear', 'ay')
                ->leftJoin('s.curriculumSubject', 'cs')
                ->where('s.faculty = :faculty')
                ->andWhere('s.status = :status')
                ->andWhere('ay.isCurrent = :isCurrent')
                ->andWhere('s.semester = :semester')
                ->andWhere('s.dayPattern IN (:dayPatterns)')
                ->setParameter('faculty', $user)
                ->setParameter('status', 'active')
                ->setParameter('isCurrent', true)
                ->setParameter('semester', $activeSemester)
                ->setParameter('dayPatterns', $dayPatterns)
                ->orderBy('s.startTime', 'ASC')
                ->getQuery();
            
            $todaySchedules = $qb->getResult();
        }
        
        // Get all active schedules for statistics
        $allSchedules = [];
        if ($currentAcademicYear && $activeSemester) {
            $allSchedules = $this->entityManager->getRepository(Schedule::class)
                ->createQueryBuilder('s')
                ->leftJoin('s.academicYear', 'ay')
                ->where('s.faculty = :faculty')
                ->andWhere('s.status = :status')
                ->andWhere('ay.isCurrent = :isCurrent')
                ->andWhere('s.semester = :semester')
                ->setParameter('faculty', $user)
                ->setParameter('status', 'active')
                ->setParameter('isCurrent', true)
                ->setParameter('semester', $activeSemester)
                ->getQuery()
                ->getResult();
        }
        
        // Calculate total teaching hours per week
        $totalHours = 0;
        $uniqueClasses = [];
        $totalStudents = 0;
        
        foreach ($allSchedules as $schedule) {
            $start = $schedule->getStartTime();
            $end = $schedule->getEndTime();
            $diff = $start->diff($end);
            $hours = $diff->h + ($diff->i / 60);
            
            // Count hours based on day pattern frequency per week
            $daysPerWeek = count($schedule->getDaysFromPattern());
            $totalHours += $hours * $daysPerWeek;
            
            // Track unique classes
            $classKey = $schedule->getSubject()->getId() . '_' . $schedule->getSection();
            if (!isset($uniqueClasses[$classKey])) {
                $uniqueClasses[$classKey] = true;
                $totalStudents += $schedule->getEnrolledStudents();
            }
        }
        
        return $this->render('faculty/dashboard.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Faculty Dashboard',
            'todaySchedules' => $todaySchedules,
            'totalHours' => round($totalHours, 1),
            'activeClasses' => count($uniqueClasses),
            'totalStudents' => $totalStudents,
            'todayCount' => count($todaySchedules),
        ]));
    }
    
    /**
     * Get all day patterns that include the given day
     */
    private function getDayPatternsForDay(string $dayOfWeek): array
    {
        $patterns = [];
        
        switch ($dayOfWeek) {
            case 'Monday':
                $patterns = ['M-W-F', 'M-T-TH-F', 'M-T'];
                break;
            case 'Tuesday':
                $patterns = ['T-TH', 'M-T-TH-F', 'M-T'];
                break;
            case 'Wednesday':
                $patterns = ['M-W-F'];
                break;
            case 'Thursday':
                $patterns = ['T-TH', 'M-T-TH-F', 'TH-F'];
                break;
            case 'Friday':
                $patterns = ['M-W-F', 'M-T-TH-F', 'TH-F'];
                break;
            case 'Saturday':
                $patterns = ['SAT'];
                break;
            case 'Sunday':
                $patterns = ['SUN'];
                break;
        }
        
        return $patterns;
    }

    #[Route('/schedule', name: 'schedule')]
    public function schedule(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Get current academic year and active semester
        $currentAcademicYear = $this->entityManager->getRepository(AcademicYear::class)
            ->findOneBy(['isCurrent' => true]);
        
        $activeSemester = $this->systemSettingsService->getActiveSemester();
        
        // Get selected semester or default to active semester
        $selectedSemester = $request->query->get('semester', $activeSemester);
        
        // Fetch schedules for the faculty
        $schedules = $this->entityManager->getRepository(Schedule::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.subject', 'sub')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.academicYear', 'ay')
            ->where('s.faculty = :faculty')
            ->andWhere('s.status = :status')
            ->andWhere('ay.isCurrent = :isCurrent')
            ->andWhere('s.semester = :semester')
            ->setParameter('faculty', $user)
            ->setParameter('status', 'active')
            ->setParameter('isCurrent', true)
            ->setParameter('semester', $selectedSemester)
            ->orderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        // Process schedules into weekly view
        $weeklySchedule = $this->buildWeeklySchedule($schedules);
        
        // Calculate statistics
        $stats = $this->calculateScheduleStats($schedules);

        return $this->render('faculty/schedule.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'My Teaching Schedule',
            'schedules' => $schedules,
            'weeklySchedule' => $weeklySchedule,
            'stats' => $stats,
            'selectedSemester' => $selectedSemester,
            'currentAcademicYear' => $currentAcademicYear,
        ]));
    }

    #[Route('/schedule/export-pdf', name: 'schedule_export_pdf')]
    public function exportSchedulePdf(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Get current academic year
        $currentAcademicYear = $this->entityManager->getRepository(AcademicYear::class)
            ->findOneBy(['isCurrent' => true]);
        
        // Get selected semester or default to current
        $selectedSemester = $request->query->get('semester', $user->getPreferredSemesterFilter() ?? '1st Semest');
        
        // Fetch schedules for the faculty
        $schedules = $this->entityManager->getRepository(Schedule::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.subject', 'sub')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.academicYear', 'ay')
            ->where('s.faculty = :faculty')
            ->andWhere('s.status = :status')
            ->andWhere('ay.isCurrent = :isCurrent')
            ->andWhere('s.semester = :semester')
            ->setParameter('faculty', $user)
            ->setParameter('status', 'active')
            ->setParameter('isCurrent', true)
            ->setParameter('semester', $selectedSemester)
            ->orderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        // Process schedules into weekly view
        $weeklySchedule = $this->buildWeeklySchedule($schedules);
        
        // Calculate statistics
        $stats = $this->calculateScheduleStats($schedules);

        // Generate PDF
        $pdf = $this->generateSchedulePdf($user, $schedules, $weeklySchedule, $stats, $currentAcademicYear, $selectedSemester);
        
        // Return PDF as response
        return new Response(
            $pdf->Output('teaching-schedule.pdf', 'S'),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="teaching-schedule.pdf"'
            ]
        );
    }

    #[Route('/office-hours', name: 'office_hours')]
    public function officeHours(): Response
    {
        return $this->render('faculty/office_hours.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Office Hours',
        ]));
    }

    #[Route('/classes', name: 'classes')]
    public function classes(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Get current academic year and active semester
        $currentAcademicYear = $this->entityManager->getRepository(AcademicYear::class)
            ->findOneBy(['isCurrent' => true]);
        
        $activeSemester = $this->systemSettingsService->getActiveSemester();
        
        // Get selected semester filter or default to active semester
        $selectedSemester = $request->query->get('semester', $activeSemester);
        
        // Fetch schedules for the faculty with subject and enrollment data
        $schedules = $this->entityManager->getRepository(Schedule::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.subject', 'sub')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.academicYear', 'ay')
            ->where('s.faculty = :faculty')
            ->andWhere('s.status = :status')
            ->andWhere('ay.isCurrent = :isCurrent')
            ->andWhere('s.semester = :semester')
            ->setParameter('faculty', $user)
            ->setParameter('status', 'active')
            ->setParameter('isCurrent', true)
            ->setParameter('semester', $selectedSemester)
            ->orderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();
        
        // Calculate stats
        $totalClasses = count($schedules);
        $totalStudents = 0;
        $totalHours = 0;
        $pendingTasks = 0; // Can be calculated based on assignments, grades, etc.
        
        foreach ($schedules as $schedule) {
            // Note: Student enrollment tracking would need to be added if needed
            // For now, we'll set student count to 0 or use enrolledStudents field if available
            if (method_exists($schedule, 'getEnrolledStudents')) {
                $totalStudents += $schedule->getEnrolledStudents() ?? 0;
            }
            
            // Calculate hours for this class
            if ($schedule->getStartTime() && $schedule->getEndTime()) {
                $start = $schedule->getStartTime();
                $end = $schedule->getEndTime();
                $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
                
                // Count number of days per week
                $days = $schedule->getDaysFromPattern();
                $totalHours += $hours * count($days);
            }
        }
        
        $stats = [
            'total_classes' => $totalClasses,
            'total_students' => $totalStudents,
            'teaching_hours' => round($totalHours, 1),
            'pending_tasks' => $pendingTasks
        ];

        return $this->render('faculty/classes.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'My Classes',
            'schedules' => $schedules,
            'stats' => $stats,
            'selectedSemester' => $selectedSemester,
            'currentAcademicYear' => $currentAcademicYear,
        ]));
    }

    #[Route('/performance', name: 'performance')]
    public function performance(): Response
    {
        return $this->render('faculty/performance.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'My Performance',
        ]));
    }

    #[Route('/profile', name: 'profile')]
    public function profile(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        $form = $this->createForm(FacultyProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->entityManager->flush();
                $this->addFlash('success', 'Profile updated successfully!');
                
                return $this->redirectToRoute('faculty_profile');
            } catch (\Exception $e) {
                $this->addFlash('error', 'An error occurred while updating your profile. Please try again.');
            }
        }

        return $this->render('faculty/profile.html.twig', array_merge($this->getBaseTemplateData(), [
            'page_title' => 'Profile & Settings',
            'form' => $form->createView(),
            'user' => $user,
        ]), new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Build weekly schedule array from schedules
     */
    private function buildWeeklySchedule(array $schedules): array
    {
        $weeklySchedule = [
            'Monday' => [],
            'Tuesday' => [],
            'Wednesday' => [],
            'Thursday' => [],
            'Friday' => [],
            'Saturday' => [],
            'Sunday' => []
        ];

        foreach ($schedules as $schedule) {
            $days = $schedule->getDaysFromPattern();
            foreach ($days as $day) {
                if (isset($weeklySchedule[$day])) {
                    $weeklySchedule[$day][] = $schedule;
                }
            }
        }

        return $weeklySchedule;
    }

    /**
     * Calculate schedule statistics
     */
    private function calculateScheduleStats(array $schedules): array
    {
        $totalHours = 0;
        $totalStudents = 0;
        $uniqueRooms = [];
        $uniqueSubjects = [];

        foreach ($schedules as $schedule) {
            // Calculate hours
            $start = $schedule->getStartTime();
            $end = $schedule->getEndTime();
            if ($start && $end) {
                $diff = $start->diff($end);
                $hours = $diff->h + ($diff->i / 60);
                
                // Multiply by number of days per week
                $daysCount = count($schedule->getDaysFromPattern());
                $totalHours += $hours * $daysCount;
            }

            // Count students
            $totalStudents += $schedule->getEnrolledStudents() ?? 0;

            // Track unique rooms
            if ($schedule->getRoom()) {
                $uniqueRooms[$schedule->getRoom()->getId()] = $schedule->getRoom();
            }

            // Track unique subjects
            if ($schedule->getSubject()) {
                $uniqueSubjects[$schedule->getSubject()->getId()] = $schedule->getSubject();
            }
        }

        return [
            'totalHours' => round($totalHours, 1),
            'totalClasses' => count($schedules),
            'totalStudents' => $totalStudents,
            'totalRooms' => count($uniqueRooms),
        ];
    }

    /**
     * Generate PDF document for teaching schedule
     */
    private function generateSchedulePdf(User $user, array $schedules, array $weeklySchedule, array $stats, ?AcademicYear $academicYear, string $semester): TCPDF
    {
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Smart Scheduling System');
        $pdf->SetAuthor($user->getFirstName() . ' ' . $user->getLastName());
        $pdf->SetTitle('Teaching Schedule');
        $pdf->SetSubject('Teaching Schedule');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        
        // Add a page
        $pdf->AddPage();
        
        // Set font
        $pdf->SetFont('helvetica', 'B', 16);
        
        // Title
        $pdf->Cell(0, 10, 'Teaching Schedule', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 10);
        $facultyName = $user->getFirstName() . ' ' . $user->getLastName();
        $ayText = $academicYear ? $academicYear->getYear() : '';
        $pdf->Cell(0, 5, $facultyName . ' - ' . $ayText . ' (' . $semester . ' Semester)', 0, 1, 'C');
        $pdf->Ln(5);
        
        // Statistics boxes
        $pdf->SetFont('helvetica', 'B', 10);
        $boxWidth = 60;
        $boxHeight = 15;
        
        $stats_data = [
            ['Total Hours', $stats['totalHours']],
            ['Classes', $stats['totalClasses']],
            ['Students', $stats['totalStudents']],
            ['Rooms', $stats['totalRooms']]
        ];
        
        $x = 15;
        foreach ($stats_data as $stat) {
            $pdf->SetXY($x, $pdf->GetY());
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell($boxWidth, $boxHeight, '', 1, 0, 'C', true);
            $pdf->SetXY($x, $pdf->GetY());
            $pdf->Cell($boxWidth, 7, $stat[0], 0, 2, 'C');
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell($boxWidth, 8, (string)$stat[1], 0, 0, 'C');
            $pdf->SetFont('helvetica', 'B', 10);
            $x += $boxWidth + 5;
        }
        
        $pdf->Ln(20);
        
        // Class list
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 7, 'Class List', 0, 1, 'L');
        $pdf->Ln(2);
        
        // Table header
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(30, 7, 'Code', 1, 0, 'L', true);
        $pdf->Cell(65, 7, 'Subject', 1, 0, 'L', true);
        $pdf->Cell(45, 7, 'Schedule', 1, 0, 'L', true);
        $pdf->Cell(40, 7, 'Room', 1, 0, 'L', true);
        $pdf->Cell(25, 7, 'Students', 1, 0, 'C', true);
        $pdf->Cell(25, 7, 'Section', 1, 1, 'C', true);
        
        // Table rows
        $pdf->SetFont('helvetica', '', 8);
        foreach ($schedules as $schedule) {
            $code = $schedule->getSubject()->getCode();
            $title = $schedule->getSubject()->getTitle();
            $scheduleText = $schedule->getDayPatternLabel() . "\n" . 
                          $schedule->getStartTime()->format('g:i A') . '-' . 
                          $schedule->getEndTime()->format('g:i A');
            $room = $schedule->getRoom()->getName();
            $students = (string)($schedule->getEnrolledStudents() ?? 0);
            $section = $schedule->getSection() ?? '-';
            
            $pdf->Cell(30, 12, $code, 1, 0, 'L');
            $pdf->MultiCell(65, 12, $title, 1, 'L', false, 0);
            $pdf->MultiCell(45, 6, $scheduleText, 1, 'L', false, 0);
            $pdf->Cell(40, 12, $room, 1, 0, 'L');
            $pdf->Cell(25, 12, $students, 1, 0, 'C');
            $pdf->Cell(25, 12, $section, 1, 1, 'C');
        }
        
        return $pdf;
    }
}
