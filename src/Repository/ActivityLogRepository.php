<?php

namespace App\Repository;

use App\Entity\ActivityLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLog>
 */
class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    /**
     * Find recent activities
     */
    public function findRecentActivities(int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find activities by user
     */
    public function findByUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find activities by action type
     */
    public function findByAction(string $action, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->where('a.action = :action')
            ->setParameter('action', $action)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find activities by entity
     */
    public function findByEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->where('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find activities within date range
     */
    public function findByDateRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->where('a.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get activity statistics
     */
    public function getActivityStats(int $days = 30): array
    {
        $fromDate = new \DateTime("-{$days} days");
        
        // Total activities
        $totalActivities = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt >= :date')
            ->setParameter('date', $fromDate)
            ->getQuery()
            ->getSingleScalarResult();

        // Activities by type
        $activitiesByType = $this->createQueryBuilder('a')
            ->select('a.action, COUNT(a.id) as count')
            ->where('a.createdAt >= :date')
            ->setParameter('date', $fromDate)
            ->groupBy('a.action')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        // Most active users
        $mostActiveUsers = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.user) as userId, COUNT(a.id) as activityCount')
            ->where('a.createdAt >= :date')
            ->andWhere('a.user IS NOT NULL')
            ->setParameter('date', $fromDate)
            ->groupBy('a.user')
            ->orderBy('activityCount', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return [
            'total' => (int) $totalActivities,
            'by_type' => $activitiesByType,
            'most_active_users' => $mostActiveUsers,
        ];
    }

    /**
     * Clean old activities (for maintenance)
     */
    public function cleanOldActivities(int $daysToKeep = 365): int
    {
        $cutoffDate = new \DateTime("-{$daysToKeep} days");
        
        return $this->createQueryBuilder('a')
            ->delete()
            ->where('a.createdAt < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();
    }

    /**
     * Save activity log
     */
    public function save(ActivityLog $activityLog): void
    {
        $this->getEntityManager()->persist($activityLog);
        $this->getEntityManager()->flush();
    }
}
