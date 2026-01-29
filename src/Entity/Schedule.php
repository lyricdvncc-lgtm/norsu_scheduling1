<?php

namespace App\Entity;

use App\Repository\ScheduleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ScheduleRepository::class)]
#[ORM\Table(name: 'schedules')]
#[ORM\Index(name: 'schedules_conflict_check_index', columns: ['room_id', 'day_pattern', 'start_time', 'end_time'])]
#[ORM\HasLifecycleCallbacks]
class Schedule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false)]
    private ?AcademicYear $academicYear = null;

    #[ORM\Column(name: 'semester', type: Types::STRING, length: 10)]
    #[Assert\NotBlank(message: 'Semester is required')]
    private ?string $semester = null;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false)]
    private ?Subject $subject = null;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: false)]
    private ?Room $room = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'faculty_id', referencedColumnName: 'id', nullable: true)]
    private ?User $faculty = null;

    #[ORM\Column(name: 'day_pattern', type: Types::STRING, length: 255, nullable: true)]
    private ?string $dayPattern = null;

    #[ORM\Column(name: 'start_time', type: Types::TIME_MUTABLE)]
    #[Assert\NotBlank(message: 'Start time is required')]
    private ?\DateTimeInterface $startTime = null;

    #[ORM\Column(name: 'end_time', type: Types::TIME_MUTABLE)]
    #[Assert\NotBlank(message: 'End time is required')]
    private ?\DateTimeInterface $endTime = null;

    #[ORM\Column(name: 'section', type: Types::STRING, length: 255, nullable: true)]
    private ?string $section = null;

    #[ORM\Column(name: 'enrolled_students', type: Types::INTEGER, options: ['default' => 0])]
    private ?int $enrolledStudents = 0;

    #[ORM\Column(name: 'is_conflicted', type: Types::BOOLEAN, options: ['default' => false])]
    private ?bool $isConflicted = false;

    #[ORM\Column(name: 'is_overload', type: Types::BOOLEAN, options: ['default' => false])]
    private ?bool $isOverload = false;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, options: ['default' => 'active'])]
    private ?string $status = 'active';

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = 'active';
        $this->enrolledStudents = 0;
        $this->isConflicted = false;
        $this->isOverload = false;
    }

    /**
     * Get individual days from pattern
     */
    public function getDaysFromPattern(): array
    {
        $pattern = $this->dayPattern;
        
        if ($pattern === 'M-W-F') {
            return ['Monday', 'Wednesday', 'Friday'];
        } elseif ($pattern === 'T-TH') {
            return ['Tuesday', 'Thursday'];
        } elseif ($pattern === 'M-T-TH-F') {
            return ['Monday', 'Tuesday', 'Thursday', 'Friday'];
        } elseif ($pattern === 'M-T') {
            return ['Monday', 'Tuesday'];
        } elseif ($pattern === 'TH-F') {
            return ['Thursday', 'Friday'];
        } elseif ($pattern === 'SAT') {
            return ['Saturday'];
        } elseif ($pattern === 'SUN') {
            return ['Sunday'];
        }

        return [];
    }

    public function getDayPatternLabel(): string
    {
        $labels = [
            'M-W-F' => 'Monday-Wednesday-Friday',
            'T-TH' => 'Tuesday-Thursday',
            'M-T-TH-F' => 'Monday-Tuesday-Thursday-Friday',
            'M-T' => 'Monday-Tuesday',
            'TH-F' => 'Thursday-Friday',
            'SAT' => 'Saturday',
            'SUN' => 'Sunday',
        ];

        return $labels[$this->dayPattern] ?? $this->dayPattern;
    }

    // Getters and Setters
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAcademicYear(): ?AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(?AcademicYear $academicYear): self
    {
        $this->academicYear = $academicYear;
        return $this;
    }

    public function getSemester(): ?string
    {
        return $this->semester;
    }

    public function setSemester(?string $semester): self
    {
        $this->semester = $semester;
        return $this;
    }

    public function getSubject(): ?Subject
    {
        return $this->subject;
    }

    public function setSubject(?Subject $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function setRoom(?Room $room): self
    {
        $this->room = $room;
        return $this;
    }

    public function getFaculty(): ?User
    {
        return $this->faculty;
    }

    public function setFaculty(?User $faculty): self
    {
        $this->faculty = $faculty;
        return $this;
    }

    public function getDayPattern(): ?string
    {
        return $this->dayPattern;
    }

    public function setDayPattern(?string $dayPattern): self
    {
        $this->dayPattern = $dayPattern;
        return $this;
    }

    public function getStartTime(): ?\DateTimeInterface
    {
        return $this->startTime;
    }

    public function setStartTime(?\DateTimeInterface $startTime): self
    {
        $this->startTime = $startTime;
        return $this;
    }

    public function getEndTime(): ?\DateTimeInterface
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTimeInterface $endTime): self
    {
        $this->endTime = $endTime;
        return $this;
    }

    public function getSection(): ?string
    {
        return $this->section;
    }

    public function setSection(?string $section): self
    {
        $this->section = $section;
        return $this;
    }

    public function getEnrolledStudents(): ?int
    {
        return $this->enrolledStudents;
    }

    public function setEnrolledStudents(?int $enrolledStudents): self
    {
        $this->enrolledStudents = $enrolledStudents;
        return $this;
    }

    public function getIsConflicted(): ?bool
    {
        return $this->isConflicted;
    }

    public function setIsConflicted(?bool $isConflicted): self
    {
        $this->isConflicted = $isConflicted;
        return $this;
    }

    public function getIsOverload(): ?bool
    {
        return $this->isOverload;
    }

    public function setIsOverload(?bool $isOverload): self
    {
        $this->isOverload = $isOverload;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
