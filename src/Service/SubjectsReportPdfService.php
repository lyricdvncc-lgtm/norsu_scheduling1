<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use TCPDF;

class SubjectsReportPdfService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function generateSubjectsReportPdf(?string $year = null, ?string $semester = null, ?int $departmentId = null): string
    {
        // Create new PDF document in landscape mode for wider tables
        $pdf = new TCPDF('L', 'mm', 'LEGAL', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Smart Scheduling System');
        $pdf->SetAuthor('NORSU');
        $pdf->SetTitle('Subject Offerings Report');
        $pdf->SetSubject('Subject Offerings Catalog');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins - Legal landscape: 355.6mm width, with margins = ~335mm usable
        $pdf->SetMargins(30, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 10);

        // Add a page
        $pdf->AddPage();

        // Get subjects data
        $subjectsData = $this->getSubjectsData($year, $semester, $departmentId);

        // Generate the PDF content
        $this->generateHeader($pdf, $year, $semester, $departmentId);
        $this->generateSummary($pdf, $subjectsData);
        $this->generateSubjectsTable($pdf, $subjectsData);
        $this->generateFooter($pdf);

        // Return PDF as string
        return $pdf->Output('', 'S');
    }

    /**
     * Generate a Subject Offerings PDF from pre-built history data array.
     * Used by department head history reports.
     */
    public function generateSubjectsHistoryPdf(array $subjectsData, ?string $year = null, ?string $semester = null, ?string $departmentName = null, ?string $searchTerm = null): string
    {
        $pdf = new TCPDF('L', 'mm', 'LEGAL', true, 'UTF-8', false);
        $pdf->SetCreator('Smart Scheduling System');
        $pdf->SetAuthor('NORSU');
        $pdf->SetTitle('Subject Offerings Report');
        $pdf->SetSubject('Subject Offerings Catalog');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->AddPage();

        $tableWidth = 295;
        $startX = 20;

        // Header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetX($startX);
        $pdf->Cell($tableWidth, 8, 'NEGROS ORIENTAL STATE UNIVERSITY', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetX($startX);
        $pdf->Cell($tableWidth, 6, 'Smart Scheduling System', 0, 1, 'C');
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetX($startX);
        $pdf->Cell($tableWidth, 8, 'SUBJECT OFFERINGS REPORT', 0, 1, 'C');

        if ($departmentName) {
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetTextColor(0, 100, 0);
            $pdf->SetX($startX);
            $pdf->Cell($tableWidth, 7, 'Department: ' . $departmentName, 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }

        $filters = [];
        if ($year) $filters[] = 'Academic Year: ' . $year;
        if ($semester) $filters[] = 'Semester: ' . $semester;
        if ($searchTerm) $filters[] = 'Search: ' . $searchTerm;
        $filterText = !empty($filters) ? implode(' | ', $filters) : 'All Academic Years and Semesters';
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetX($startX);
        $pdf->Cell($tableWidth, 6, $filterText, 0, 1, 'C');
        $pdf->Ln(3);

        // Summary
        $totalSubjects = count($subjectsData);
        $totalSchedules = 0;
        $totalUnits = 0;
        foreach ($subjectsData as $data) {
            $subject = $data[0];
            $totalUnits += $subject->getUnits() ?? 0;
            $totalSchedules += count($data['schedules']);
        }

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $boxWidth = 65;
        $boxStartX = $startX + ($tableWidth - $boxWidth * 3) / 2;
        $pdf->SetX($boxStartX);
        $pdf->Cell($boxWidth, 7, 'Total Subjects', 1, 0, 'C', true);
        $pdf->Cell($boxWidth, 7, 'Total Schedules', 1, 0, 'C', true);
        $pdf->Cell($boxWidth, 7, 'Total Units', 1, 1, 'C', true);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetX($boxStartX);
        $pdf->Cell($boxWidth, 7, $totalSubjects, 1, 0, 'C');
        $pdf->Cell($boxWidth, 7, $totalSchedules, 1, 0, 'C');
        $pdf->Cell($boxWidth, 7, $totalUnits, 1, 1, 'C');
        $pdf->Ln(5);

        // Table header
        $colWidths = [28, 70, 12, 18, 36, 20, 22, 45, 22, 22];
        $colHeaders = ['Code', 'Subject Title', 'Units', 'Section', 'Time', 'Day', 'Room', 'Faculty', 'Year', 'Semester'];

        $this->drawHistoryTableHeader($pdf, $startX, $colWidths, $colHeaders);

        // Data rows
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(0, 0, 0);
        $rowBg = false;

        foreach ($subjectsData as $data) {
            $subject = $data[0];
            $schedules = $data['schedules'];

            if (empty($schedules)) {
                $schedules = [['section' => 'N/A', 'time' => 'N/A', 'day' => 'N/A', 'room' => 'N/A', 'faculty' => 'N/A', 'year' => '', 'semester' => '']];
            }

            foreach ($schedules as $sch) {
                $y = $pdf->GetY();
                $rowHeight = 6;
                $titleLines = $pdf->getNumLines($subject->getTitle() ?? '', 70);
                $rowHeight = max($rowHeight, $titleLines * 4);

                if ($y + $rowHeight > $pdf->getPageHeight() - 15) {
                    $pdf->AddPage();
                    $this->drawHistoryTableHeader($pdf, $startX, $colWidths, $colHeaders);
                    $pdf->SetFont('helvetica', '', 7);
                    $pdf->SetTextColor(0, 0, 0);
                    $y = $pdf->GetY();
                }

                if ($rowBg) {
                    $pdf->SetFillColor(248, 248, 248);
                } else {
                    $pdf->SetFillColor(255, 255, 255);
                }

                $x = $startX;
                $pdf->MultiCell($colWidths[0], $rowHeight, $subject->getCode() ?? '', 1, 'L', true, 0, $x, $y);
                $x += $colWidths[0];
                $pdf->MultiCell($colWidths[1], $rowHeight, $subject->getTitle() ?? '', 1, 'L', true, 0, $x, $y);
                $x += $colWidths[1];
                $pdf->MultiCell($colWidths[2], $rowHeight, $subject->getUnits() ?? 0, 1, 'C', true, 0, $x, $y);
                $x += $colWidths[2];
                $pdf->MultiCell($colWidths[3], $rowHeight, $sch['section'] ?? 'N/A', 1, 'C', true, 0, $x, $y);
                $x += $colWidths[3];
                $pdf->MultiCell($colWidths[4], $rowHeight, $sch['time'] ?? 'N/A', 1, 'C', true, 0, $x, $y);
                $x += $colWidths[4];
                $pdf->MultiCell($colWidths[5], $rowHeight, $sch['day'] ?? 'N/A', 1, 'C', true, 0, $x, $y);
                $x += $colWidths[5];
                $pdf->MultiCell($colWidths[6], $rowHeight, $sch['room'] ?? 'N/A', 1, 'C', true, 0, $x, $y);
                $x += $colWidths[6];
                $pdf->MultiCell($colWidths[7], $rowHeight, $sch['faculty'] ?? 'N/A', 1, 'L', true, 0, $x, $y);
                $x += $colWidths[7];
                $pdf->MultiCell($colWidths[8], $rowHeight, $sch['year'] ?? 'N/A', 1, 'C', true, 0, $x, $y);
                $x += $colWidths[8];
                $pdf->MultiCell($colWidths[9], $rowHeight, $sch['semester'] ?? 'N/A', 1, 'C', true, 1, $x, $y);

                $rowBg = !$rowBg;
            }
        }

        // Footer
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 5, 'Generated on: ' . date('F d, Y h:i A'), 0, 1, 'L');

        return $pdf->Output('', 'S');
    }

    private function drawHistoryTableHeader(TCPDF $pdf, float $startX, array $colWidths, array $colHeaders): void
    {
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(51, 122, 183);
        $pdf->SetTextColor(255, 255, 255);
        $x = $startX;
        foreach ($colWidths as $i => $w) {
            $isLast = ($i === count($colWidths) - 1);
            $pdf->Cell($w, 6, $colHeaders[$i], 1, $isLast ? 1 : 0, 'C', true);
        }
    }

    private function getSubjectsData(?string $year, ?string $semester, ?int $departmentId): array
    {
        // Get all subjects with their schedules
        $qb = $this->entityManager->getRepository(\App\Entity\Subject::class)
            ->createQueryBuilder('sub')
            ->leftJoin('sub.department', 'd')
            ->where('sub.deletedAt IS NULL')
            ->orderBy('d.name', 'ASC')
            ->addOrderBy('sub.code', 'ASC');

        if ($departmentId) {
            $qb->andWhere('d.id = :departmentId')
               ->setParameter('departmentId', $departmentId);
        }

        $subjects = $qb->getQuery()->getResult();

        // Get all schedules for reference
        $scheduleQb = $this->entityManager->getRepository(\App\Entity\Schedule::class)
            ->createQueryBuilder('s')
            ->select('s', 'sub', 'r', 'f', 'd', 'ay')
            ->leftJoin('s.subject', 'sub')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.faculty', 'f')
            ->leftJoin('sub.department', 'd')
            ->leftJoin('s.academicYear', 'ay');

        // Apply filters to schedules if provided
        if ($year) {
            $scheduleQb->andWhere('ay.year = :year')
                       ->setParameter('year', $year);
        }

        if ($semester) {
            $scheduleQb->andWhere('s.semester = :semester')
                       ->setParameter('semester', $semester);
        }
        
        // Also filter schedules by department if department is selected
        if ($departmentId) {
            $scheduleQb->andWhere('d.id = :deptId')
                       ->setParameter('deptId', $departmentId);
        }

        $schedules = $scheduleQb->getQuery()->getResult();

        // Build subjects data array
        $subjectsData = [];
        foreach ($subjects as $subject) {
            // Filter schedules for this subject
            $subjectSchedules = array_filter($schedules, function($s) use ($subject) {
                return $s->getSubject() && $s->getSubject()->getId() === $subject->getId();
            });

            // Get schedule details (time, day, room)
            $scheduleDetails = [];
            $years = [];
            $semesters = [];
            
            foreach ($subjectSchedules as $schedule) {
                $scheduleDetails[] = [
                    'section' => $schedule->getSection() ?: 'N/A',
                    'time' => $schedule->getStartTime()->format('h:i A') . ' - ' . $schedule->getEndTime()->format('h:i A'),
                    'day' => $schedule->getDayPattern(),
                    'room' => $schedule->getRoom() ? $schedule->getRoom()->getCode() : 'N/A',
                    'faculty' => $schedule->getFaculty() ? ($schedule->getFaculty()->getFirstName() . ' ' . $schedule->getFaculty()->getLastName()) : 'N/A'
                ];
                
                if ($schedule->getAcademicYear()) {
                    $years[] = $schedule->getAcademicYear()->getYear();
                }
                if ($schedule->getSemester()) {
                    $semesters[] = $schedule->getSemester();
                }
            }

            // Only include subjects that have schedules matching the filter criteria
            // Skip subjects with 0 matching schedules when filters are applied
            if (count($subjectSchedules) > 0 || (!$year && !$semester)) {
                $subjectsData[] = [
                    'subject' => $subject,
                    'schedules' => $scheduleDetails,
                    'years' => array_unique($years),
                    'semesters' => array_unique($semesters)
                ];
            }
        }

        return $subjectsData;
    }

    private function generateHeader(TCPDF $pdf, ?string $year, ?string $semester, ?int $departmentId): void
    {
        // Calculate table width (255mm total) and center position
        // Legal landscape: 355.6mm width, table is 255mm
        $tableWidth = 255;
        $startX = 20; // Left-aligned position
        
        // School header - centered to table
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetX($startX);
        $pdf->Cell($tableWidth, 8, 'NEGROS ORIENTAL STATE UNIVERSITY', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetX($startX);
        $pdf->Cell($tableWidth, 6, 'Smart Scheduling System', 0, 1, 'C');
        
        $pdf->Ln(3);
        
        // Report title
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetX($startX);
        $pdf->Cell($tableWidth, 8, 'SUBJECT OFFERINGS REPORT', 0, 1, 'C');
        
        // Department filter - prominently displayed
        if ($departmentId) {
            $dept = $this->entityManager->getRepository(\App\Entity\Department::class)->find($departmentId);
            if ($dept) {
                $pdf->SetFont('helvetica', 'B', 12);
                $pdf->SetTextColor(0, 100, 0);
                $pdf->SetX($startX);
                $pdf->Cell($tableWidth, 7, 'Department: ' . $dept->getName(), 0, 1, 'C');
                $pdf->SetTextColor(0, 0, 0);
            }
        }
        
        // Other filter information
        $pdf->SetFont('helvetica', '', 10);
        $filterText = '';
        
        if ($year || $semester) {
            $filters = [];
            if ($year) $filters[] = 'Academic Year: ' . $year;
            if ($semester) $filters[] = 'Semester: ' . $semester;
            $filterText = implode(' | ', $filters);
        } elseif (!$departmentId) {
            $filterText = 'All Academic Years, Semesters, and Departments';
        } else {
            $filterText = 'All Academic Years and Semesters';
        }
        
        if ($filterText) {
            $pdf->SetX($startX);
            $pdf->Cell($tableWidth, 6, $filterText, 0, 1, 'C');
        }
        $pdf->Ln(5);
    }

    private function generateSummary(TCPDF $pdf, array $subjectsData): void
    {
        // Calculate statistics
        $totalSubjects = count($subjectsData);
        $totalLecture = 0;
        $totalLab = 0;
        $totalUnits = 0;
        $totalSchedules = 0;

        foreach ($subjectsData as $data) {
            $subject = $data['subject'];
            if (strtolower($subject->getType()) === 'lecture') {
                $totalLecture++;
            } else {
                $totalLab++;
            }
            $totalUnits += $subject->getUnits();
            $totalSchedules += count($data['schedules']);
        }

        // Summary box - centered to match table and header
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(240, 240, 240);
        
        $boxWidth = 50;
        $totalBoxWidth = $boxWidth * 4; // 200mm
        $startX = 47.5; // Centered relative to table at 20mm
        
        $pdf->SetX($startX);
        $pdf->Cell($boxWidth, 8, 'Total Subjects', 1, 0, 'C', true);
        $pdf->Cell($boxWidth, 8, 'Lecture', 1, 0, 'C', true);
        $pdf->Cell($boxWidth, 8, 'Laboratory', 1, 0, 'C', true);
        $pdf->Cell($boxWidth, 8, 'Total Units', 1, 1, 'C', true);
        
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetX($startX);
        $pdf->Cell($boxWidth, 7, $totalSubjects, 1, 0, 'C');
        $pdf->Cell($boxWidth, 7, $totalLecture, 1, 0, 'C');
        $pdf->Cell($boxWidth, 7, $totalLab, 1, 0, 'C');
        $pdf->Cell($boxWidth, 7, $totalUnits, 1, 1, 'C');
        
        $pdf->Ln(5);
    }

    private function generateSubjectsTable(TCPDF $pdf, array $subjectsData): void
    {
        // Calculate table width and center position
        $tableWidth = 255;
        $startX = 20; // Left-aligned position
        
        // Group by department
        $groupedData = [];
        foreach ($subjectsData as $data) {
            $deptName = $data['subject']->getDepartment() ? $data['subject']->getDepartment()->getName() : 'No Department';
            $groupedData[$deptName][] = $data;
        }

        // Table headers
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(51, 122, 183);
        $pdf->SetTextColor(255, 255, 255);
        
        foreach ($groupedData as $deptName => $subjects) {
            // Department header
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(34, 139, 34);
            $pdf->SetX($startX);
            $pdf->Cell($tableWidth, 7, $deptName . ' (' . count($subjects) . ' subjects)', 1, 1, 'L', true);
            
            // Column headers - Compressed to fit: Total ~255mm
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetFillColor(51, 122, 183);
            $pdf->SetX($startX);
            $pdf->Cell(28, 6, 'Code', 1, 0, 'C', true);
            $pdf->Cell(65, 6, 'Subject Title', 1, 0, 'C', true);
            $pdf->Cell(10, 6, 'Units', 1, 0, 'C', true);
            $pdf->Cell(22, 6, 'Type', 1, 0, 'C', true);
            $pdf->Cell(38, 6, 'Time', 1, 0, 'C', true);
            $pdf->Cell(20, 6, 'Day', 1, 0, 'C', true);
            $pdf->Cell(22, 6, 'Room', 1, 0, 'C', true);
            $pdf->Cell(50, 6, 'Faculty', 1, 1, 'C', true);
            
            // Data rows
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFillColor(255, 255, 255);
            
            foreach ($subjects as $data) {
                $subject = $data['subject'];
                $schedules = $data['schedules'];
                
                // If no schedules, show one row with N/A
                if (empty($schedules)) {
                    $schedules = [['section' => 'N/A', 'time' => 'N/A', 'day' => 'N/A', 'room' => 'N/A', 'faculty' => 'N/A']];
                }
                
                // Show each schedule on a separate row
                foreach ($schedules as $schedule) {
                    $rowHeight = 6;
                    $titleLines = $pdf->getNumLines($subject->getTitle(), 65);
                    $rowHeight = max($rowHeight, $titleLines * 4);
                    
                    $y = $pdf->GetY();
                    
                    // Check if we need a new page
                    if ($y + $rowHeight > $pdf->getPageHeight() - 20) {
                        $pdf->AddPage();
                        // Repeat headers
                        $pdf->SetFont('helvetica', 'B', 7);
                        $pdf->SetFillColor(51, 122, 183);
                        $pdf->SetTextColor(255, 255, 255);
                        $pdf->SetX($startX);
                        $pdf->Cell(28, 6, 'Code', 1, 0, 'C', true);
                        $pdf->Cell(65, 6, 'Subject Title', 1, 0, 'C', true);
                        $pdf->Cell(10, 6, 'Units', 1, 0, 'C', true);
                        $pdf->Cell(22, 6, 'Type', 1, 0, 'C', true);
                        $pdf->Cell(38, 6, 'Time', 1, 0, 'C', true);
                        $pdf->Cell(20, 6, 'Day', 1, 0, 'C', true);
                        $pdf->Cell(22, 6, 'Room', 1, 0, 'C', true);
                        $pdf->Cell(50, 6, 'Faculty', 1, 1, 'C', true);
                        $pdf->SetFont('helvetica', '', 7);
                        $pdf->SetTextColor(0, 0, 0);
                        $y = $pdf->GetY();
                    }
                    
                    // Code with section (e.g., "ITS 100 - A")
                    $codeWithSection = $subject->getCode() . ' - ' . $schedule['section'];
                    $pdf->MultiCell(28, $rowHeight, $codeWithSection, 1, 'L', false, 0, $startX, $y);
                    $pdf->MultiCell(65, $rowHeight, $subject->getTitle(), 1, 'L', false, 0, $startX + 28, $y);
                    $pdf->MultiCell(10, $rowHeight, $subject->getUnits(), 1, 'C', false, 0, $startX + 93, $y);
                    $pdf->MultiCell(22, $rowHeight, ucfirst($subject->getType()), 1, 'C', false, 0, $startX + 103, $y);
                    
                    // Schedule details
                    $pdf->MultiCell(38, $rowHeight, $schedule['time'], 1, 'C', false, 0, $startX + 125, $y);
                    $pdf->MultiCell(20, $rowHeight, $schedule['day'], 1, 'C', false, 0, $startX + 163, $y);
                    $pdf->MultiCell(22, $rowHeight, $schedule['room'], 1, 'C', false, 0, $startX + 183, $y);
                    $pdf->MultiCell(50, $rowHeight, $schedule['faculty'], 1, 'L', false, 1, $startX + 205, $y);
                }
            }
            
            $pdf->Ln(3);
        }
    }

    private function generateFooter(TCPDF $pdf): void
    {
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 5, 'Generated on: ' . date('F d, Y h:i A'), 0, 1, 'L');
        $pdf->Cell(0, 5, 'Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 1, 'R');
    }
}
