<?php

namespace App\Service;

use App\Repository\UserRepository;
use App\Repository\CurriculumRepository;
use App\Repository\RoomRepository;
use App\Repository\ActivityLogRepository;
use App\Repository\CollegeRepository;
use App\Repository\DepartmentRepository;
use Doctrine\ORM\EntityManagerInterface;

class DashboardService
{
    private UserRepository $userRepository;
    private CurriculumRepository $curriculumRepository;
    private RoomRepository $roomRepository;
    private ActivityLogRepository $activityLogRepository;
    private CollegeRepository $collegeRepository;
    private DepartmentRepository $departmentRepository;
    private EntityManagerInterface $entityManager;
    private \App\Repository\SubjectRepository $subjectRepository;

    public function __construct(
        UserRepository $userRepository, 
        CurriculumRepository $curriculumRepository,
        RoomRepository $roomRepository,
        ActivityLogRepository $activityLogRepository,
        CollegeRepository $collegeRepository,
        DepartmentRepository $departmentRepository,
        EntityManagerInterface $entityManager,
        \App\Repository\SubjectRepository $subjectRepository
    ) {
        $this->userRepository = $userRepository;
        $this->curriculumRepository = $curriculumRepository;
        $this->roomRepository = $roomRepository;
        $this->activityLogRepository = $activityLogRepository;
        $this->collegeRepository = $collegeRepository;
        $this->departmentRepository = $departmentRepository;
        $this->entityManager = $entityManager;
        $this->subjectRepository = $subjectRepository;
    }

    public function getAdminDashboardData(): array
    {
        // Get total users count
        $totalUsers = $this->userRepository->count([]);

        // Get users by role
        $adminCount = $this->userRepository->count(['role' => 1]);
        $deptHeadCount = $this->userRepository->count(['role' => 2]);
        $facultyCount = $this->userRepository->count(['role' => 3]);

        // Get active users
        $activeUsers = $this->userRepository->count(['isActive' => true]);

        // Get recent users (last 30 days)
        $thirtyDaysAgo = new \DateTime('-30 days');
        $recentUsersCount = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt >= :date')
            ->setParameter('date', $thirtyDaysAgo)
            ->getQuery()
            ->getSingleScalarResult();

        // Get recent activity (last 5 users)
        $recentUsers = $this->userRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            5
        );

        // Get users created this month
        $thisMonthStart = new \DateTime('first day of this month 00:00:00');
        $thisMonthUsers = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt >= :date')
            ->setParameter('date', $thisMonthStart)
            ->getQuery()
            ->getSingleScalarResult();

        // Calculate growth percentage (mock calculation for demo)
        $lastMonthStart = new \DateTime('first day of last month 00:00:00');
        $lastMonthEnd = new \DateTime('last day of last month 23:59:59');
        $lastMonthUsers = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $lastMonthStart)
            ->setParameter('end', $lastMonthEnd)
            ->getQuery()
            ->getSingleScalarResult();

        $growthPercent = $lastMonthUsers > 0 ? 
            round((($thisMonthUsers - $lastMonthUsers) / $lastMonthUsers) * 100, 1) : 0;

        // Get curriculum statistics
        $curriculumStats = $this->curriculumRepository->getStatistics();
        
        // Get room statistics
        $roomStats = $this->roomRepository->getStatistics();

        // Get recent activities
        $recentActivities = $this->activityLogRepository->findRecentActivities(15);

        // Get college and department counts
        $collegeCount = $this->collegeRepository->count([]);
        $departmentCount = $this->departmentRepository->count([]);
        
        // Get subjects count
        $subjectCount = $this->subjectRepository->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.deletedAt IS NULL')
            ->andWhere('s.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_users' => $totalUsers,
            'admin_count' => $adminCount,
            'dept_head_count' => $deptHeadCount,
            'faculty_count' => $facultyCount,
            'active_users' => $activeUsers,
            'recent_users_count' => $recentUsersCount,
            'this_month_users' => $thisMonthUsers,
            'growth_percent' => $growthPercent,
            'recent_users' => $recentUsers,
            // Curriculum data
            'total_curriculums' => $curriculumStats['total'],
            'active_curriculums' => $curriculumStats['active'],
            'curriculum_stats' => $curriculumStats,
            // Room data
            'total_rooms' => $roomStats['total'],
            'available_rooms' => $roomStats['available'],
            'room_stats' => $roomStats,
            // College and Department data
            'college_count' => $collegeCount,
            'department_count' => $departmentCount,
            'total_subjects' => $subjectCount,
            // Activity data
            'recent_activities' => $recentActivities,
            'system_stats' => [
                'database_status' => 'healthy',
                'api_status' => 'running',
                'storage_usage' => 78,
                'last_backup' => new \DateTime('-2 hours'),
            ]
        ];
    }

    public function getRoleDisplayName(int $role): string
    {
        return match($role) {
            1 => 'Administrator',
            2 => 'Department Head',
            3 => 'Faculty Member',
            default => 'Unknown'
        };
    }

    public function getRoleColor(int $role): string
    {
        return match($role) {
            1 => 'bg-red-100 text-red-800',
            2 => 'bg-blue-100 text-blue-800',
            3 => 'bg-green-100 text-green-800',
            default => 'bg-gray-100 text-gray-800'
        };
    }

    /**
     * Get Department Head dashboard data (scoped to their department)
     */
    public function getDepartmentHeadDashboardData(\App\Entity\User $departmentHead): array
    {
        if ($departmentHead->getRole() !== 2) {
            throw new \InvalidArgumentException('User is not a department head.');
        }

        $department = $departmentHead->getDepartment();
        
        if (!$department) {
            throw new \RuntimeException('Department Head has no assigned department.');
        }

        // Get faculty count in department
        $totalFaculty = $this->userRepository->count([
            'role' => 3,
            'department' => $department
        ]);

        $activeFaculty = $this->userRepository->count([
            'role' => 3,
            'department' => $department,
            'isActive' => true
        ]);

        // Get recent faculty
        $thirtyDaysAgo = new \DateTime('-30 days');
        $recentFacultyCount = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.role = :role')
            ->andWhere('u.department = :dept')
            ->andWhere('u.createdAt >= :date')
            ->setParameter('role', 3)
            ->setParameter('dept', $department)
            ->setParameter('date', $thirtyDaysAgo)
            ->getQuery()
            ->getSingleScalarResult();

        // Get recent faculty list
        $recentFaculty = $this->userRepository->findBy(
            ['role' => 3, 'department' => $department],
            ['createdAt' => 'DESC'],
            5
        );

        // Get curriculum statistics
        $totalCurricula = $this->curriculumRepository->count(['department' => $department]);
        $publishedCurricula = $this->curriculumRepository->count([
            'department' => $department,
            'isPublished' => true
        ]);

        return [
            'department' => $department,
            'total_faculty' => $totalFaculty,
            'active_faculty' => $activeFaculty,
            'inactive_faculty' => $totalFaculty - $activeFaculty,
            'recent_faculty_count' => $recentFacultyCount,
            'recent_faculty' => $recentFaculty,
            'total_curricula' => $totalCurricula,
            'published_curricula' => $publishedCurricula,
            'draft_curricula' => $totalCurricula - $publishedCurricula,
        ];
    }
}
