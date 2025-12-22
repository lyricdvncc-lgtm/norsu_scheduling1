<?php

namespace App\Service;

use App\Entity\Schedule;
use App\Repository\ScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;

class ScheduleConflictDetector
{
    private ScheduleRepository $scheduleRepository;
    private EntityManagerInterface $entityManager;

    public function __construct(
        ScheduleRepository $scheduleRepository,
        EntityManagerInterface $entityManager
    ) {
        $this->scheduleRepository = $scheduleRepository;
        $this->entityManager = $entityManager;
    }

    /**
     * Check if a schedule has conflicts with existing schedules
     * 
     * @param Schedule $schedule The schedule to check
     * @param bool $excludeSelf Whether to exclude the schedule itself (for updates)
     * @return array Array of conflicting schedules
     */
    public function detectConflicts(Schedule $schedule, bool $excludeSelf = false): array
    {
        $conflicts = [];

        // Ensure we have a room before checking
        if (!$schedule->getRoom()) {
            return $conflicts;
        }

        // Get all schedules for the same room (removed day pattern filter to check overlapping days)
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('s')
            ->from(Schedule::class, 's')
            ->where('s.room = :room')
            ->andWhere('s.academicYear = :academicYear')
            ->andWhere('s.semester = :semester')
            ->andWhere('s.status = :status')
            ->setParameter('room', $schedule->getRoom())
            ->setParameter('academicYear', $schedule->getAcademicYear())
            ->setParameter('semester', $schedule->getSemester())
            ->setParameter('status', 'active');

        // Exclude the schedule itself if it's an update
        if ($excludeSelf && $schedule->getId()) {
            $qb->andWhere('s.id != :scheduleId')
                ->setParameter('scheduleId', $schedule->getId());
        }

        $existingSchedules = $qb->getQuery()->getResult();
        
        // Check for time overlaps AND day pattern overlaps
        foreach ($existingSchedules as $existing) {
            $hasDayOverlap = $this->hasDayOverlap($schedule->getDayPattern(), $existing->getDayPattern());
            $hasTimeOverlap = $this->hasTimeOverlap($schedule, $existing);
            
            // Check if day patterns overlap AND times overlap
            if ($hasDayOverlap && $hasTimeOverlap) {
                $conflicts[] = [
                    'type' => 'room_time_conflict',
                    'schedule' => $existing,
                    'message' => sprintf(
                        'Room %s is already booked for %s (%s) from %s to %s (Section: %s)',
                        $existing->getRoom()->getName(),
                        $existing->getSubject()->getCode(),
                        $existing->getDayPattern(),
                        $existing->getStartTime()->format('g:i A'),
                        $existing->getEndTime()->format('g:i A'),
                        $existing->getSection() ?? 'N/A'
                    )
                ];
            }
        }

        // Check for subject-section conflicts (same subject and section at same time)
        if ($schedule->getSection()) {
            $sectionConflicts = $this->checkSectionConflicts($schedule, $excludeSelf);
            $conflicts = array_merge($conflicts, $sectionConflicts);
        }

        return $conflicts;
    }

    /**
     * Check if two schedules have overlapping time periods
     */
    private function hasTimeOverlap(Schedule $schedule1, Schedule $schedule2): bool
    {
        $start1 = $schedule1->getStartTime();
        $end1 = $schedule1->getEndTime();
        $start2 = $schedule2->getStartTime();
        $end2 = $schedule2->getEndTime();

        // Convert to timestamps for comparison
        $start1Time = strtotime($start1->format('H:i:s'));
        $end1Time = strtotime($end1->format('H:i:s'));
        $start2Time = strtotime($start2->format('H:i:s'));
        $end2Time = strtotime($end2->format('H:i:s'));

        // Check for overlap: (start1 < end2) AND (end1 > start2)
        return ($start1Time < $end2Time) && ($end1Time > $start2Time);
    }

    /**
     * Check if two day patterns have overlapping days
     * 
     * @param string $pattern1 First day pattern (e.g., "Mon-Fri (Daily)", "MWF", "TTh")
     * @param string $pattern2 Second day pattern
     * @return bool True if the patterns share at least one common day
     */
    private function hasDayOverlap(string $pattern1, string $pattern2): bool
    {
        // Map of day patterns to actual days
        $dayMap = [
            'M' => 'Monday',
            'T' => 'Tuesday',
            'W' => 'Wednesday',
            'Th' => 'Thursday',
            'F' => 'Friday',
            'S' => 'Saturday',
            'Su' => 'Sunday',
        ];

        // Extract days from pattern1
        $days1 = $this->extractDays($pattern1);
        $days2 = $this->extractDays($pattern2);
        
        $overlap = array_intersect($days1, $days2);

        // Check if there's any overlap
        return !empty($overlap);
    }

    /**
     * Extract individual days from a day pattern
     * 
     * @param string $pattern Day pattern (e.g., "MTWTHF", "MWF", "TTH", "MW")
     * @return array Array of day names
     */
    private function extractDays(string $pattern): array
    {
        $days = [];
        
        // Normalize pattern to uppercase for consistent comparison
        $pattern = strtoupper(trim($pattern));

        // Check for common full patterns first
        if (strpos($pattern, 'MTWTHF') !== false || strpos($pattern, 'DAILY') !== false || strpos($pattern, 'MON-FRI') !== false) {
            return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        }
        
        if (strpos($pattern, 'MON-SAT') !== false) {
            return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        }

        // Handle specific patterns
        if ($pattern === 'SAT' || $pattern === 'SATURDAY') {
            return ['Saturday'];
        }
        
        if ($pattern === 'SUN' || $pattern === 'SUNDAY') {
            return ['Sunday'];
        }

        // Parse individual day codes from the pattern
        // We need to check longer patterns first to avoid false matches
        
        // Handle "TH" for Thursday (must check before "T")
        if (strpos($pattern, 'TH') !== false) {
            $days[] = 'Thursday';
            // Remove TH to avoid matching T again
            $pattern = str_replace('TH', '', $pattern);
        }

        // Handle Sunday (SU)
        if (strpos($pattern, 'SU') !== false) {
            $days[] = 'Sunday';
            $pattern = str_replace('SU', '', $pattern);
        }

        // Handle Saturday (SA)
        if (strpos($pattern, 'SA') !== false) {
            $days[] = 'Saturday';
            $pattern = str_replace('SA', '', $pattern);
        }

        // Handle remaining single letter days
        if (strpos($pattern, 'M') !== false) {
            $days[] = 'Monday';
        }
        if (strpos($pattern, 'T') !== false) {
            $days[] = 'Tuesday';
        }
        if (strpos($pattern, 'W') !== false) {
            $days[] = 'Wednesday';
        }
        if (strpos($pattern, 'F') !== false) {
            $days[] = 'Friday';
        }

        return array_unique($days);
    }

    /**
     * Check if same section is scheduled for multiple subjects at the same time
     */
    private function checkSectionConflicts(Schedule $schedule, bool $excludeSelf = false): array
    {
        $conflicts = [];

        // Check if the SAME subject and section is scheduled at the same time
        // (Section A of ITS 100 is different from Section A of ITS 101)
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('s')
            ->from(Schedule::class, 's')
            ->where('s.section = :section')
            ->andWhere('s.subject = :subject')
            ->andWhere('s.academicYear = :academicYear')
            ->andWhere('s.semester = :semester')
            ->andWhere('s.status = :status')
            ->setParameter('section', $schedule->getSection())
            ->setParameter('subject', $schedule->getSubject())
            ->setParameter('academicYear', $schedule->getAcademicYear())
            ->setParameter('semester', $schedule->getSemester())
            ->setParameter('status', 'active');

        if ($excludeSelf && $schedule->getId()) {
            $qb->andWhere('s.id != :scheduleId')
                ->setParameter('scheduleId', $schedule->getId());
        }

        $existingSchedules = $qb->getQuery()->getResult();

        foreach ($existingSchedules as $existing) {
            // Check if day patterns overlap AND times overlap
            if ($this->hasDayOverlap($schedule->getDayPattern(), $existing->getDayPattern())
                && $this->hasTimeOverlap($schedule, $existing)) {
                $conflicts[] = [
                    'type' => 'section_conflict',
                    'schedule' => $existing,
                    'message' => sprintf(
                        'Section %s is already scheduled for %s (%s) from %s to %s in Room %s',
                        $existing->getSection(),
                        $existing->getSubject()->getCode(),
                        $existing->getDayPattern(),
                        $existing->getStartTime()->format('g:i A'),
                        $existing->getEndTime()->format('g:i A'),
                        $existing->getRoom()->getName()
                    )
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Check if the same subject-section combination already exists (duplicate check)
     */
    public function checkDuplicateSubjectSection(Schedule $schedule, bool $excludeSelf = false): array
    {
        $conflicts = [];

        if (!$schedule->getSubject() || !$schedule->getSection()) {
            return $conflicts;
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('s')
            ->from(Schedule::class, 's')
            ->where('s.subject = :subject')
            ->andWhere('s.section = :section')
            ->andWhere('s.academicYear = :academicYear')
            ->andWhere('s.semester = :semester')
            ->andWhere('s.status = :status')
            ->setParameter('subject', $schedule->getSubject())
            ->setParameter('section', $schedule->getSection())
            ->setParameter('academicYear', $schedule->getAcademicYear())
            ->setParameter('semester', $schedule->getSemester())
            ->setParameter('status', 'active');

        if ($excludeSelf && $schedule->getId()) {
            $qb->andWhere('s.id != :scheduleId')
                ->setParameter('scheduleId', $schedule->getId());
        }

        $existingSchedules = $qb->getQuery()->getResult();

        foreach ($existingSchedules as $existing) {
            $conflicts[] = [
                'type' => 'duplicate_subject_section',
                'schedule' => $existing,
                'message' => sprintf(
                    'Subject %s Section %s already exists on %s from %s to %s in Room %s',
                    $existing->getSubject()->getCode(),
                    $existing->getSection(),
                    $existing->getDayPattern(),
                    $existing->getStartTime()->format('g:i A'),
                    $existing->getEndTime()->format('g:i A'),
                    $existing->getRoom()->getName()
                )
            ];
        }

        return $conflicts;
    }

    /**
     * Get a summary of conflicts for display
     */
    public function getConflictSummary(array $conflicts): string
    {
        if (empty($conflicts)) {
            return 'No conflicts detected.';
        }

        $summary = sprintf('%d conflict(s) detected:<br>', count($conflicts));
        foreach ($conflicts as $conflict) {
            $summary .= '• ' . $conflict['message'] . '<br>';
        }

        return $summary;
    }

    /**
     * Mark a schedule as conflicted or not
     */
    public function updateConflictStatus(Schedule $schedule): void
    {
        $conflicts = $this->detectConflicts($schedule, true);
        $schedule->setIsConflicted(!empty($conflicts));
    }

    /**
     * Validate schedule time ranges
     */
    public function validateTimeRange(Schedule $schedule): array
    {
        $errors = [];

        $startTime = $schedule->getStartTime();
        $endTime = $schedule->getEndTime();

        if ($startTime >= $endTime) {
            $errors[] = 'End time must be after start time.';
        }

        // Check if time range is reasonable (e.g., not more than 8 hours)
        $duration = $endTime->getTimestamp() - $startTime->getTimestamp();
        $hours = $duration / 3600;

        if ($hours > 8) {
            $errors[] = 'Schedule duration cannot exceed 8 hours.';
        }

        if ($hours < 0.5) {
            $errors[] = 'Schedule duration must be at least 30 minutes.';
        }

        return $errors;
    }

    /**
     * Check room capacity vs enrolled students
     */
    public function validateRoomCapacity(Schedule $schedule): array
    {
        $errors = [];
        $room = $schedule->getRoom();
        $enrolledStudents = $schedule->getEnrolledStudents();

        if ($room && $enrolledStudents > 0) {
            $capacity = $room->getCapacity();
            if ($capacity && $enrolledStudents > $capacity) {
                $errors[] = sprintf(
                    'Enrolled students (%d) exceeds room capacity (%d).',
                    $enrolledStudents,
                    $capacity
                );
            }
        }

        return $errors;
    }

    /**
     * Scan all schedules and update their conflict status
     * This method checks all active schedules and marks them as conflicted if they have conflicts
     * 
     * @param int|null $departmentId Optional department ID to limit scanning to specific department
     * @return array Statistics about the scan (total_scanned, conflicts_found, schedules_updated)
     */
    public function scanAndUpdateAllConflicts(?int $departmentId = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('s')
            ->from(Schedule::class, 's')
            ->where('s.status = :status')
            ->setParameter('status', 'active');

        // Filter by department if provided
        if ($departmentId) {
            $qb->join('s.subject', 'subj')
               ->andWhere('subj.department = :deptId')
               ->setParameter('deptId', $departmentId);
        }

        $allSchedules = $qb->getQuery()->getResult();
        
        $stats = [
            'total_scanned' => count($allSchedules),
            'conflicts_found' => 0,
            'schedules_updated' => 0,
        ];

        foreach ($allSchedules as $schedule) {
            $oldStatus = $schedule->getIsConflicted();
            $conflicts = $this->detectConflicts($schedule, true);
            $hasConflicts = !empty($conflicts);
            
            $schedule->setIsConflicted($hasConflicts);
            
            if ($hasConflicts) {
                $stats['conflicts_found']++;
            }
            
            // Track if status changed
            if ($oldStatus !== $hasConflicts) {
                $stats['schedules_updated']++;
            }
        }

        // Persist all changes
        $this->entityManager->flush();

        return $stats;
    }

    /**
     * Get all conflicted schedules for a department
     * 
     * @param int|null $departmentId Department ID to filter by
     * @param object|null $academicYear Academic year to filter by
     * @param string|null $semester Semester to filter by
     * @return array Array of schedules with their conflicts
     */
    public function getConflictedSchedulesWithDetails(?int $departmentId = null, ?object $academicYear = null, ?string $semester = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('s')
            ->from(Schedule::class, 's')
            ->where('s.status = :status')
            ->setParameter('status', 'active');

        if ($departmentId) {
            $qb->join('s.subject', 'subj')
               ->andWhere('subj.department = :deptId')
               ->setParameter('deptId', $departmentId);
        }

        if ($academicYear) {
            $qb->andWhere('s.academicYear = :academicYear')
               ->setParameter('academicYear', $academicYear);
        }

        if ($semester) {
            $qb->andWhere('s.semester = :semester')
               ->setParameter('semester', $semester);
        }

        $schedules = $qb->getQuery()->getResult();
        $conflictedSchedules = [];

        foreach ($schedules as $schedule) {
            $conflicts = $this->detectConflicts($schedule, true);
            if (!empty($conflicts)) {
                $conflictedSchedules[] = [
                    'schedule' => $schedule,
                    'conflicts' => $conflicts,
                    'conflict_count' => count($conflicts),
                ];
            }
        }

        return $conflictedSchedules;
    }
}
