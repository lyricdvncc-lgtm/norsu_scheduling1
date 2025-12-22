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
        // School header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 8, 'NEGROS ORIENTAL STATE UNIVERSITY', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 6, 'Smart Scheduling System', 0, 1, 'C');
        
        $pdf->Ln(3);
        
        // Report title
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'ROOM USAGE HISTORY REPORT', 0, 1, 'C');
        
        $pdf->Ln(2);
        
        // Filter information
        $pdf->SetFont('helvetica', '', 10);
        
        if ($departmentName) {
            $pdf->Cell(0, 6, 'Department: ' . $departmentName, 0, 1, 'C');
        }
        
        if ($year || $semester) {
            $filterText = 'Academic Period: ';
            if ($year) $filterText .= $year;
            if ($year && $semester) $filterText .= ' | ';
            if ($semester) $filterText .= $semester . ' Semester';
            $pdf->Cell(0, 6, $filterText, 0, 1, 'C');
        }
        
        if ($searchTerm) {
            $pdf->Cell(0, 6, 'Search Term: "' . $searchTerm . '"', 0, 1, 'C');
        }
        
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Cell(0, 5, 'Generated on: ' . date('F d, Y h:i A'), 0, 1, 'C');
        
        $pdf->Ln(5);
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
        $pdf->Cell(45, 8, 'Room Name', 1, 0, 'L', true);
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
                $pdf->Cell(45, 8, 'Room Name', 1, 0, 'L', true);
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
            
            // Get starting Y position and X position
            $startY = $pdf->GetY();
            $startX = $pdf->GetX();
            
            // Calculate row height based on longest content
            $pdf->SetFont('helvetica', '', 8);
            $roomNameHeight = $pdf->getStringHeight(45, $room->getName());
            $buildingHeight = $pdf->getStringHeight(45, $room->getBuilding() ?? '');
            $departmentsHeight = $pdf->getStringHeight(45, $departments);
            $maxHeight = max($roomNameHeight, $buildingHeight, $departmentsHeight, 7);
            
            // Draw cells with same height
            $pdf->Cell(12, $maxHeight, $count, 1, 0, 'C', true);
            
            // Room Name with wrapping
            $pdf->MultiCell(45, $maxHeight, $room->getName(), 1, 'L', true, 0, 0, 0, true, 0, false, true, $maxHeight, 'M');
            
            // Building with wrapping
            $pdf->MultiCell(45, $maxHeight, $room->getBuilding() ?? '', 1, 'L', true, 0, 0, 0, true, 0, false, true, $maxHeight, 'M');
            
            // Capacity (single line)
            $pdf->Cell(23, $maxHeight, $room->getCapacity(), 1, 0, 'C', true);
            
            // Schedule Count (single line)
            $pdf->Cell(25, $maxHeight, $scheduleCount, 1, 0, 'C', true);
            
            // Departments with wrapping
            $pdf->MultiCell(45, $maxHeight, $departments, 1, 'L', true, 1, 0, 0, true, 0, false, true, $maxHeight, 'M');
            
            $count++;
        }
    }

    private function generateFooter(TCPDF $pdf): void
    {
        $pdf->Ln(10);
        
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        
        $pdf->Cell(0, 5, 'This report was automatically generated by the Smart Scheduling System', 0, 1, 'C');
        $pdf->Cell(0, 5, 'NEGROS ORIENTAL STATE UNIVERSITY', 0, 1, 'C');
    }
}
