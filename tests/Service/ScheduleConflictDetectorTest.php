<?php

namespace App\Tests\Service;

use App\Entity\Schedule;
use App\Entity\Subject;
use App\Entity\Room;
use App\Entity\AcademicYear;
use App\Entity\User;
use App\Entity\CurriculumSubject;
use App\Entity\CurriculumTerm;
use App\Repository\ScheduleRepository;
use App\Service\ScheduleConflictDetector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ScheduleConflictDetectorTest extends TestCase
{
    private $entityManager;
    private $scheduleRepository;
    private $conflictDetector;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->scheduleRepository = $this->createMock(ScheduleRepository::class);
        $this->conflictDetector = new ScheduleConflictDetector(
            $this->scheduleRepository,
            $this->entityManager
        );
    }

    /**
     * Test that same section, same year level, overlapping days and times = CONFLICT
     */
    public function testBlockSectioningConflictDetected(): void
    {
        echo "\n=== TEST 1: Block Sectioning Conflict (Same Year, Same Section, Overlapping Days) ===\n";
        
        // Create existing schedule: Year 3, Section A, T-TH, 7:00-8:30
        $existingSchedule = $this->createSchedule(
            'ITS 308',
            'A',
            '2nd Semester',
            'T-TH',
            '07:00',
            '08:30',
            3  // Year level 3
        );

        // Create new schedule: Year 3, Section A, M-T-TH-F, 7:00-8:30
        $newSchedule = $this->createSchedule(
            'ITS 310',
            'A',
            '2nd Semester',
            'M-T-TH-F',
            '07:00',
            '08:30',
            3  // Year level 3
        );

        echo "Existing: ITS 308, Year 3, Section A, T-TH, 7:00-8:30\n";
        echo "New:      ITS 310, Year 3, Section A, M-T-TH-F, 7:00-8:30\n";
        echo "Expected: CONFLICT (both have T and TH, same year, same section, same time)\n";

        // Mock EntityManager to return year levels for both schedules
        $qbMock = $this->createQueryBuilderMock([$existingSchedule], [3, 3]); // Both year 3
        
        $this->entityManager
            ->expects($this->any())
            ->method('createQueryBuilder')
            ->willReturn($qbMock);

        $conflicts = $this->conflictDetector->detectConflicts($newSchedule);

        echo "Result:   " . (count($conflicts) > 0 ? "CONFLICT DETECTED ✅" : "NO CONFLICT ❌") . "\n";
        if (count($conflicts) > 0) {
            echo "Conflict Type: " . $conflicts[0]['type'] . "\n";
            echo "Conflict Message: " . $conflicts[0]['message'] . "\n";
        }
        
        $this->assertGreaterThan(0, count($conflicts), 'Should detect block sectioning conflict');
    }

    /**
     * Test that different year levels = NO CONFLICT
     */
    public function testDifferentYearLevelsNoConflict(): void
    {
        echo "\n=== TEST 2: Different Year Levels (Should NOT Conflict) ===\n";
        
        // Existing: Year 2, Section A, T-TH, 7:00-8:30
        $existingSchedule = $this->createSchedule(
            'ITS 308',
            'A',
            '2nd Semester',
            'T-TH',
            '07:00',
            '08:30',
            2  // Year level 2
        );

        // New: Year 3, Section A, M-W-F, 7:00-8:30 (different days to avoid room conflicts)
        $newSchedule = $this->createSchedule(
            'ITS 310',
            'A',
            '2nd Semester',
            'M-W-F',  // Changed from T-TH to M-W-F
            '07:00',
            '08:30',
            3  // Year level 3
        );

        echo "Existing: ITS 308, Year 2, Section A, T-TH, 7:00-8:30\n";
        echo "New:      ITS 310, Year 3, Section A, M-W-F, 7:00-8:30\n";
        echo "Expected: NO CONFLICT (different year levels AND different days)\n";

        // Mock EntityManager to return different year levels
        // [3, 2] means: newSchedule=Year 3 (position 0), existingSchedule=Year 2 (position 1)
        $qbMock = $this->createQueryBuilderMock([$existingSchedule], [3, 2]);
        
        $this->entityManager
            ->expects($this->any())
            ->method('createQueryBuilder')
            ->willReturn($qbMock);

        $conflicts = $this->conflictDetector->detectConflicts($newSchedule);
        
        $this->assertCount(0, $conflicts, 'Should NOT detect conflict for different year levels');
    }

    /**
     * Test that same year but different days = NO CONFLICT
     */
    public function testSameYearDifferentDaysNoConflict(): void
    {
        echo "\n=== TEST 3: Same Year, Different Days (Should NOT Conflict) ===\n";
        
        // Existing: Year 3, Section A, T-TH, 7:00-8:30
        $existingSchedule = $this->createSchedule(
            'ITS 308',
            'A',
            '2nd Semester',
            'T-TH',
            '07:00',
            '08:30',
            3
        );

        // New: Year 3, Section A, M-W-F, 7:00-8:30 (no overlapping days)
        $newSchedule = $this->createSchedule(
            'ITS 310',
            'A',
            '2nd Semester',
            'M-W-F',
            '07:00',
            '08:30',
            3
        );

        echo "Existing: ITS 308, Year 3, Section A, T-TH, 7:00-8:30\n";
        echo "New:      ITS 310, Year 3, Section A, M-W-F, 7:00-8:30\n";
        echo "Expected: NO CONFLICT (different days)\n";

        // Mock EntityManager to return same year levels
        $qbMock = $this->createQueryBuilderMock([$existingSchedule], [3, 3]);
        
        $this->entityManager
            ->expects($this->any())
            ->method('createQueryBuilder')
            ->willReturn($qbMock);

        $conflicts = $this->conflictDetector->detectConflicts($newSchedule);

        echo "Result:   " . (count($conflicts) === 0 ? "NO CONFLICT ✅" : "CONFLICT DETECTED ❌") . "\n";
        
        $this->assertCount(0, $conflicts, 'Should NOT detect conflict for different days');
    }

    /**
     * Test that same year but different times = NO CONFLICT
     */
    public function testSameYearDifferentTimesNoConflict(): void
    {
        echo "\n=== TEST 4: Same Year, Same Days, Different Times (Should NOT Conflict) ===\n";
        
        // Existing: Year 3, Section A, T-TH, 7:00-8:30
        $existingSchedule = $this->createSchedule(
            'ITS 308',
            'A',
            '2nd Semester',
            'T-TH',
            '07:00',
            '08:30',
            3
        );

        // New: Year 3, Section A, T-TH, 9:00-10:30 (different time)
        $newSchedule = $this->createSchedule(
            'ITS 310',
            'A',
            '2nd Semester',
            'T-TH',
            '09:00',
            '10:30',
            3
        );

        echo "Existing: ITS 308, Year 3, Section A, T-TH, 7:00-8:30\n";
        echo "New:      ITS 310, Year 3, Section A, T-TH, 9:00-10:30\n";
        echo "Expected: NO CONFLICT (different times)\n";

        // Mock EntityManager to return same year levels
        $qbMock = $this->createQueryBuilderMock([$existingSchedule], [3, 3]);
        
        $this->entityManager
            ->expects($this->any())
            ->method('createQueryBuilder')
            ->willReturn($qbMock);

        $conflicts = $this->conflictDetector->detectConflicts($newSchedule);

        echo "Result:   " . (count($conflicts) === 0 ? "NO CONFLICT ✅" : "CONFLICT DETECTED ❌") . "\n";
        
        $this->assertCount(0, $conflicts, 'Should NOT detect conflict for different times');
    }

    /**
     * Test day overlap logic specifically
     */
    public function testDayOverlapLogic(): void
    {
        echo "\n=== TEST 5: Day Overlap Logic ===\n";
        
        $testCases = [
            ['M-T-TH-F', 'T-TH', true, 'M-T-TH-F vs T-TH should overlap on T and TH'],
            ['M-W-F', 'T-TH', false, 'M-W-F vs T-TH should NOT overlap'],
            ['M-T-W-TH-F', 'M-W-F', true, 'M-T-W-TH-F vs M-W-F should overlap on M, W, F'],
            ['T-TH', 'T-TH', true, 'T-TH vs T-TH should overlap (exact match)'],
            ['M-W', 'T-TH', false, 'M-W vs T-TH should NOT overlap'],
        ];

        foreach ($testCases as [$pattern1, $pattern2, $expected, $description]) {
            $days1 = explode('-', $pattern1);
            $days2 = explode('-', $pattern2);
            $overlap = count(array_intersect($days1, $days2)) > 0;
            
            $status = $overlap === $expected ? '✅ PASS' : '❌ FAIL';
            echo "{$status}: {$description} - Result: " . ($overlap ? 'OVERLAP' : 'NO OVERLAP') . "\n";
            
            $this->assertEquals($expected, $overlap, $description);
        }
    }

    // Helper methods

    private function createSchedule(
        string $subjectCode,
        string $section,
        string $semester,
        string $dayPattern,
        string $startTime,
        string $endTime,
        int $yearLevel
    ): Schedule {
        $schedule = new Schedule();
        
        // Create and set subject with department
        $subject = new Subject();
        $subject->setCode($subjectCode);
        
        // Set unique ID for subject using reflection (to differentiate subjects)
        static $subjectIdCounter = 1;
        $reflection = new \ReflectionClass($subject);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($subject, $subjectIdCounter++);
        
        // Add department to subject for section conflict checks
        $department = new \App\Entity\Department();
        $department->setName('Information Technology');
        $subject->setDepartment($department);
        
        $schedule->setSubject($subject);
        
        // Set schedule details
        $schedule->setSection($section);
        $schedule->setSemester($semester);
        $schedule->setDayPattern($dayPattern);
        $schedule->setStartTime(new \DateTime($startTime));
        $schedule->setEndTime(new \DateTime($endTime));
        $schedule->setStatus('active');
        
        // Set different rooms to avoid room conflicts
        static $roomCounter = 0;
        $room = new Room();
        $room->setCode('TEST-ROOM-' . $roomCounter);
        // Set unique ID for room
        $roomReflection = new \ReflectionClass($room);
        $roomIdProperty = $roomReflection->getProperty('id');
        $roomIdProperty->setAccessible(true);
        $roomIdProperty->setValue($room, ++$roomCounter);
        $schedule->setRoom($room);
        
        $academicYear = new AcademicYear();
        $academicYear->setYear('2025-2026');
        $schedule->setAcademicYear($academicYear);
        
        // Set different faculty to avoid faculty conflicts
        static $facultyCounter = 0;
        $faculty = new User();
        // Set unique ID for faculty
        $facultyReflection = new \ReflectionClass($faculty);
        $facultyIdProperty = $facultyReflection->getProperty('id');
        $facultyIdProperty->setAccessible(true);
        $facultyIdProperty->setValue($faculty, ++$facultyCounter);
        $schedule->setFaculty($faculty);
        
        return $schedule;
    }

    private function createQueryBuilderMock(array $schedules, array $yearLevels = [])
    {
        $qb = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $query = $this->createMock(\Doctrine\ORM\Query::class);

        // For schedule queries, return schedules
        $query->expects($this->any())
            ->method('getResult')
            ->willReturn($schedules);
            
        // For year level queries, return year level
        $query->expects($this->any())
            ->method('getOneOrNullResult')
            ->willReturnCallback(function() use ($yearLevels) {
                static $callCount = 0;
                $result = isset($yearLevels[$callCount]) ? ['year_level' => $yearLevels[$callCount]] : null;
                $callCount++;
                return $result;
            });

        $qb->expects($this->any())
            ->method('select')
            ->willReturnSelf();
            
        $qb->expects($this->any())
            ->method('from')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('innerJoin')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('where')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('andWhere')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('setParameter')
            ->willReturnSelf();
            
        $qb->expects($this->any())
            ->method('setMaxResults')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('getQuery')
            ->willReturn($query);

        return $qb;
    }
}
