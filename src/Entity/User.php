<?php

namespace App\Entity;

use App\Repository\UserRepository;
use App\Entity\College;
use App\Entity\Department;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(name: 'idx_email', columns: ['email'])]
#[ORM\Index(name: 'idx_username', columns: ['username'])]
#[ORM\Index(name: 'idx_employee_id', columns: ['employee_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'UNIQ_username', columns: ['username'])]
#[ORM\UniqueConstraint(name: 'UNIQ_employee_id', columns: ['employee_id'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
#[UniqueEntity(fields: ['username'], message: 'There is already an account with this username')]
#[UniqueEntity(fields: ['employeeId'], message: 'This Employee ID is already in use')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    private string $username;

    #[ORM\Column(name: 'firstname', length: 255, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(name: 'middlename', length: 255, nullable: true)]
    private ?string $middleName = null;

    #[ORM\Column(name: 'lastname', length: 255, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    #[ORM\Column(name: 'email_verified_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $emailVerifiedAt = null;

    #[ORM\Column(length: 255)]
    private string $password;

    #[ORM\Column(type: 'integer')]
    private ?int $role = null;

    #[ORM\ManyToOne(targetEntity: College::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'college_id', referencedColumnName: 'id', nullable: true)]
    private ?College $college = null;

    #[ORM\ManyToOne(targetEntity: Department::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'department_id', referencedColumnName: 'id', nullable: true)]
    private ?Department $department = null;

    #[ORM\Column(name: 'employee_id', length: 255, nullable: true)]
    #[Assert\NotBlank(message: 'Employee ID is required')]
    #[Assert\Length(min: 6, max: 15, minMessage: 'Employee ID must be at least 6 characters', maxMessage: 'Employee ID cannot exceed 15 characters')]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9]+$/', message: 'Employee ID must contain only letters and numbers')]
    private ?string $employeeId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $position = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $address = null;

    #[ORM\Column(name: 'is_active', type: 'boolean', nullable: true)]
    private ?bool $isActive = true;

    #[ORM\Column(name: 'last_login', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastLogin = null;

    #[ORM\Column(name: 'remember_token', length: 100, nullable: true)]
    private ?string $rememberToken = null;

    #[ORM\Column(name: 'created_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(name: 'deleted_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $deletedAt = null;

    #[ORM\Column(name: 'preferred_semester_filter', length: 20, nullable: true)]
    private ?string $preferredSemesterFilter = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->isActive = true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        // Map database role enum to Symfony security roles
        $roles = ['ROLE_USER'];
        
        switch ($this->role) {
            case 1: // Admin
                $roles[] = 'ROLE_ADMIN';
                break;
            case 2: // Department Head
                $roles[] = 'ROLE_DEPARTMENT_HEAD';
                break;
            case 3: // Faculty
                $roles[] = 'ROLE_FACULTY';
                break;
        }

        return array_unique($roles);
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getMiddleName(): ?string
    {
        return $this->middleName;
    }

    public function setMiddleName(?string $middleName): static
    {
        $this->middleName = $middleName;
        return $this;
    }

    public function getFullName(): string
    {
        $parts = array_filter([
            $this->firstName,
            $this->middleName,
            $this->lastName
        ]);
        return implode(' ', $parts);
    }

    public function getRole(): ?int
    {
        return $this->role;
    }

    public function setRole(int $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getRoleString(): string
    {
        return match($this->role) {
            1 => 'admin',
            2 => 'department_head',
            3 => 'faculty',
            default => 'user'
        };
    }

    public function setRoleFromString(string $roleString): static
    {
        $this->role = match($roleString) {
            'admin' => 1,
            'department_head' => 2,
            'faculty' => 3,
            default => 3
        };
        return $this;
    }

    public function getCollegeId(): ?int
    {
        return $this->college?->getId();
    }

    public function getCollege(): ?College
    {
        return $this->college;
    }

    public function setCollege(?College $college): static
    {
        $this->college = $college;
        return $this;
    }

    public function getDepartmentId(): ?int
    {
        return $this->department?->getId();
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): static
    {
        $this->department = $department;
        return $this;
    }

    public function getEmployeeId(): ?string
    {
        return $this->employeeId;
    }

    public function setEmployeeId(?string $employeeId): static
    {
        // Auto-pad employee ID with leading zeros to reach minimum 6 characters
        if ($employeeId !== null && $employeeId !== '') {
            // Remove any existing leading/trailing whitespace
            $employeeId = trim($employeeId);
            
            // If the employee ID is numeric, pad it with zeros
            if (is_numeric($employeeId)) {
                $employeeId = str_pad($employeeId, 6, '0', STR_PAD_LEFT);
            }
        }
        
        $this->employeeId = $employeeId;
        return $this;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
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

    public function getLastLogin(): ?\DateTimeInterface
    {
        return $this->lastLogin;
    }

    public function setLastLogin(?\DateTimeInterface $lastLogin): static
    {
        $this->lastLogin = $lastLogin;
        return $this;
    }

    public function getRememberToken(): ?string
    {
        return $this->rememberToken;
    }

    public function setRememberToken(?string $rememberToken): static
    {
        $this->rememberToken = $rememberToken;
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

    public function getRoleDisplayName(): string
    {
        return match($this->role) {
            1 => 'Administrator',
            2 => 'Department Head', 
            3 => 'Faculty',
            default => 'User'
        };
    }

    public function getEmailVerifiedAt(): ?\DateTimeInterface
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeInterface $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;
        return $this;
    }

    public function getPreferredSemesterFilter(): ?string
    {
        return $this->preferredSemesterFilter;
    }

    public function setPreferredSemesterFilter(?string $preferredSemesterFilter): static
    {
        $this->preferredSemesterFilter = $preferredSemesterFilter;
        return $this;
    }
}