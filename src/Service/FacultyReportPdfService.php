<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use TCPDF;

class FacultyReportPdfService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function generateFacultyReportPdf(array $facultyData, ?string $year = null, ?string $semester = null, ?string $departmentName = null, ?string $searchTerm = null): string
    {
        // Create new PDF document in portrait mode with long bond paper (8.5 x 13 inches)
        $pdf = new TCPDF('P', PDF_UNIT, array(215.9, 330.2), true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Smart Scheduling System');
        $pdf->SetAuthor('NORSU');
        $pdf->SetTitle('Faculty Teaching History Report');
        $pdf->SetSubject('Faculty Teaching Report');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Disable image scale
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        
        // Set margins
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 10);

        // Add a page
        $pdf->AddPage();
        
        // Reset Y position to ensure we start at the top
        $pdf->SetY(10);

        // Generate the PDF content
        $this->generateHeader($pdf, $year, $semester, $departmentName, $searchTerm);
        $this->generateSummary($pdf, $facultyData);
        $this->generateFacultyTable($pdf, $facultyData);
        $this->generateFooter($pdf);

        // Return PDF as string
        return $pdf->Output('', 'S');
    }

    private function generateHeader(TCPDF $pdf, ?string $year, ?string $semester, ?string $departmentName, ?string $searchTerm): void
    {
        // Reset any previous formatting
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(255, 255, 255);
        
        // Ensure we're at the top of the page
        $pdf->SetY(10);
        
        // School header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'NEGROS ORIENTAL STATE UNIVERSITY', 0, 1, 'C');
        
        // Add spacing before subtitle
        $pdf->Ln(1);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Smart Scheduling System', 0, 1, 'C');
        
        // Add more spacing before report title
        $pdf->Ln(3);
        
        // Report title
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'FACULTY TEACHING HISTORY REPORT', 0, 1, 'C');
        
        // Add spacing after title
        $pdf->Ln(4);
        
        // Filter information
        $pdf->SetFont('helvetica', '', 9);
        
        if ($departmentName) {
            $pdf->Cell(0, 5, 'Department: ' . $departmentName, 0, 1, 'C');
        }
        
        if ($year || $semester) {
            $filterText = 'Academic Period: ';
            if ($year) $filterText .= $year;
            if ($year && $semester) $filterText .= ' | ';
            if ($semester) $filterText .= $semester . ' Semester';
            $pdf->Cell(0, 5, $filterText, 0, 1, 'C');
        }
        
        if ($searchTerm) {
            $pdf->Cell(0, 5, 'Search Term: "' . $searchTerm . '"', 0, 1, 'C');
        }
        
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 5, 'Generated on: ' . date('F d, Y h:i A'), 0, 1, 'C');
        
        $pdf->Ln(6);
    }

    private function generateSummary(TCPDF $pdf, array $facultyData): void
    {
        $totalFaculty = count($facultyData);
        $totalUnits = array_sum(array_column($facultyData, 'totalUnits'));
        $totalSubjects = array_sum(array_column($facultyData, 'scheduleCount'));
        
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'SUMMARY', 0, 1, 'L');
        
        $pdf->SetFont('helvetica', '', 10);
        
        // Create summary boxes - total width = 195mm, divide by 3 = 65mm each
        $boxWidth = 65;
        $boxHeight = 12;
        
        // Total Faculty
        $pdf->SetFillColor(147, 51, 234); // Purple
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($boxWidth, $boxHeight, 'Total Faculty: ' . $totalFaculty, 1, 0, 'C', true);
        
        // Total Units
        $pdf->SetFillColor(16, 185, 129); // Green
        $pdf->Cell($boxWidth, $boxHeight, 'Total Units: ' . $totalUnits, 1, 0, 'C', true);
        
        // Total Subjects
        $pdf->SetFillColor(59, 130, 246); // Blue
        $pdf->Cell($boxWidth, $boxHeight, 'Total Subjects: ' . $totalSubjects, 1, 1, 'C', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);
    }

    private function generateFacultyTable(TCPDF $pdf, array $facultyData): void
    {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'FACULTY DETAILS', 0, 1, 'L');
        
        // Table header - total width = 195mm (page width 215.9 - margins 20)
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        
        $pdf->Cell(12, 8, 'No.', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Employee ID', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Faculty Name', 1, 0, 'L', true);
        $pdf->Cell(40, 8, 'Position', 1, 0, 'L', true);
        $pdf->Cell(40, 8, 'Department', 1, 0, 'L', true);
        $pdf->Cell(13, 8, 'Units', 1, 0, 'C', true);
        $pdf->Cell(10, 8, 'Subj', 1, 1, 'C', true);
        
        // Table rows
        $pdf->SetFont('helvetica', '', 8);
        $count = 1;
        
        foreach ($facultyData as $item) {
            $faculty = $item[0];
            $scheduleCount = $item['scheduleCount'];
            $totalUnits = $item['totalUnits'] ?? 0;
            
            // Build full name with middle name/initial
            $nameParts = [$faculty->getFirstName()];
            if ($faculty->getMiddleName()) {
                $nameParts[] = $faculty->getMiddleName();
            }
            $nameParts[] = $faculty->getLastName();
            $fullName = implode(' ', $nameParts);
            
            $department = $faculty->getDepartment() ? $faculty->getDepartment()->getName() : 'No Department';
            $position = $faculty->getPosition() ?? 'N/A';
            
            // Auto page break check
            if ($pdf->GetY() > 290) {
                $pdf->AddPage();
                
                // Re-print header on new page
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetFillColor(240, 240, 240);
                
                $pdf->Cell(12, 8, 'No.', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Employee ID', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Faculty Name', 1, 0, 'L', true);
                $pdf->Cell(40, 8, 'Position', 1, 0, 'L', true);
                $pdf->Cell(40, 8, 'Department', 1, 0, 'L', true);
                $pdf->Cell(13, 8, 'Units', 1, 0, 'C', true);
                $pdf->Cell(10, 8, 'Subj', 1, 1, 'C', true);
                
                $pdf->SetFont('helvetica', '', 8);
            }
            
            // Alternate row colors
            if ($count % 2 == 0) {
                $pdf->SetFillColor(250, 250, 250);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            
            // Get current position
            $currentX = $pdf->GetX();
            $currentY = $pdf->GetY();
            
            // Calculate row height based on longest content
            $pdf->SetFont('helvetica', '', 8);
            $nameHeight = $pdf->getStringHeight(50, $fullName);
            $positionHeight = $pdf->getStringHeight(40, $position ?? '');
            $departmentHeight = $pdf->getStringHeight(40, $department);
            $maxHeight = max($nameHeight, $positionHeight, $departmentHeight, 8);
            
            // Draw all cells in proper sequence with explicit coordinates
            // Column 1: Number
            $pdf->SetXY($currentX, $currentY);
            $pdf->Cell(12, $maxHeight, $count, 1, 0, 'C', true);
            
            // Column 2: Employee ID
            $pdf->SetXY($currentX + 12, $currentY);
            $pdf->Cell(30, $maxHeight, $faculty->getEmployeeId() ?? '', 1, 0, 'L', true);
            
            // Column 3: Faculty Name
            $pdf->SetXY($currentX + 42, $currentY);
            $pdf->MultiCell(50, $maxHeight, $fullName, 1, 'L', true, 0, $currentX + 42, $currentY, true, 0, false, true, $maxHeight, 'M');
            
            // Column 4: Position
            $pdf->SetXY($currentX + 92, $currentY);
            $pdf->MultiCell(40, $maxHeight, $position ?? '', 1, 'L', true, 0, $currentX + 92, $currentY, true, 0, false, true, $maxHeight, 'M');
            
            // Column 5: Department
            $pdf->SetXY($currentX + 132, $currentY);
            $pdf->MultiCell(40, $maxHeight, $department, 1, 'L', true, 0, $currentX + 132, $currentY, true, 0, false, true, $maxHeight, 'M');
            
            // Column 6: Units
            $pdf->SetXY($currentX + 172, $currentY);
            $pdf->Cell(13, $maxHeight, $totalUnits, 1, 0, 'C', true);
            
            // Column 7: Subjects
            $pdf->SetXY($currentX + 185, $currentY);
            $pdf->Cell(10, $maxHeight, $scheduleCount, 1, 0, 'C', true);
            
            // Move to next row
            $pdf->SetXY($currentX, $currentY + $maxHeight);
            
            $count++;
        }
    }

    private function generateFooter(TCPDF $pdf): void
    {
        $pdf->Ln(8);
        
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        
        $footerText = 'This report was automatically generated by the Smart Scheduling System - NEGROS ORIENTAL STATE UNIVERSITY';
        $pdf->Cell(0, 5, $footerText, 0, 1, 'C');
    }
}
