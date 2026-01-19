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
        $this->generateHeader($pdf, $totals['semester'], $academicYear, $faculty);
        $this->generateInfoBox($pdf, $faculty);
        $this->generateScheduleTable($pdf, $schedules, $totals);
        $this->generateBottomSection($pdf, $schedules, $totals, $faculty);
        $this->generateFooterSection($pdf, $faculty);

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
        $totalContactHours = 0;
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
            
            // Calculate contact hours per week
            // For Saturday-only or Sunday-only classes, contact hours = 5
            // For other patterns, contact hours = units
            $dayPattern = $schedule->getDayPattern();
            $normalizedPattern = strtoupper(trim($dayPattern));
            if ($normalizedPattern === 'SAT' || $normalizedPattern === 'SATURDAY' || 
                $normalizedPattern === 'SUN' || $normalizedPattern === 'SUNDAY') {
                $totalContactHours += 5;
            } else {
                $totalContactHours += $schedule->getSubject()->getUnits();
            }
            
            // Calculate max capacity for this schedule
            // If subject has lab hours > 0, max capacity is 35, otherwise 40
            $maxCapacity = ($schedule->getSubject()->getLabHours() > 0) ? 35 : 40;
            $totalStudents += $maxCapacity;
            
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
            'totalContactHours' => $totalContactHours,
            'totalStudents' => $totalStudents,
            'semester' => $semesterFromSchedules
        ];
    }

    private function generateHeader(TCPDF $pdf, ?string $semester, AcademicYear $academicYear, User $faculty): void
    {
        // Add the two header logos
        $logoPath1 = __DIR__ . '/../../public/images/loadform/headers.png';  // Main university header
        $logoPath2 = __DIR__ . '/../../public/images/loadform/middlelogo1.png';     // GCL/ISO certification

        $logoHeight = 20;
        
        // Calculate center position for logos
        $pageWidth = $pdf->getPageWidth();
        $logo1Width = 145;
        $logo2Width = 25; // GCL logo approximate width
        $spaceBetween = 5;
        $totalWidth = $logo1Width + $spaceBetween + $logo2Width;
        $startX = ($pageWidth - $totalWidth) / 2;
        
        // Position the two logos centered
        // Logo 1: NORSU header
        if (file_exists($logoPath1)) {
            $pdf->Image($logoPath1, $startX, 9.5, $logo1Width, $logoHeight, '', '', '', true, 150);
        }
        // Logo 2: GCL/ISO certification
        if (file_exists($logoPath2)) {
            $pdf->Image($logoPath2, $startX + $logo1Width + $spaceBetween, 7, 0, $logoHeight, '', '', '', true, 150);
        }

        $pdf->SetY(30 );

        // Office title - fetch college name dynamically
        $pdf->SetFont('times', 'B', 11);
        // Get college name - try from faculty's college first, then from department's college
        $collegeName = 'College of Arts and Sciences'; // Default fallback
        if ($faculty->getCollege()) {
            $collegeName = $faculty->getCollege()->getName();
        } elseif ($faculty->getDepartment() && $faculty->getDepartment()->getCollege()) {
            $collegeName = $faculty->getDepartment()->getCollege()->getName();
        }
        $pdf->Cell(0, 5, 'Office of the Dean, ' . $collegeName, 0, 1, 'C');

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
        $colWidths = [60, 17, 26, 16.5, 18, 22, 31]; // Total = 190.5mm exactly
        $startX = 12.7; // Align to left margin (same as margins)
        
        // Table header with advanced formatting
        $pdf->SetFont('times', 'B', 9);
        $pdf->SetFillColor(255, 255, 255);
        
        $headerY = $pdf->GetY();
        
        // Calculate required height for multi-line headers
        $maxHeaderLines = max(
            $pdf->getNumLines("SUBJECT\nw/ SECTION", $colWidths[0]),
            $pdf->getNumLines('DAY', $colWidths[1]),
            $pdf->getNumLines('TIME', $colWidths[2]),
            $pdf->getNumLines("NO. of\nSTUD.", $colWidths[3]),
            $pdf->getNumLines("NO. of\nUNITS\nHANDLED", $colWidths[4]),
            $pdf->getNumLines("NO. of\nCONTACT\nHOURS per\nWEEK", $colWidths[5]),
            $pdf->getNumLines('ROOM', $colWidths[6])
        );
        $headerHeight = max($maxHeaderLines * 4, 14); // Auto-adjust based on content with better spacing
        
        // Draw header using MultiCell for perfect text wrapping and vertical centering
        $x = $startX;
        $pdf->MultiCell($colWidths[0], $headerHeight, "SUBJECT\nw/ SECTION", 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[0];
        $pdf->MultiCell($colWidths[1], $headerHeight, 'DAY', 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[1];
        $pdf->MultiCell($colWidths[2], $headerHeight, 'TIME', 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[2];
        $pdf->MultiCell($colWidths[3], $headerHeight, "NO. of\nSTUD.", 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[3];
        $pdf->MultiCell($colWidths[4], $headerHeight, "NO. of\nUNITS\nHANDLED", 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[4];
        $pdf->MultiCell($colWidths[5], $headerHeight, "NO. of\nCONTACT\nHOURS per\nWEEK", 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        $x += $colWidths[5];
        $pdf->MultiCell($colWidths[6], $headerHeight, 'ROOM', 1, 'C', true, 0, $x, $headerY, true, 0, false, true, $headerHeight, 'M');
        
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
                $courseCode = $schedule->getSubject()->getCode();
                $courseTitle = $schedule->getSubject()->getTitle();
                $subjectWithSection = $courseCode . ' ' . $schedule->getSection() . ' - ' . $courseTitle;
                $roomName = $schedule->getRoom()->getCode();
                $units = (string)$schedule->getSubject()->getUnits();
                
                // Determine max capacity based on lab hours
                // If subject has lab hours > 0, show 35, otherwise show 40
                $maxCapacity = ($schedule->getSubject()->getLabHours() > 0) ? 35 : 40;
                $students = (string)$maxCapacity;
                
                // Calculate contact hours per week based on day pattern
                // For Saturday-only or Sunday-only classes, contact hours = 5
                // For other patterns, contact hours = units
                $contactHours = $units;
                $normalizedPattern = strtoupper(trim($dayPattern));
                if ($normalizedPattern === 'SAT' || $normalizedPattern === 'SATURDAY' || 
                    $normalizedPattern === 'SUN' || $normalizedPattern === 'SUNDAY') {
                    $contactHours = '5';
                }
                
                // Calculate required height based on all cells
                $maxLines = max(
                    $pdf->getNumLines($subjectWithSection, $colWidths[0]),
                    $pdf->getNumLines($dayPattern, $colWidths[1]),
                    $pdf->getNumLines($timeText, $colWidths[2]),
                    $pdf->getNumLines($students, $colWidths[3]),
                    $pdf->getNumLines($roomName, $colWidths[6]),
                    1
                );
                
                $rowHeight = max($maxLines * 4.5, 6);
                
                // Draw each cell with MultiCell for automatic wrapping and vertical centering
                $x = $startX;
                $pdf->MultiCell($colWidths[0], $rowHeight, $subjectWithSection, 1, 'L', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[0];
                $pdf->MultiCell($colWidths[1], $rowHeight, $dayPattern, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[1];
                $pdf->MultiCell($colWidths[2], $rowHeight, $timeText, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[2];
                $pdf->MultiCell($colWidths[3], $rowHeight, $students, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[3];
                $pdf->MultiCell($colWidths[4], $rowHeight, $units, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[4];
                $pdf->MultiCell($colWidths[5], $rowHeight, $contactHours, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                $x += $colWidths[5];
                $pdf->MultiCell($colWidths[6], $rowHeight, $roomName, 1, 'C', false, 0, $x, $rowY, true, 0, false, true, $rowHeight, 'M');
                
                $pdf->SetY($rowY + $rowHeight);
            }

            // Total row - NO merged cells, TOTAL text in Description column only
            $pdf->SetFont('times', 'B', 8);
            $pdf->SetFillColor(211, 211, 211); // Gray background
            
            $totalY = $pdf->GetY();
            $totalHeight = 6;
            
            $x = $startX;
            // TOTAL text in Subject column
            $pdf->MultiCell($colWidths[0], $totalHeight, 'TOTAL', 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[0];
            // Empty cells
            $pdf->MultiCell($colWidths[1], $totalHeight, '', 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[1];
            $pdf->MultiCell($colWidths[2], $totalHeight, '', 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[2];
            // Total students
            $pdf->MultiCell($colWidths[3], $totalHeight, (string)$totals['totalStudents'], 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[3];
            // Total units
            $pdf->MultiCell($colWidths[4], $totalHeight, (string)$totals['totalUnits'], 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[4];
            // Total contact hours (calculated based on day patterns)
            $pdf->MultiCell($colWidths[5], $totalHeight, (string)$totals['totalContactHours'], 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            $x += $colWidths[5];
            // Empty cell for Room
            $pdf->MultiCell($colWidths[6], $totalHeight, '', 1, 'C', true, 0, $x, $totalY, true, 0, false, true, $totalHeight, 'M');
            
            $pdf->SetY($totalY + $totalHeight);

            // Consultation Period row
            $pdf->SetFont('times', '', 8);
            $pdf->SetFillColor(255, 255, 255);
            
            $consultY = $pdf->GetY();
            $consultHeight = 8;
            
            $x = $startX;
            // Consultation Period label in Subject column
            $pdf->MultiCell($colWidths[0], $consultHeight, 'Consultation Period', 1, 'L', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[0];
            // Empty cell for Day
            $pdf->MultiCell($colWidths[1], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[1];
            // Time in bold
            $pdf->SetFont('times', 'B', 8);
            $pdf->MultiCell($colWidths[2], $consultHeight, 'MWF 10-11 PM', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $pdf->SetFont('times', '', 8);
            $x += $colWidths[2];
            // Empty cells for remaining columns
            $pdf->MultiCell($colWidths[3], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[3];
            $pdf->MultiCell($colWidths[4], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[4];
            $pdf->MultiCell($colWidths[5], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            $x += $colWidths[5];
            $pdf->MultiCell($colWidths[6], $consultHeight, '', 1, 'C', false, 0, $x, $consultY, true, 0, false, true, $consultHeight, 'M');
            
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
        
        // Row 2: Total Hours (left) and Total Students (positioned to the right)
        $pdf->Cell($leftColWidth, $rowHeight, 'Total No. of Hrs./Week.: ' . number_format($totals['totalContactHours'], 0), 0, 0, 'L');
        $pdf->Cell(10, $rowHeight, '', 0, 0, 'L'); // Spacing between the two fields
        $pdf->Cell($rightColWidth - 10, $rowHeight, 'Total No. of Students: ' . $totals['totalStudents'], 0, 1, 'L');
        
        // Row 4: Other Designation (spans both columns)
        $pdf->Cell(0, $rowHeight, 'Other Designation/Special Assignments:', 0, 1, 'L');
        
        $pdf->Ln(5);
        
        // Row 5: Highest Educational Attainment (spans both columns) - with fillable field
        $y = $pdf->GetY();
        $pdf->Cell(55, $rowHeight, 'Highest Educational Attainment: ', 0, 0, 'L');
        $x = $pdf->GetX();
        // Add fillable text field for Educational Attainment
        $pdf->TextField('educational_attainment', 135, $rowHeight, ['strokeColor' => [200, 200, 200], 'lineWidth' => 0.3], [], $x, $y);
        $pdf->Ln($rowHeight);
        
        $pdf->Ln(6);
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

    private function generateFooterSection(TCPDF $pdf, $faculty): void
    {
        // Calculate footer position from the bottom of the page
        // Legal paper height is 355.6mm, with bottom margin of 8mm
        $pageHeight = $pdf->getPageHeight();
        $bottomMargin = 8;
        
        // Footer table dimensions
        $footerTableHeight = 12; // 3 rows * 4mm
        $gapBeforeTable = 5; // Increased gap before footer table
        
        // Calculate starting Y position (work backwards from bottom)
        $footerTableY = $pageHeight - $bottomMargin - $footerTableHeight;
        
        // Calculate total height needed for signature sections
        // Attested/Noted section: 25mm (label + name + line + title + gap)
        // Recommending section: 27mm (label + name + line + title + gap)
        // Approved section: 25mm (label + name + line + title)
        $totalSignatureHeight = 80; // Increased to move signatures higher
        
        $signatureStartY = $footerTableY - $gapBeforeTable - $totalSignatureHeight;
        
        $pdf->SetFont('times', '', 11);
        
        // Signature section at calculated Y position
        $pdf->SetY($signatureStartY);
        
        // First row - Attested by (left) and Noted by (right)
        $pdf->SetXY(15, $signatureStartY);
        $pdf->Cell(90, 5, 'Attested by:', 0, 0, 'L');
        
        $pdf->SetXY(110, $signatureStartY);
        $pdf->Cell(85, 5, 'Noted by:', 0, 1, 'L');
        
        // Names at calculated position
        $nameY = $signatureStartY + 10;
        $pdf->SetFont('times', 'B', 11);
        $pdf->SetXY(15, $nameY);
        
        // Get dean name - try from faculty's college first, then from department's college
        $deanName = 'NAME'; // Default fallback
        if ($faculty->getCollege() && $faculty->getCollege()->getDean()) {
            $deanName = strtoupper($faculty->getCollege()->getDean());
        } elseif ($faculty->getDepartment() && $faculty->getDepartment()->getCollege() && $faculty->getDepartment()->getCollege()->getDean()) {
            $deanName = strtoupper($faculty->getDepartment()->getCollege()->getDean());
        }
        
        $pdf->Cell(90, 5, $deanName, 0, 0, 'L');
        
        $pdf->SetXY(110, $nameY);
        $pdf->Cell(85, 5, 'RYAN O. TAYCO, Ph.D.', 0, 1, 'L');
        
        // Titles at calculated position
        $pdf->SetFont('times', '', 11);
        $titleY = $nameY + 6;
        $pdf->SetXY(15, $titleY);
        $pdf->Cell(90, 5, 'Dean', 0, 0, 'L');
        $pdf->SetXY(110, $titleY);
        $pdf->Cell(85, 5, 'Campus Director,  MC I & II and Pamplona Campus', 0, 1, 'L');
        
        // Recommending Approval section (centered)
        $recommendingY = $titleY + 18;
        $pdf->SetXY(0, $recommendingY);
        $pdf->Cell(0, 5, 'Recommending Approval:', 0, 1, 'C');
        
        $pdf->SetFont('times', 'B', 11);
        $recommendingNameY = $recommendingY + 10;
        $pdf->SetXY(0, $recommendingNameY);
        $pdf->Cell(0, 5, 'LIBERTINE C. DE GUZMAN, Ed.D.', 0, 1, 'C');
        
        $pdf->SetFont('times', '', 11);
        $recommendingTitleY = $recommendingNameY + 6;
        $pdf->SetXY(0, $recommendingTitleY);
        $pdf->Cell(0, 5, 'Vice President for Academic Affairs', 0, 1, 'C');
        
        // Approved section at calculated position
        $approvedY = $recommendingTitleY + 11;
        $pdf->SetXY(0, $approvedY);
        $pdf->Cell(0, 5, 'Approved:', 0, 1, 'C');
        
        $pdf->SetFont('times', 'B', 11);
        $approvedNameY = $approvedY + 10;
        $pdf->SetXY(0, $approvedNameY);
        $pdf->Cell(0, 5, 'Hon. NOEL MARJON E. YASI, Psy.D.', 0, 1, 'C');
        
        $pdf->SetFont('times', '', 11);
        $pdf->SetXY(0, $approvedNameY + 6);
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
