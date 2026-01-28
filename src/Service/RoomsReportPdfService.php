<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use TCPDF;

class RoomsReportPdfService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function generateRoomsReportPdf(array $roomsData, ?string $year = null, ?string $semester = null, ?string $departmentName = null, ?string $searchTerm = null): string
    {
        // Create new PDF document in portrait mode with long bond paper (8.5 x 13 inches)
        $pdf = new TCPDF('P', PDF_UNIT, array(215.9, 330.2), true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Smart Scheduling System');
        $pdf->SetAuthor('NORSU');
        $pdf->SetTitle('Room Usage History Report');
        $pdf->SetSubject('Room Usage Report');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 10);

        // Add a page
        $pdf->AddPage();

        // Generate the PDF content
        $this->generateHeader($pdf, $year, $semester, $departmentName, $searchTerm);
        $this->generateSummary($pdf, $roomsData);
        $this->generateRoomsTable($pdf, $roomsData);
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
        $pdf->Cell(0, 8, 'ROOM USAGE HISTORY REPORT', 0, 1, 'C');
        
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

    private function generateSummary(TCPDF $pdf, array $roomsData): void
    {
        $totalRooms = count($roomsData);
        $totalSchedules = array_sum(array_column($roomsData, 'scheduleCount'));
        
        // Calculate total capacity
        $totalCapacity = 0;
        foreach ($roomsData as $item) {
            $room = $item[0];
            $totalCapacity += $room->getCapacity() ?? 0;
        }
        
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'SUMMARY', 0, 1, 'L');
        
        $pdf->SetFont('helvetica', '', 10);
        
        // Create summary boxes - total width = 195mm, divide by 3 = 65mm each
        $boxWidth = 65;
        $boxHeight = 12;
        
        // Total Rooms
        $pdf->SetFillColor(59, 130, 246); // Blue
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($boxWidth, $boxHeight, 'Total Rooms: ' . $totalRooms, 1, 0, 'C', true);
        
        // Total Capacity
        $pdf->SetFillColor(16, 185, 129); // Green
        $pdf->Cell($boxWidth, $boxHeight, 'Total Capacity: ' . $totalCapacity, 1, 0, 'C', true);
        
        // Total Schedules
        $pdf->SetFillColor(249, 115, 22); // Orange
        $pdf->Cell($boxWidth, $boxHeight, 'Total Schedules: ' . $totalSchedules, 1, 1, 'C', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);
    }

    private function generateRoomsTable(TCPDF $pdf, array $roomsData): void
    {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'ROOM DETAILS', 0, 1, 'L');
        
        // Table header - total width = 195mm (page width 215.9 - margins 20)
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        
        $pdf->Cell(12, 8, 'No.', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Room Code', 1, 0, 'L', true);
        $pdf->Cell(45, 8, 'Building', 1, 0, 'L', true);
        $pdf->Cell(23, 8, 'Capacity', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Schedules', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Departments', 1, 1, 'L', true);
        
        // Table rows
        $pdf->SetFont('helvetica', '', 8);
        $count = 1;
        
        foreach ($roomsData as $item) {
            $room = $item[0];
            $scheduleCount = $item['scheduleCount'];
            $departments = $item['departments'] ?? 'N/A';
            
            // Auto page break check
            if ($pdf->GetY() > 290) {
                $pdf->AddPage();
                
                // Re-print header on new page
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetFillColor(240, 240, 240);
                
                $pdf->Cell(12, 8, 'No.', 1, 0, 'C', true);
                $pdf->Cell(45, 8, 'Room Code', 1, 0, 'L', true);
                $pdf->Cell(45, 8, 'Building', 1, 0, 'L', true);
                $pdf->Cell(23, 8, 'Capacity', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Schedules', 1, 0, 'C', true);
                $pdf->Cell(45, 8, 'Departments', 1, 1, 'L', true);
                
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
            $roomCodeHeight = $pdf->getStringHeight(45, $room->getCode());
            $buildingHeight = $pdf->getStringHeight(45, $room->getBuilding() ?? '');
            $departmentsHeight = $pdf->getStringHeight(45, $departments);
            $maxHeight = max($roomCodeHeight, $buildingHeight, $departmentsHeight, 8);
            
            // Draw all cells in proper sequence with explicit coordinates
            // Column 1: Number
            $pdf->SetXY($currentX, $currentY);
            $pdf->Cell(12, $maxHeight, $count, 1, 0, 'C', true);
            
            // Column 2: Room Code
            $pdf->SetXY($currentX + 12, $currentY);
            $pdf->MultiCell(45, $maxHeight, $room->getCode(), 1, 'L', true, 0, $currentX + 12, $currentY, true, 0, false, true, $maxHeight, 'M');
            
            // Column 3: Building
            $pdf->SetXY($currentX + 57, $currentY);
            $pdf->MultiCell(45, $maxHeight, $room->getBuilding() ?? '', 1, 'L', true, 0, $currentX + 57, $currentY, true, 0, false, true, $maxHeight, 'M');
            
            // Column 4: Capacity
            $pdf->SetXY($currentX + 102, $currentY);
            $pdf->Cell(23, $maxHeight, $room->getCapacity(), 1, 0, 'C', true);
            
            // Column 5: Schedule Count
            $pdf->SetXY($currentX + 125, $currentY);
            $pdf->Cell(25, $maxHeight, $scheduleCount, 1, 0, 'C', true);
            
            // Column 6: Departments
            $pdf->SetXY($currentX + 150, $currentY);
            $pdf->MultiCell(45, $maxHeight, $departments, 1, 'L', true, 0, $currentX + 150, $currentY, true, 0, false, true, $maxHeight, 'M');
            
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
