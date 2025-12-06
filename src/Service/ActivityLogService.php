<?php

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\User;
use App\Repository\ActivityLogRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

class ActivityLogService
{
    private ActivityLogRepository $activityLogRepository;
    private RequestStack $requestStack;
    private Security $security;
    private LoggerInterface $logger;

    public function __construct(
        ActivityLogRepository $activityLogRepository,
        RequestStack $requestStack,
        Security $security,
        LoggerInterface $logger
    ) {
        $this->activityLogRepository = $activityLogRepository;
        $this->requestStack = $requestStack;
        $this->security = $security;
        $this->logger = $logger;
    }

    /**
     * Log an activity
     */
    public function log(
        string $action,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null,
        ?User $user = null
    ): ActivityLog {
        try {
            $activityLog = new ActivityLog();
            
            // Set user (either provided or current authenticated user)
            $logUser = $user ?? $this->security->getUser();
            if ($logUser instanceof User) {
                $activityLog->setUser($logUser);
            }

            // Set basic info
            $activityLog->setAction($action);
            $activityLog->setDescription($description);
            
            // Set entity info if provided
            if ($entityType) {
                $activityLog->setEntityType($entityType);
            }
            if ($entityId) {
                $activityLog->setEntityId($entityId);
            }
            
            // Set metadata
            if ($metadata) {
                $activityLog->setMetadata($metadata);
            }

            // Get request info
            $request = $this->requestStack->getCurrentRequest();
            if ($request) {
                $activityLog->setIpAddress($request->getClientIp());
                $activityLog->setUserAgent($request->headers->get('User-Agent'));
            }

            // Save
            $this->activityLogRepository->save($activityLog);

            return $activityLog;
        } catch (\Exception $e) {
            // Log error but don't throw - we don't want activity logging to break the app
            $this->logger->error('Failed to log activity', [
                'action' => $action,
                'error' => $e->getMessage()
            ]);
            
            // Return a dummy activity log
            $dummy = new ActivityLog();
            $dummy->setAction($action);
            $dummy->setDescription($description);
            return $dummy;
        }
    }

    /**
     * Log user-related activities
     */
    public function logUserActivity(string $action, User $targetUser, ?array $metadata = null): ActivityLog
    {
        $descriptions = [
            'user.created' => "New user {$targetUser->getFirstName()} {$targetUser->getLastName()} was created",
            'user.updated' => "User {$targetUser->getFirstName()} {$targetUser->getLastName()} was updated",
            'user.deleted' => "User {$targetUser->getFirstName()} {$targetUser->getLastName()} was deleted",
            'user.activated' => "User {$targetUser->getFirstName()} {$targetUser->getLastName()} was activated",
            'user.deactivated' => "User {$targetUser->getFirstName()} {$targetUser->getLastName()} was deactivated",
            'user.restored' => "User {$targetUser->getFirstName()} {$targetUser->getLastName()} was restored",
            'user.login' => "{$targetUser->getFirstName()} {$targetUser->getLastName()} logged in",
            'user.logout' => "{$targetUser->getFirstName()} {$targetUser->getLastName()} logged out",
        ];

        $description = $descriptions[$action] ?? "User activity: {$action}";

        return $this->log(
            $action,
            $description,
            'User',
            $targetUser->getId(),
            $metadata,
            $action === 'user.login' || $action === 'user.logout' ? $targetUser : null
        );
    }

    /**
     * Log schedule-related activities
     */
    public function logScheduleActivity(string $action, int $scheduleId, string $scheduleInfo, ?array $metadata = null): ActivityLog
    {
        $descriptions = [
            'schedule.created' => "Schedule created: {$scheduleInfo}",
            'schedule.updated' => "Schedule updated: {$scheduleInfo}",
            'schedule.deleted' => "Schedule deleted: {$scheduleInfo}",
            'schedule.approved' => "Schedule approved: {$scheduleInfo}",
            'schedule.rejected' => "Schedule rejected: {$scheduleInfo}",
        ];

        $description = $descriptions[$action] ?? "Schedule activity: {$action}";

        return $this->log($action, $description, 'Schedule', $scheduleId, $metadata);
    }

    /**
     * Log curriculum-related activities
     */
    public function logCurriculumActivity(string $action, int $curriculumId, string $curriculumName, ?array $metadata = null): ActivityLog
    {
        $descriptions = [
            'curriculum.created' => "Curriculum created: {$curriculumName}",
            'curriculum.updated' => "Curriculum updated: {$curriculumName}",
            'curriculum.deleted' => "Curriculum deleted: {$curriculumName}",
        ];

        $description = $descriptions[$action] ?? "Curriculum activity: {$action}";

        return $this->log($action, $description, 'Curriculum', $curriculumId, $metadata);
    }

    /**
     * Log room-related activities
     */
    public function logRoomActivity(string $action, int $roomId, string $roomName, ?array $metadata = null): ActivityLog
    {
        $descriptions = [
            'room.created' => "Room created: {$roomName}",
            'room.updated' => "Room updated: {$roomName}",
            'room.deleted' => "Room deleted: {$roomName}",
        ];

        $description = $descriptions[$action] ?? "Room activity: {$action}";

        return $this->log($action, $description, 'Room', $roomId, $metadata);
    }

    /**
     * Log subject-related activities
     */
    public function logSubjectActivity(string $action, int $subjectId, string $subjectInfo, ?array $metadata = null): ActivityLog
    {
        $descriptions = [
            'subject.created' => "Subject created: {$subjectInfo}",
            'subject.updated' => "Subject updated: {$subjectInfo}",
            'subject.deleted' => "Subject deleted: {$subjectInfo}",
        ];

        $description = $descriptions[$action] ?? "Subject activity: {$action}";

        return $this->log($action, $description, 'Subject', $subjectId, $metadata);
    }

    /**
     * Get recent activities
     */
    public function getRecentActivities(int $limit = 20): array
    {
        return $this->activityLogRepository->findRecentActivities($limit);
    }

    /**
     * Get activities by user
     */
    public function getUserActivities(User $user, int $limit = 50): array
    {
        return $this->activityLogRepository->findByUser($user, $limit);
    }

    /**
     * Get activity statistics
     */
    public function getActivityStats(int $days = 30): array
    {
        return $this->activityLogRepository->getActivityStats($days);
    }
}
