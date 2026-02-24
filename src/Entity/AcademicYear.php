<?php

namespace App\Entity;

use App\Repository\AcademicYearRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: AcademicYearRepository::class)]
#[ORM\Table(name: 'academic_years')]
#[UniqueEntity(fields: ['year'], message: 'This academic year already exists')]
class AcademicYear
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Academic year is required')]
    #[Assert\Regex(
        pattern: '/^\d{4}-\d{4}$/',
        message: 'Academic year must be in format YYYY-YYYY (e.g., 2024-2025)'
    )]
    private ?string $year = null;

    #[ORM\Column(name: 'start_date', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $endDate = null;

    #[ORM\Column(name: 'is_current', type: Types::BOOLEAN, nullable: true)]
    private ?bool $isCurrent = false;

    #[ORM\Column(name: 'current_semester', type: Types::STRING, length: 20, nullable: true)]
    #[Assert\Choice(
        choices: ['1st', '2nd', 'Summer'],
        message: 'Semester must be either 1st, 2nd, or Summer'
    )]
    private ?string $currentSemester = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, nullable: true)]
    private ?bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deletedAt = null;

    // Per-semester date fields
    #[ORM\Column(name: 'first_sem_start', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $firstSemStart = null;

    #[ORM\Column(name: 'first_sem_end', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $firstSemEnd = null;

    #[ORM\Column(name: 'second_sem_start', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $secondSemStart = null;

    #[ORM\Column(name: 'second_sem_end', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $secondSemEnd = null;

    #[ORM\Column(name: 'summer_start', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $summerStart = null;

    #[ORM\Column(name: 'summer_end', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $summerEnd = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->isActive = true;
        $this->isCurrent = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getYear(): ?string
    {
        return $this->year;
    }

    public function setYear(string $year): static
    {
        $this->year = $year;
        return $this;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeInterface $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeInterface $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function isCurrent(): ?bool
    {
        return $this->isCurrent;
    }

    public function setIsCurrent(?bool $isCurrent): static
    {
        $this->isCurrent = $isCurrent;
        return $this;
    }

    public function getCurrentSemester(): ?string
    {
        return $this->currentSemester;
    }

    public function setCurrentSemester(?string $currentSemester): static
    {
        $this->currentSemester = $currentSemester;
        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(?bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getDeletedAt(): ?\DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeInterface $deletedAt): static
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    /**
     * Get display name with status badges
     */
    public function getDisplayName(): string
    {
        $name = $this->year;
        if ($this->isCurrent) {
            $name .= ' (Current';
            if ($this->currentSemester) {
                $name .= ' - ' . $this->currentSemester . ' Semester';
            }
            $name .= ')';
        }
        return $name;
    }

    /**
     * Get full display name with semester
     */
    public function getFullDisplayName(): string
    {
        $name = $this->year;
        if ($this->currentSemester) {
            $name .= ' | ' . $this->currentSemester . ' Semester';
        }
        return $name;
    }

    /**
     * Check if year is in the past
     */
    public function isPast(): bool
    {
        if (!$this->endDate) {
            return false;
        }
        return $this->endDate < new \DateTime();
    }

    /**
     * Check if year is in the future
     */
    public function isFuture(): bool
    {
        if (!$this->startDate) {
            return false;
        }
        return $this->startDate > new \DateTime();
    }

    /**
     * Check if year is currently active (between start and end date)
     */
    public function isCurrentlyActive(): bool
    {
        $now = new \DateTime();
        
        if (!$this->startDate || !$this->endDate) {
            return false;
        }
        
        return $now >= $this->startDate && $now <= $this->endDate;
    }

    // --- Per-semester date getters/setters ---

    public function getFirstSemStart(): ?\DateTimeInterface
    {
        return $this->firstSemStart;
    }

    public function setFirstSemStart(?\DateTimeInterface $firstSemStart): static
    {
        $this->firstSemStart = $firstSemStart;
        return $this;
    }

    public function getFirstSemEnd(): ?\DateTimeInterface
    {
        return $this->firstSemEnd;
    }

    public function setFirstSemEnd(?\DateTimeInterface $firstSemEnd): static
    {
        $this->firstSemEnd = $firstSemEnd;
        return $this;
    }

    public function getSecondSemStart(): ?\DateTimeInterface
    {
        return $this->secondSemStart;
    }

    public function setSecondSemStart(?\DateTimeInterface $secondSemStart): static
    {
        $this->secondSemStart = $secondSemStart;
        return $this;
    }

    public function getSecondSemEnd(): ?\DateTimeInterface
    {
        return $this->secondSemEnd;
    }

    public function setSecondSemEnd(?\DateTimeInterface $secondSemEnd): static
    {
        $this->secondSemEnd = $secondSemEnd;
        return $this;
    }

    public function getSummerStart(): ?\DateTimeInterface
    {
        return $this->summerStart;
    }

    public function setSummerStart(?\DateTimeInterface $summerStart): static
    {
        $this->summerStart = $summerStart;
        return $this;
    }

    public function getSummerEnd(): ?\DateTimeInterface
    {
        return $this->summerEnd;
    }

    public function setSummerEnd(?\DateTimeInterface $summerEnd): static
    {
        $this->summerEnd = $summerEnd;
        return $this;
    }

    /**
     * Get the start/end dates for a specific semester
     * @return array{start: ?\DateTimeInterface, end: ?\DateTimeInterface}
     */
    public function getSemesterDates(?string $semester = null): array
    {
        $semester = $semester ?? $this->currentSemester;

        return match ($semester) {
            '1st' => ['start' => $this->firstSemStart, 'end' => $this->firstSemEnd],
            '2nd' => ['start' => $this->secondSemStart, 'end' => $this->secondSemEnd],
            'Summer' => ['start' => $this->summerStart, 'end' => $this->summerEnd],
            default => ['start' => null, 'end' => null],
        };
    }

    /**
     * Set the start/end dates for a specific semester
     */
    public function setSemesterDates(string $semester, ?\DateTimeInterface $start, ?\DateTimeInterface $end): static
    {
        match ($semester) {
            '1st' => (function() use ($start, $end) {
                $this->firstSemStart = $start;
                $this->firstSemEnd = $end;
            })(),
            '2nd' => (function() use ($start, $end) {
                $this->secondSemStart = $start;
                $this->secondSemEnd = $end;
            })(),
            'Summer' => (function() use ($start, $end) {
                $this->summerStart = $start;
                $this->summerEnd = $end;
            })(),
            default => null,
        };

        return $this;
    }

    /**
     * Check if the current semester has expired (end date has passed)
     */
    public function isCurrentSemesterExpired(): bool
    {
        if (!$this->currentSemester) {
            return false;
        }

        $dates = $this->getSemesterDates($this->currentSemester);
        if (!$dates['end']) {
            return false;
        }

        $now = new \DateTime('today');
        return $now > $dates['end'];
    }

    /**
     * Get the number of days remaining in the current semester
     * Returns null if no end date is set, negative if already expired
     */
    public function getCurrentSemesterDaysRemaining(): ?int
    {
        if (!$this->currentSemester) {
            return null;
        }

        $dates = $this->getSemesterDates($this->currentSemester);
        if (!$dates['end']) {
            return null;
        }

        $now = new \DateTime('today');
        $diff = $now->diff($dates['end']);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    public function __toString(): string
    {
        return $this->year ?? '';
    }
}
