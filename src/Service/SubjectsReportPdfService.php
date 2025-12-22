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
        $pdf = new TCPDF('L', PDF_UNIT, 'LEGAL', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Smart Scheduling System');
        $pdf->SetAuthor('NORSU');
        $pdf->SetTitle('Subject Offerings Report');
        $pdf->SetSubject('Subject Offerings Catalog');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(10, 10, 10);
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
            ->select('s', 'sub', 'f', 'd', 'ay')
            ->leftJoin('s.subject', 'sub')
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

            // Get unique faculty teaching this subject
            $facultyList = [];
            $years = [];
            $semesters = [];
            
            foreach ($subjectSchedules as $schedule) {
                if ($schedule->getFaculty()) {
                    $faculty = $schedule->getFaculty();
                    $facultyList[$faculty->getId()] = $faculty->getFirstName() . ' ' . $faculty->getLastName();
                }
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
                    'timesOffered' => count($subjectSchedules),
                    'facultyCount' => count($facultyList),
                    'facultyList' => array_values($facultyList),
                    'years' => array_unique($years),
                    'semesters' => array_unique($semesters)
                ];
            }
        }

        return $subjectsData;
    }

    private function generateHeader(TCPDF $pdf, ?string $year, ?string $semester, ?int $departmentId): void
    {
        // School header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 8, 'NEGROS ORIENTAL STATE UNIVERSITY', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 6, 'Smart Scheduling System', 0, 1, 'C');
        
        $pdf->Ln(3);
        
        // Report title
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'SUBJECT OFFERINGS REPORT', 0, 1, 'C');
        
        // Department filter - prominently displayed
        if ($departmentId) {
            $dept = $this->entityManager->getRepository(\App\Entity\Department::class)->find($departmentId);
            if ($dept) {
                $pdf->SetFont('helvetica', 'B', 12);
                $pdf->SetTextColor(0, 100, 0);
                $pdf->Cell(0, 7, 'Department: ' . $dept->getName(), 0, 1, 'C');
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
            $pdf->Cell(0, 6, $filterText, 0, 1, 'C');
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
        $totalOfferings = 0;

        foreach ($subjectsData as $data) {
            $subject = $data['subject'];
            if (strtolower($subject->getType()) === 'lecture') {
                $totalLecture++;
            } else {
                $totalLab++;
            }
            $totalUnits += $subject->getUnits();
            $totalOfferings += $data['timesOffered'];
        }

        // Summary box
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(240, 240, 240);
        
        $boxWidth = 50;
        $startX = ($pdf->getPageWidth() - ($boxWidth * 4)) / 2;
        
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
            $pdf->Cell(0, 7, $deptName . ' (' . count($subjects) . ' subjects)', 1, 1, 'L', true);
            
            // Column headers
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(51, 122, 183);
            $pdf->Cell(30, 6, 'Code', 1, 0, 'C', true);
            $pdf->Cell(80, 6, 'Subject Title', 1, 0, 'C', true);
            $pdf->Cell(15, 6, 'Units', 1, 0, 'C', true);
            $pdf->Cell(20, 6, 'Type', 1, 0, 'C', true);
            $pdf->Cell(20, 6, 'Offered', 1, 0, 'C', true);
            $pdf->Cell(20, 6, 'Faculty', 1, 0, 'C', true);
            $pdf->Cell(0, 6, 'Faculty Assigned', 1, 1, 'C', true);
            
            // Data rows
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFillColor(255, 255, 255);
            
            foreach ($subjects as $data) {
                $subject = $data['subject'];
                $facultyNames = !empty($data['facultyList']) ? implode(', ', array_slice($data['facultyList'], 0, 3)) : 'Not Assigned';
                if (count($data['facultyList']) > 3) {
                    $facultyNames .= '...';
                }
                
                // Calculate row height based on content
                $rowHeight = 6;
                $titleLines = $pdf->getNumLines($subject->getTitle(), 80);
                $facultyLines = $pdf->getNumLines($facultyNames, 0);
                $rowHeight = max($rowHeight, max($titleLines, $facultyLines) * 4);
                
                $x = $pdf->GetX();
                $y = $pdf->GetY();
                
                // Check if we need a new page
                if ($y + $rowHeight > $pdf->getPageHeight() - 20) {
                    $pdf->AddPage();
                    // Repeat headers
                    $pdf->SetFont('helvetica', 'B', 8);
                    $pdf->SetFillColor(51, 122, 183);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->Cell(30, 6, 'Code', 1, 0, 'C', true);
                    $pdf->Cell(80, 6, 'Subject Title', 1, 0, 'C', true);
                    $pdf->Cell(15, 6, 'Units', 1, 0, 'C', true);
                    $pdf->Cell(20, 6, 'Type', 1, 0, 'C', true);
                    $pdf->Cell(20, 6, 'Offered', 1, 0, 'C', true);
                    $pdf->Cell(20, 6, 'Faculty', 1, 0, 'C', true);
                    $pdf->Cell(0, 6, 'Faculty Assigned', 1, 1, 'C', true);
                    $pdf->SetFont('helvetica', '', 8);
                    $pdf->SetTextColor(0, 0, 0);
                    $y = $pdf->GetY();
                }
                
                // Draw cells
                $pdf->MultiCell(30, $rowHeight, $subject->getCode(), 1, 'L', false, 0, $x, $y);
                $pdf->MultiCell(80, $rowHeight, $subject->getTitle(), 1, 'L', false, 0, $x + 30, $y);
                $pdf->MultiCell(15, $rowHeight, $subject->getUnits(), 1, 'C', false, 0, $x + 110, $y);
                $pdf->MultiCell(20, $rowHeight, ucfirst($subject->getType()), 1, 'C', false, 0, $x + 125, $y);
                $pdf->MultiCell(20, $rowHeight, $data['timesOffered'] . 'x', 1, 'C', false, 0, $x + 145, $y);
                $pdf->MultiCell(20, $rowHeight, $data['facultyCount'], 1, 'C', false, 0, $x + 165, $y);
                $pdf->MultiCell(0, $rowHeight, $facultyNames, 1, 'L', false, 1, $x + 185, $y);
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
