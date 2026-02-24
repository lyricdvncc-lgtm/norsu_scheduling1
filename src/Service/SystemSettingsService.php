<?php

namespace App\Service;

use App\Entity\AcademicYear;
use App\Repository\AcademicYearRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SystemSettingsService
{
    private const VALID_SEMESTERS = ['1st', '2nd', 'Summer'];

    public function __construct(
        private AcademicYearRepository $academicYearRepository,
        private EntityManagerInterface $entityManager,
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Get the active academic year (the one marked as is_current = true)
     */
    public function getActiveAcademicYear(): ?AcademicYear
    {
        return $this->academicYearRepository->findCurrent();
    }

    /**
     * Get the active semester from the current academic year
     */
    public function getActiveSemester(): ?string
    {
        $activeYear = $this->getActiveAcademicYear();
        return $activeYear?->getCurrentSemester();
    }

    /**
     * Get full display name of active semester (e.g., "2025-2026 | 1st Semester")
     */
    public function getActiveSemesterDisplay(): string
    {
        $activeYear = $this->getActiveAcademicYear();
        
        if (!$activeYear) {
            return 'No active semester set';
        }

        return $activeYear->getFullDisplayName();
    }

    /**
     * Check if a specific year and semester combination is currently active
     */
    public function isActiveSemester(int $academicYearId, string $semester): bool
    {
        $activeYear = $this->getActiveAcademicYear();
        
        if (!$activeYear) {
            return false;
        }

        return $activeYear->getId() === $academicYearId 
            && $activeYear->getCurrentSemester() === $semester;
    }

    /**
     * Set the active academic year and semester
     * This will mark the specified year as current and set its semester
     */
    public function setActiveSemester(int $academicYearId, string $semester, ?\DateTimeInterface $semesterStart = null, ?\DateTimeInterface $semesterEnd = null): AcademicYear
    {
        // Validate semester value
        if (!in_array($semester, self::VALID_SEMESTERS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid semester "%s". Must be one of: %s', 
                    $semester, 
                    implode(', ', self::VALID_SEMESTERS)
                )
            );
        }

        // Get the academic year
        $academicYear = $this->academicYearRepository->find($academicYearId);
        if (!$academicYear) {
            throw new \InvalidArgumentException('Academic year not found');
        }

        // Check if academic year is active
        if (!$academicYear->isActive()) {
            throw new \InvalidArgumentException('Cannot set inactive academic year as current');
        }

        // Unset current flag from all other years (but not this one)
        $this->unsetAllCurrentYears($academicYearId);

        // Set this as current with the specified semester
        $academicYear->setIsCurrent(true);
        $academicYear->setCurrentSemester($semester);
        $academicYear->setUpdatedAt(new \DateTime());

        // Save semester dates if provided
        if ($semesterStart !== null || $semesterEnd !== null) {
            $academicYear->setSemesterDates($semester, $semesterStart, $semesterEnd);
        }

        $this->entityManager->flush();

        return $academicYear;
    }

    /**
     * Change only the semester of the current active year
     * Useful for transitioning from 1st to 2nd semester within the same year
     */
    public function changeActiveSemester(string $newSemester): AcademicYear
    {
        // Validate semester value
        if (!in_array($newSemester, self::VALID_SEMESTERS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid semester "%s". Must be one of: %s', 
                    $newSemester, 
                    implode(', ', self::VALID_SEMESTERS)
                )
            );
        }

        $activeYear = $this->getActiveAcademicYear();
        if (!$activeYear) {
            throw new \RuntimeException('No active academic year found. Please set one first.');
        }

        // Don't allow setting the same semester
        if ($activeYear->getCurrentSemester() === $newSemester) {
            throw new \InvalidArgumentException(
                sprintf('Semester "%s" is already the active semester', $newSemester)
            );
        }

        $activeYear->setCurrentSemester($newSemester);
        $activeYear->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        return $activeYear;
    }

    /**
     * Get validation errors for semester transition
     * Returns an array of warnings/errors before changing semester
     */
    public function validateSemesterTransition(int $academicYearId, string $semester): array
    {
        $warnings = [];

        // Check if semester is valid
        if (!in_array($semester, self::VALID_SEMESTERS, true)) {
            $warnings[] = sprintf('Invalid semester "%s". Must be one of: %s', 
                $semester, 
                implode(', ', self::VALID_SEMESTERS)
            );
            return $warnings;
        }

        // Check if academic year exists and is active
        $academicYear = $this->academicYearRepository->find($academicYearId);
        if (!$academicYear) {
            $warnings[] = 'Academic year not found';
            return $warnings;
        }

        if (!$academicYear->isActive()) {
            $warnings[] = 'Academic year is not active';
        }

        // Check if this is already the active semester
        if ($this->isActiveSemester($academicYearId, $semester)) {
            $warnings[] = sprintf('"%s - %s Semester" is already the active semester', 
                $academicYear->getYear(), 
                $semester
            );
        }

        return $warnings;
    }

    /**
     * Get available semesters
     */
    public function getAvailableSemesters(): array
    {
        return self::VALID_SEMESTERS;
    }

    /**
     * Check if system has an active semester configured
     */
    public function hasActiveSemester(): bool
    {
        $activeYear = $this->getActiveAcademicYear();
        return $activeYear !== null && $activeYear->getCurrentSemester() !== null;
    }

    /**
     * Get semester transition information
     * Useful for displaying what will happen when semester changes
     */
    public function getSemesterTransitionInfo(): array
    {
        $activeYear = $this->getActiveAcademicYear();
        
        if (!$activeYear) {
            return [
                'has_active' => false,
                'current_year' => null,
                'current_semester' => null,
                'message' => 'No active semester configured'
            ];
        }

        return [
            'has_active' => true,
            'current_year' => $activeYear->getYear(),
            'current_semester' => $activeYear->getCurrentSemester(),
            'current_year_id' => $activeYear->getId(),
            'display_name' => $activeYear->getFullDisplayName(),
            'message' => 'Active semester found'
        ];
    }

    /**
     * Unset current flag from all academic years
     */
    private function unsetAllCurrentYears(?int $exceptId = null): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->update(AcademicYear::class, 'ay')
           ->set('ay.isCurrent', ':false')
           ->set('ay.currentSemester', ':null')
           ->setParameter('false', false)
           ->setParameter('null', null)
           ->where('ay.deletedAt IS NULL');

        if ($exceptId !== null) {
            $qb->andWhere('ay.id != :exceptId')
               ->setParameter('exceptId', $exceptId);
        }

        $qb->getQuery()->execute();
    }

    /**
     * Get semester order for comparison
     * Returns numeric value for semester ordering (1st=1, 2nd=2, Summer=3)
     */
    public function getSemesterOrder(string $semester): int
    {
        return match($semester) {
            '1st' => 1,
            '2nd' => 2,
            'Summer' => 3,
            default => 0
        };
    }

    /**
     * Get next logical semester
     * 1st -> 2nd, 2nd -> Summer, Summer -> 1st (new year)
     */
    public function getNextSemester(string $currentSemester): ?string
    {
        return match($currentSemester) {
            '1st' => '2nd',
            '2nd' => 'Summer',
            'Summer' => '1st', // This would need a new academic year
            default => null
        };
    }

    /**
     * Get the start and end dates for the currently active semester
     * @return array{start: ?\DateTimeInterface, end: ?\DateTimeInterface}
     */
    public function getActiveSemesterDates(): array
    {
        $activeYear = $this->getActiveAcademicYear();
        if (!$activeYear || !$activeYear->getCurrentSemester()) {
            return ['start' => null, 'end' => null];
        }

        return $activeYear->getSemesterDates($activeYear->getCurrentSemester());
    }

    /**
     * Get all semester dates for a specific academic year
     * @return array<string, array{start: ?string, end: ?string}>
     */
    public function getAllSemesterDatesForYear(int $academicYearId): array
    {
        $academicYear = $this->academicYearRepository->find($academicYearId);
        if (!$academicYear) {
            return [];
        }

        $result = [];
        foreach (self::VALID_SEMESTERS as $semester) {
            $dates = $academicYear->getSemesterDates($semester);
            $result[$semester] = [
                'start' => $dates['start'] ? $dates['start']->format('Y-m-d') : null,
                'end' => $dates['end'] ? $dates['end']->format('Y-m-d') : null,
            ];
        }

        return $result;
    }

    /**
     * Save semester dates for a specific academic year and semester
     */
    public function saveSemesterDates(int $academicYearId, string $semester, ?\DateTimeInterface $start, ?\DateTimeInterface $end): void
    {
        $academicYear = $this->academicYearRepository->find($academicYearId);
        if (!$academicYear) {
            throw new \InvalidArgumentException('Academic year not found');
        }

        $academicYear->setSemesterDates($semester, $start, $end);
        $academicYear->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();
    }

    /**
     * Clear (nullify) dates for a specific semester.
     */
    public function clearSemesterDates(int $academicYearId, string $semester): void
    {
        if (!in_array($semester, self::VALID_SEMESTERS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid semester "%s". Must be one of: %s', $semester, implode(', ', self::VALID_SEMESTERS))
            );
        }

        $academicYear = $this->academicYearRepository->find($academicYearId);
        if (!$academicYear) {
            throw new \InvalidArgumentException('Academic year not found');
        }

        $academicYear->setSemesterDates($semester, null, null);
        $academicYear->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();
    }

    /**
     * Save dates for all semesters at once (1st, 2nd, Summer) from the UI payload.
     * Validates that dates are in chronological order: 1st end < 2nd start < 2nd end < Summer start.
     * @param int $academicYearId
     * @param array<string, array{start: ?string, end: ?string}> $allDates
     * @throws \InvalidArgumentException if dates are out of order
     */
    public function saveAllSemesterDates(int $academicYearId, array $allDates): void
    {
        $academicYear = $this->academicYearRepository->find($academicYearId);
        if (!$academicYear) {
            throw new \InvalidArgumentException('Academic year not found');
        }

        // Parse all dates first
        $parsed = [];
        foreach (self::VALID_SEMESTERS as $semester) {
            if (isset($allDates[$semester])) {
                $start = !empty($allDates[$semester]['start']) ? new \DateTime($allDates[$semester]['start']) : null;
                $end = !empty($allDates[$semester]['end']) ? new \DateTime($allDates[$semester]['end']) : null;

                // Validate start < end for each semester
                if ($start && $end && $start >= $end) {
                    $label = $semester === 'Summer' ? 'Summer' : $semester . ' Semester';
                    throw new \InvalidArgumentException(
                        sprintf('%s: start date must be before end date', $label)
                    );
                }

                $parsed[$semester] = ['start' => $start, 'end' => $end];
            }
        }

        // Validate chronological sequence: 1st end < 2nd start, 2nd end < Summer start
        $sequencePairs = [
            ['1st', '2nd'],
            ['2nd', 'Summer'],
        ];
        foreach ($sequencePairs as [$first, $second]) {
            if (isset($parsed[$first]) && isset($parsed[$second])
                && $parsed[$first]['end'] !== null && $parsed[$second]['start'] !== null
            ) {
                if ($parsed[$second]['start'] <= $parsed[$first]['end']) {
                    $firstLabel = $first === 'Summer' ? 'Summer' : $first . ' Semester';
                    $secondLabel = $second === 'Summer' ? 'Summer' : $second . ' Semester';
                    throw new \InvalidArgumentException(
                        sprintf('%s start date must be after %s end date', $secondLabel, $firstLabel)
                    );
                }
            }
        }

        // All valid — persist
        foreach ($parsed as $semester => $dates) {
            $academicYear->setSemesterDates($semester, $dates['start'], $dates['end']);
        }

        $academicYear->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();
    }

    /**
     * Check if the current active semester has expired and auto-transition to the next one.
     * Returns null if no transition needed, or the new AcademicYear if transition occurred.
     *
     * Transition logic:
     *   1st -> 2nd (same year)
     *   2nd -> Summer (same year)
     *   Summer -> 1st (next academic year, if it exists and is active)
     */
    public function checkAndAutoTransition(): ?array
    {
        $activeYear = $this->getActiveAcademicYear();
        if (!$activeYear || !$activeYear->getCurrentSemester()) {
            return null;
        }

        // Check if the current semester has expired
        if (!$activeYear->isCurrentSemesterExpired()) {
            return null;
        }

        $currentSemester = $activeYear->getCurrentSemester();
        $nextSemester = $this->getNextSemester($currentSemester);

        if (!$nextSemester) {
            return null;
        }

        $oldDisplay = $activeYear->getFullDisplayName();

        // If moving from Summer to 1st, we need the next academic year
        if ($currentSemester === 'Summer') {
            return $this->transitionToNextAcademicYear($activeYear, $oldDisplay);
        }

        // Otherwise, transition within the same academic year
        $activeYear->setCurrentSemester($nextSemester);
        $activeYear->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        $this->logger?->info(sprintf(
            'Auto-transitioned semester from "%s" to "%s"',
            $oldDisplay,
            $activeYear->getFullDisplayName()
        ));

        return [
            'transitioned' => true,
            'from' => $oldDisplay,
            'to' => $activeYear->getFullDisplayName(),
            'academic_year' => $activeYear,
            'crossed_year' => false,
        ];
    }

    /**
     * Transition from the current academic year's Summer to the next year's 1st semester
     */
    private function transitionToNextAcademicYear(AcademicYear $currentYear, string $oldDisplay): ?array
    {
        // Calculate the next academic year string (e.g., "2025-2026" -> "2026-2027")
        $yearParts = explode('-', $currentYear->getYear());
        if (count($yearParts) !== 2) {
            return null;
        }

        $nextYearStart = (int)$yearParts[0] + 1;
        $nextYearEnd = (int)$yearParts[1] + 1;
        $nextYearString = $nextYearStart . '-' . $nextYearEnd;

        // Try to find the next academic year
        $nextYear = $this->academicYearRepository->findOneBy([
            'year' => $nextYearString,
            'isActive' => true,
        ]);

        if (!$nextYear || $nextYear->getDeletedAt() !== null) {
            $this->logger?->warning(sprintf(
                'Auto-transition: Summer semester of %s has expired but next academic year "%s" does not exist or is inactive. Manual intervention required.',
                $currentYear->getYear(),
                $nextYearString
            ));

            return [
                'transitioned' => false,
                'from' => $oldDisplay,
                'to' => null,
                'reason' => sprintf('Next academic year "%s" not found or inactive. Please create it and set it as active.', $nextYearString),
                'academic_year' => $currentYear,
                'crossed_year' => true,
            ];
        }

        // Unset current from all years
        $this->unsetAllCurrentYears($nextYear->getId());

        // Set the next year as current with 1st semester
        $nextYear->setIsCurrent(true);
        $nextYear->setCurrentSemester('1st');
        $nextYear->setUpdatedAt(new \DateTime());

        // Also unset the old year
        $currentYear->setIsCurrent(false);
        $currentYear->setCurrentSemester(null);
        $currentYear->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        $this->logger?->info(sprintf(
            'Auto-transitioned semester from "%s" to "%s" (new academic year)',
            $oldDisplay,
            $nextYear->getFullDisplayName()
        ));

        return [
            'transitioned' => true,
            'from' => $oldDisplay,
            'to' => $nextYear->getFullDisplayName(),
            'academic_year' => $nextYear,
            'crossed_year' => true,
        ];
    }

    /**
     * Get auto-transition status information for the UI.
     * Includes data for ALL 3 semesters so the template can render a full timeline.
     */
    public function getAutoTransitionStatus(): array
    {
        $activeYear = $this->getActiveAcademicYear();
        if (!$activeYear || !$activeYear->getCurrentSemester()) {
            return [
                'has_dates' => false,
                'is_expired' => false,
                'days_remaining' => null,
                'semester_start' => null,
                'semester_end' => null,
                'next_semester' => null,
                'all_semesters' => [],
            ];
        }

        $currentSemester = $activeYear->getCurrentSemester();
        $dates = $activeYear->getSemesterDates($currentSemester);
        $daysRemaining = $activeYear->getCurrentSemesterDaysRemaining();
        $isExpired = $activeYear->isCurrentSemesterExpired();
        $nextSemester = $this->getNextSemester($currentSemester);

        // Build next semester display
        $nextDisplay = null;
        if ($nextSemester) {
            if ($currentSemester === 'Summer') {
                $yearParts = explode('-', $activeYear->getYear());
                $nextYearString = ((int)$yearParts[0] + 1) . '-' . ((int)$yearParts[1] + 1);
                $nextDisplay = $nextYearString . ' | ' . $nextSemester . ' Semester';
            } else {
                $nextDisplay = $activeYear->getYear() . ' | ' . $nextSemester . ' Semester';
            }
        }

        // Build the full timeline for all 3 semesters
        $today = new \DateTime('today');
        $allSemesters = [];
        foreach (self::VALID_SEMESTERS as $sem) {
            $semDates = $activeYear->getSemesterDates($sem);
            $isActive = ($sem === $currentSemester);
            $semExpired = false;
            $semDaysRemaining = null;
            $semStatus = 'no_dates'; // no_dates | upcoming | active | completed

            if ($semDates['end'] !== null) {
                $endDate = $semDates['end'] instanceof \DateTimeInterface
                    ? \DateTime::createFromInterface($semDates['end'])->setTime(23, 59, 59)
                    : $semDates['end'];
                $semExpired = $today > $endDate;
                $diff = $today->diff($endDate);
                $semDaysRemaining = $semExpired ? 0 : (int) $diff->days;

                if ($isActive) {
                    $semStatus = $semExpired ? 'expired' : 'active';
                } elseif ($semExpired) {
                    $semStatus = 'completed';
                } else {
                    $semStatus = 'upcoming';
                }
            }

            $allSemesters[$sem] = [
                'label' => $sem === 'Summer' ? 'Summer' : $sem . ' Semester',
                'start' => $semDates['start']?->format('M d, Y'),
                'end' => $semDates['end']?->format('M d, Y'),
                'start_raw' => $semDates['start']?->format('Y-m-d'),
                'end_raw' => $semDates['end']?->format('Y-m-d'),
                'has_dates' => $semDates['end'] !== null,
                'is_active' => $isActive,
                'is_expired' => $semExpired,
                'days_remaining' => $semDaysRemaining,
                'status' => $semStatus,
            ];
        }

        return [
            'has_dates' => $dates['end'] !== null,
            'is_expired' => $isExpired,
            'days_remaining' => $daysRemaining,
            'semester_start' => $dates['start']?->format('M d, Y'),
            'semester_end' => $dates['end']?->format('M d, Y'),
            'semester_start_raw' => $dates['start']?->format('Y-m-d'),
            'semester_end_raw' => $dates['end']?->format('Y-m-d'),
            'next_semester' => $nextDisplay,
            'all_semesters' => $allSemesters,
        ];
    }
}
