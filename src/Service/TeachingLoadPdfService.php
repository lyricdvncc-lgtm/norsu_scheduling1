<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\AcademicYear;
use App\Repository\ScheduleRepository;
use TCPDF;

class TeachingLoadPdfService
{
    private ScheduleRepository $scheduleRepository;

    public function __construct(ScheduleRepository $scheduleRepository)
    {
        $this->scheduleRepository = $scheduleRepository;
    }

    public function generateTeachingLoadPdf(User $faculty, AcademicYear $academicYear, ?string $semester = null): string
    {
        // Create new PDF document in portrait mode using Legal size (long bond paper)
        $pdf = new TCPDF('P', PDF_UNIT, 'LEGAL', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Smart Scheduling System');
        $pdf->SetAuthor('NORSU');
        $pdf->SetTitle('Individual Teaching Load - ' . $faculty->getFirstName() . ' ' . $this->getMiddleInitial($faculty) . $faculty->getLastName());
        $pdf->SetSubject('Teaching Load Report');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(12.7, 8, 12.7); // left, top, right (0.5 inch margins)
        $pdf->SetAutoPageBreak(TRUE, 8); // bottom margin

        // Add a page
        $pdf->AddPage();

        // Get schedules for this faculty
        $schedules = $this->getSchedulesForFaculty($faculty, $academicYear, $semester);

        // Calculate totals
        $totals = $this->calculateTotals($schedules, $semester);

        // Generate the PDF content
        $this->generateHeader($pdf, $totals['semester'], $academicYear);
        $this->generateInfoBox($pdf, $faculty);
        $this->generateScheduleTable($pdf, $schedules, $totals);
        $this->generateBottomSection($pdf, $schedules, $totals, $faculty);
        $this->generateFooterSection($pdf);

        // Return PDF as string
        return $pdf->Output('', 'S');
    }

    private function getSchedulesForFaculty(User $faculty, AcademicYear $academicYear, ?string $semester = null): array
    {
        $qb = $this->scheduleRepository->createQueryBuilder('s')
            ->leftJoin('s.subject', 'sub')
            ->leftJoin('s.room', 'r')
            ->leftJoin('s.faculty', 'f')
            ->where('f.id = :facultyId')
            ->andWhere('s.academicYear = :academicYear')
            ->setParameter('facultyId', $faculty->getId())
            ->setParameter('academicYear', $academicYear);
        
        // Filter by semester if provided
        if ($semester !== null) {
            $qb->andWhere('s.semester = :semester')
               ->setParameter('semester', $semester);
        }
        
        $schedules = $qb->orderBy('s.dayPattern', 'ASC')
            ->addOrderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        return $schedules;
    }

    private function calculateTotals(array $schedules, ?string $semester = null): array
    {
        $totalUnits = 0;
        $totalHours = 0;
        $totalStudents = 0;
        $semesterFromSchedules = null;
        $semesters = [];

        foreach ($schedules as $schedule) {
            $totalUnits += $schedule->getSubject()->getUnits();
            
            // Calculate hours based on start and end time
            $start = $schedule->getStartTime();
            $end = $schedule->getEndTime();
            $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
            
            // Multiply by number of days in the pattern
            $daysPerWeek = count($schedule->getDaysFromPattern());
            $totalHours += ($hours * $daysPerWeek);
            
            $totalStudents += $schedule->getEnrolledStudents();
            
            // Collect all unique semesters
            $scheduleSemester = $schedule->getSemester();
            if ($scheduleSemester && !in_array($scheduleSemester, $semesters)) {
                $semesters[] = $scheduleSemester;
            }
        }

        // Use the provided semester parameter if available, otherwise determine from schedules
        if ($semester !== null) {
            $semesterFromSchedules = $semester;
        } elseif (count($semesters) === 1) {
            // All subjects are in one semester
            $semesterFromSchedules = $semesters[0];
        } elseif (count($semesters) > 1) {
            // Mixed semesters - combine them (e.g., "1 & 2")
            sort($semesters);
            $semesterFromSchedules = implode(' & ', $semesters);
        } else {
            // No semester found - default to 1st semester
            $semesterFromSchedules = '1';
        }

        return [
            'totalUnits' => $totalUnits,
            'totalHours' => $totalHours,
            'totalStudents' => $totalStudents,
            'semester' => $semesterFromSchedules
        ];
    }

    private function generateHeader(TCPDF $pdf, ?string $semester, AcademicYear $academicYear): void
    {
        // Add the three header logos
        $logoPath1 = __DIR__ . '/../../public/images/loadform/headers.png';  // Main university header
        $logoPath2 = __DIR__ . '/../../public/images/loadform/middlelogo1.png';     // GCL/ISO certification
        $logoPath3 = __DIR__ . '/../../public/images/loadform/logo2.jpg';     // SASTE TAI logo

        $logoHeight = 20;
        
        // Position the three logos across the header
        // Logo 1: NORSU header - limit width to avoid overlap
        if (file_exists($logoPath1)) {
            $pdf->Image($logoPath1, 12.7, 9.5, 140, $logoHeight, '', '', '', true, 150);
        }
        // Logo 2: GCL/ISO certification (positioned after headers.png ends, slightly higher)
        if (file_exists($logoPath2)) {
            $pdf->Image($logoPath2, 155, 7, 0, $logoHeight, '', '', '', true, 150);
        }
        // Logo 3: SASTE TAI (right side)
        if (file_exists($logoPath3)) {
            $pdf->Image($logoPath3, 177, 8, 0, $logoHeight, '', '', '', true, 150);
        }

        $pdf->SetY(30 );

        // Office title
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 5, 'Office of the Dean, College of College of Arts and Sciences', 0, 1, 'C');

        // Document title
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 5, 'INDIVIDUAL TEACHING LOAD', 0, 1, 'C');

        // Semester info
        $pdf->SetFont('times', '', 11);
        
        // Check if semester starts with '1' (handles '1', '1st', '1st Semest', '1st Semester', etc.)
        if (preg_match('/^1/', $semester)) {
            $semesterHtml = '<div style="text-align: center;">1<sup>st</sup> Semester, School Year ' . $academicYear->getYear() . '</div>';
        } elseif (preg_match('/^2/', $semester)) {
            $semesterHtml = '<div style="text-align: center;">2<sup>nd</sup> Semester, School Year ' . $academicYear->getYear() . '</div>';
        } else {
            // No consistent semester or no subjects
            $semesterHtml = '<div style="text-align: center;">School Year ' . $academicYear->getYear() . '</div>';
        }
        
        $pdf->writeHTML($semesterHtml, true, false, true, false, '');
        
        $pdf->Ln(3);
    }

    private function generateInfoBox(TCPDF $pdf, User $faculty): void
    {
        $pdf->SetFont('times', '', 11);
        
        // Name row
        $y = $pdf->GetY();
        $pdf->SetXY(12.7, $y);
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(20, 5, 'Name:', 0, 0, 'L');
        $pdf->SetFont('times', '', 11);
        $pdf->Cell(75, 5, strtoupper($faculty->getFirstName() . ' ' . $this->getMiddleInitial($faculty) . ' ' . $faculty->getLastName()), 'B', 0, 'L');
        
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(27, 5, 'Acad. Rank:', 0, 0, 'L');
        $pdf->SetFont('times', '', 11);
        $pdf->Cell(62, 5, strtoupper($faculty->getPosition() ?: 'PART-TIME'), 'B', 1, 'L');

        // Address row
        $y = $pdf->GetY();
        $pdf->SetXY(12.7, $y);
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(20, 5, 'Address:', 0, 0, 'L');
        $pdf->SetFont('times', '', 11);
        $pdf->Cell(75, 5, strtoupper($faculty->getAddress() ?: ''), 'B', 0, 'L');
        
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(27, 5, 'Department:', 0, 0, 'L');
        $pdf->SetFont('times', '', 10);
        $departmentName = $faculty->getDepartment() ? $faculty->getDepartment()->getName() : '';
        $pdf->Cell(62, 5, strtoupper($departmentName), 'B', 1, 'L');

        $pdf->Ln(3);
    }

    private function generateScheduleTable(TCPDF $pdf, array $schedules, array $totals): void
    {
        // Set thin border for table cells
        $pdf->SetLineWidth(0.1);
        
        // Define column widths - total available width is page width minus margins
        // Legal paper = 215.9mm, margins = 12.7mm each side, so available = 190.5mm
        $colWidths = [17, 26, 24, 47, 20, 18, 22, 16.5]; // Total = 190.5mm exactly
        $startX = 12.7; // Align to left margin (same as margins)
        
        // Table header with advanced formatting
        $pdf->SetFont('times', 'B', 9);
        $pdf->SetFillColor(255, 255, 255);
        
        $headerY = $pdf->GetY();
        
        // Calculate required height for multi-line headers
        $maxHeaderLines = max(
            $pdf->getNumLines('DAY', $colWidths[0]),
            $pdf->getNumLines('TIME', $colWidths[1]),
            $pdf->getNumLines("Course Code\nwith Section", $colWidths[2]),
            $pdf->getNumLines('Course Description', $colWidths[3]),
            $pdf->getNumLines('ROOM', $colWidths[4]),
            $pdf->getNumLines("NO. of\nUNITS\nHANDLED", $colWidths[5]),
            $pdf->getNumLines("NO. of\nCONTACT\nHOURS per\nWEEK", $colWidths[6]),
            $pdf->getNumLines("NO. of\nSTUD.", $colWidths[7])
        );
        $headerHeight = max($maxHeaderLines * 4, 14); // Auto-adjust based on content with better spacing
        
        // Draw header using MultiCell for perfect text wrapping and vertical centering
        $x = $startX;
        $pdf->MultiCell($colWidths[0], $headerHeight, 'DAY', 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[0];
        $pdf->MultiCell($colWidths[1], $headerHeight, 'TIME', 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[1];
        $pdf->MultiCell($colWidths[2], $headerHeight, "Course Code\nwith Section", 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[2];
        $pdf->MultiCell($colWidths[3], $headerHeight, 'Course Description', 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[3];
        $pdf->MultiCell($colWidths[4], $headerHeight, 'ROOM', 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[4];
        $pdf->MultiCell($colWidths[5], $headerHeight, "NO. of\nUNITS\nHANDLED", 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[5];
        $pdf->MultiCell($colWidths[6], $headerHeight, "NO. of\nCONTACT\nHOURS per\nWEEK", 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[6];
        $pdf->MultiCell($colWidths[7], $headerHeight, "NO. of\nSTUD.", 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        
        $pdf->SetY($headerY + $headerHeight);

        // Table body with advanced formatting
        $pdf->SetFont('times', '', 10);
        
        if (empty($schedules)) {
            $totalWidth = array_sum($colWidths);
            $pdf->Cell($totalWidth, 20, 'No teaching load assigned for this academic year', 1, 1, 'C');
        } else {
            // Draw schedule rows with auto-adjusting heights
            foreach ($schedules as $schedule) {
                $rowY = $pdf->GetY();
                
                // Get day pattern (already formatted with hyphens like M-W-F, T-TH)
                $dayPattern = $schedule->getDayPattern();
                $timeText = $schedule->getStartTime()->format('g:i') . '-' . $schedule->getEndTime()->format('g:i A');
                $courseCode = $schedule->getSubject()->getCode() . ' ' . $schedule->getSection();
                $courseTitle = $schedule->getSubject()->getTitle();
                $roomName = $schedule->getRoom()->getCode();
                $units = (string)$schedule->getSubject()->getUnits();
                $students = (string)$schedule->getEnrolledStudents();
                
                // Calculate required height based on all cells
                $maxLines = max(
                    $pdf->getNumLines($dayPattern, $colWidths[0]),
                    $pdf->getNumLines($timeText, $colWidths[1]),
                    $pdf->getNumLines($courseCode, $colWidths[2]),
                    $pdf->getNumLines($courseTitle, $colWidths[3]),
                    $pdf->getNumLines($roomName, $colWidths[4]),
                    1
                );
                
                $rowHeight = max($maxLines * 4.5, 6);
                
                // Draw each cell with MultiCell for automatic wrapping and vertical centering
                $x = $startX;
                $pdf->MultiCell($colWidths[0], $rowHeight, $dayPattern, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[0];
                $pdf->MultiCell($colWidths[1], $rowHeight, $timeText, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[1];
                $pdf->MultiCell($colWidths[2], $rowHeight, $courseCode, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[2];
                $pdf->MultiCell($colWidths[3], $rowHeight, $courseTitle, 1, 'L', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[3];
                $pdf->MultiCell($colWidths[4], $rowHeight, $roomName, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[4];
                $pdf->MultiCell($colWidths[5], $rowHeight, $units, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[5];
                $pdf->MultiCell($colWidths[6], $rowHeight, $units, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[6];
                $pdf->MultiCell($colWidths[7], $rowHeight, $students, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                
                $pdf->SetY($rowY + $rowHeight);
            }

            // Total row - NO merged cells, TOTAL text in Description column only
            $pdf->SetFont('times', 'B', 8);
            $pdf->SetFillColor(211, 211, 211); // Gray background
            
            $totalY = $pdf->GetY();
            $totalHeight = 6;
            
            $x = $startX;
            // Empty cell with border - Day column (no right border)
            $pdf->MultiCell($colWidths[0], $totalHeight, '', 'LTB', 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[0];
            // Empty cell with border - Time column (no right border)
            $pdf->MultiCell($colWidths[1], $totalHeight, '', 'TB', 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[1];
            // TOTAL text in Course Code with Section column (no right border)
            $pdf->MultiCell($colWidths[2], $totalHeight, 'TOTAL', 'TB', 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[2];
            // Empty cell with border - Description column
            $pdf->MultiCell($colWidths[3], $totalHeight, '', 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[3];
            // Empty cell with border - Room column
            $pdf->MultiCell($colWidths[4], $totalHeight, '', 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[4];
            // Total values
            $pdf->MultiCell($colWidths[5], $totalHeight, (string)$totals['totalUnits'], 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[5];
            $pdf->MultiCell($colWidths[6], $totalHeight, (string)$totals['totalUnits'], 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[6];
            $pdf->MultiCell($colWidths[7], $totalHeight, (string)$totals['totalStudents'], 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            
            $pdf->SetY($totalY + $totalHeight);

            // Consultation Period row - NO merged cells, maintain vertical grid lines
            $pdf->SetFont('times', '', 8);
            $pdf->SetFillColor(255, 255, 255);
            
            $consultY = $pdf->GetY();
            $consultHeight = 8;
            
            $x = $startX;
            // Consultation Period label
            $pdf->MultiCell($colWidths[0], $consultHeight, 'Consultation Period', 1, 'L', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[0];
            // Time in bold
            $pdf->SetFont('times', 'B', 8);
            $pdf->MultiCell($colWidths[1], $consultHeight, 'MWF 10-11 PM', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $pdf->SetFont('times', '', 8);
            $x += $colWidths[1];
            // Individual empty cells to maintain vertical grid lines
            $pdf->MultiCell($colWidths[2], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[2];
            $pdf->MultiCell($colWidths[3], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[3];
            $pdf->MultiCell($colWidths[4], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[4];
            $pdf->MultiCell($colWidths[5], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[5];
            $pdf->MultiCell($colWidths[6], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[6];
            $pdf->MultiCell($colWidths[7], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            
            $pdf->SetY($consultY + $consultHeight);
        }

        $pdf->Ln(2);
    }

    private function generateBottomSection(TCPDF $pdf, array $schedules, array $totals, User $faculty): void
    {
        $pdf->SetFont('times', '', 11);
        
        // Count unique subjects (preparations)
        $uniqueSubjects = [];
        foreach ($schedules as $schedule) {
            $subjectCode = $schedule->getSubject()->getCode();
            if (!in_array($subjectCode, $uniqueSubjects)) {
                $uniqueSubjects[] = $subjectCode;
            }
        }
        $numberOfPreparations = count($uniqueSubjects);
        
        // Define column widths for 2-column layout
        $leftColWidth = 95;  // Left column width
        $rightColWidth = 95; // Right column width (remaining space)
        $rowHeight = 7;      // Row height with spacing
        
        // Row 1: No. of Preparation (spans both columns)
        $pdf->Cell($leftColWidth + $rightColWidth, $rowHeight, 'No. of Preparation: ' . $numberOfPreparations, 0, 1, 'L');
        
        // Row 2: Total Units (left column only)
        $pdf->Cell($leftColWidth, $rowHeight, 'Total No. of Units/Week: ' . $totals['totalUnits'], 0, 1, 'L');
        
        // Row 3: Total Hours (left) and Total Students (right)
        $pdf->Cell($leftColWidth, $rowHeight, 'Total No. of Hrs./Week: ' . number_format($totals['totalHours'], 0), 0, 0, 'L');
        $pdf->Cell($rightColWidth, $rowHeight, 'Total No. of Students: ' . $totals['totalStudents'], 0, 1, 'R');
        
        // Row 4: Other Designation (spans both columns)
        $pdf->Cell(0, $rowHeight, 'Other Designation/Special Assignments:', 0, 1, 'L');
        
        // Row 5: Highest Educational Attainment (spans both columns) - with fillable field
        $y = $pdf->GetY();
        $pdf->Cell(55, $rowHeight, 'Highest Educational Attainment: ', 0, 0, 'L');
        $x = $pdf->GetX();
        // Add fillable text field for Educational Attainment
        $pdf->TextField('educational_attainment', 135, $rowHeight, ['strokeColor' => [200, 200, 200], 'lineWidth' => 0.3], [], $x, $y);
        $pdf->Ln($rowHeight);
        
        $pdf->Ln(3);
        $pdf->Cell(0, 5, 'Certified Correct:', 0, 1, 'L');
        
        $pdf->Ln(5);
        $pdf->SetFont('times', 'B', 11);
        
        // Calculate center position for name
        $nameWidth = 65; // approximate width for the name area
        $startX = 15;
        $pdf->SetX($startX);
        $pdf->Cell($nameWidth, 5, strtoupper($faculty->getFirstName() . ' ' . $this->getMiddleInitial($faculty) . $faculty->getLastName()), 0, 1, 'C');
        
        $pdf->SetFont('times', '', 11);
        $pdf->SetLineWidth(0.3);
        $lineY = $pdf->GetY();
        $pdf->Line($startX, $lineY, $startX + $nameWidth, $lineY);
        
        $pdf->SetX($startX);
        $pdf->Cell($nameWidth, 5, 'Faculty', 0, 1, 'C');
        
        $pdf->Ln(8);
    }

    private function generateFooterSection(TCPDF $pdf): void
    {
        // Calculate footer position from the bottom of the page
        // Legal paper height is 355.6mm, with bottom margin of 8mm
        $pageHeight = $pdf->getPageHeight();
        $bottomMargin = 8;
        
        // Footer table dimensions
        $footerTableHeight = 12; // 3 rows * 4mm
        $gapBeforeTable = 2;
        
        // Calculate starting Y position (work backwards from bottom)
        $footerTableY = $pageHeight - $bottomMargin - $footerTableHeight;
        $presidentSectionY = $footerTableY - $gapBeforeTable - 25; // President section needs ~25mm
        $signatureStartY = $presidentSectionY - 25; // Two-column signatures need ~25mm
        
        $pdf->SetFont('times', '', 11);
        
        // Signature section at calculated Y position
        $pdf->SetY($signatureStartY);
        
        // Left side - Attested by (centered at calculated position)
        $pdf->SetXY(15, $signatureStartY);
        $pdf->Cell(90, 5, 'Attested by:', 0, 0, 'C');
        
        // Right side - Recommending Approval (centered at calculated position)
        $pdf->SetXY(110, $signatureStartY);
        $pdf->Cell(85, 5, 'Recommending Approval:', 0, 1, 'C');
        
        // Names at calculated position
        $nameY = $signatureStartY + 10;
        $pdf->SetFont('times', 'B', 11);
        $pdf->SetXY(15, $nameY);
        $pdf->Cell(90, 5, 'DR. PRISCILLA S. CIELO', 0, 0, 'C');
        
        $pdf->SetXY(110, $nameY);
        $pdf->Cell(85, 5, 'ROSE MARIE T. PINILI, E.d., Ph.D.', 0, 1, 'C');
        
        // Draw lines under names at calculated position
        $pdf->SetLineWidth(0.3);
        $lineY = $nameY + 5;
        $pdf->Line(25, $lineY, 95, $lineY); // Left line
        $pdf->Line(120, $lineY, 185, $lineY); // Right line
        
        // Titles at calculated position
        $pdf->SetFont('times', '', 11);
        $titleY = $lineY + 1;
        $pdf->SetXY(15, $titleY);
        $pdf->Cell(90, 5, 'Dean', 0, 0, 'C');
        $pdf->SetXY(110, $titleY);
        $pdf->Cell(85, 5, 'Vice President for Academic Affairs', 0, 1, 'C');
        
        // Approved section at calculated position
        $approvedY = $titleY + 10;
        $pdf->SetXY(0, $approvedY);
        $pdf->Cell(0, 5, 'Approved:', 0, 1, 'C');
        
        $pdf->SetFont('times', 'B', 11);
        $approvedNameY = $approvedY + 10;
        $pdf->SetXY(0, $approvedNameY);
        $pdf->Cell(0, 5, 'Hon. NOEL MARJON E. YASI, Psy.D.', 0, 1, 'C');
        
        // Draw line under president name at calculated position
        $approvedLineY = $approvedNameY + 5;
        $pdf->Line(($pdf->getPageWidth() - 130) / 2, $approvedLineY, ($pdf->getPageWidth() + 130) / 2, $approvedLineY);
        
        $pdf->SetFont('times', '', 11);
        $pdf->SetXY(0, $approvedLineY + 1);
        $pdf->Cell(0, 5, 'University President', 0, 1, 'C');
        
        // Footer table - always at the absolute bottom
        $this->generateFooterTable($pdf, $footerTableY);
    }

    private function generateFooterTable(TCPDF $pdf, float $footerTableY): void
    {
        // Set thin border for footer table
        $pdf->SetLineWidth(0.2);
        $pdf->SetFont('times', 'B', 8);
        
        // Position the table at the specified Y position (calculated from bottom)
        $bottomY = $footerTableY;
        
        // Calculate center position
        $tableWidth = 180;
        $startX = ($pdf->getPageWidth() - $tableWidth) / 2;
        
        // Column widths
        $col1 = 35;  // Label column
        $col2 = 50;  // Value column 1
        $col3 = 30;  // Label column 2
        $col4 = 35;  // Value column 2
        $col5 = 30;  // Merged cell on right
        
        $rowHeight = 4;
        
        // Row 1
        $pdf->SetXY($startX, $bottomY);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->MultiCell($col1, $rowHeight, 'Form ID', 1, 'L', false, 0, $startX, $bottomY, true, 0, false, true, $rowHeight, 'M');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell($col2 + $col3 + $col4, $rowHeight, 'DGAM-OTUP-OCAS-F01-000C', 1, 'L', false, 0, $startX + $col1, $bottomY, true, 0, false, true, $rowHeight, 'M');
        
        // Merged cell on right (spans all 3 rows)
        $pdf->MultiCell($col5, $rowHeight * 3, '', 1, 'C', false, 1, $startX + $col1 + $col2 + $col3 + $col4, $bottomY, true, 0, false, true, $rowHeight * 3, 'M');
        
        // Row 2
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->MultiCell($col1, $rowHeight, 'Issue Date', 1, 'L', false, 0, $startX, $bottomY + $rowHeight, true, 0, false, true, $rowHeight, 'M');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell($col2, $rowHeight, '', 1, 'L', false, 0, $startX + $col1, $bottomY + $rowHeight, true, 0, false, true, $rowHeight, 'M');
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->MultiCell($col3, $rowHeight, 'Issue Status', 1, 'L', false, 0, $startX + $col1 + $col2, $bottomY + $rowHeight, true, 0, false, true, $rowHeight, 'M');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell($col4, $rowHeight, '2', 1, 'L', false, 1, $startX + $col1 + $col2 + $col3, $bottomY + $rowHeight, true, 0, false, true, $rowHeight, 'M');
        
        // Row 3
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->MultiCell($col1, $rowHeight, 'Reviewed & Authorized by', 1, 'L', false, 0, $startX, $bottomY + ($rowHeight * 2), true, 0, false, true, $rowHeight, 'M');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell($col2, $rowHeight, 'QVPAA', 1, 'L', false, 0, $startX + $col1, $bottomY + ($rowHeight * 2), true, 0, false, true, $rowHeight, 'M');
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->MultiCell($col3, $rowHeight, 'Approved by', 1, 'L', false, 0, $startX + $col1 + $col2, $bottomY + ($rowHeight * 2), true, 0, false, true, $rowHeight, 'M');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell($col4, $rowHeight, 'OTUP', 1, 'L', false, 1, $startX + $col1 + $col2 + $col3, $bottomY + ($rowHeight * 2), true, 0, false, true, $rowHeight, 'M');
    }

    /**
     * Get the middle initial from the faculty's middle name
     */
    private function getMiddleInitial(User $faculty): string
    {
        $middleName = $faculty->getMiddleName();
        if ($middleName && strlen(trim($middleName)) > 0) {
            return strtoupper(substr(trim($middleName), 0, 1)) . '. ';
        }
        return '';
    }
}
