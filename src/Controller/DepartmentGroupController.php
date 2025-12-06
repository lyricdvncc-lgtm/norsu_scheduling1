<?php

namespace App\Controller;

use App\Service\DepartmentGroupService;
use App\Service\ActivityLogService;
use App\Repository\DepartmentGroupRepository;
use App\Repository\DepartmentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/department-groups')]
#[IsGranted('ROLE_ADMIN')]
class DepartmentGroupController extends AbstractController
{
    public function __construct(
        private DepartmentGroupService $groupService,
        private DepartmentGroupRepository $groupRepository,
        private DepartmentRepository $departmentRepository,
        private ActivityLogService $activityLogService
    ) {
    }

    #[Route('', name: 'admin_department_groups', methods: ['GET'])]
    public function index(): Response
    {
        $groups = $this->groupService->getAllGroupsWithDepartments();
        $ungroupedDepartments = $this->groupService->getUngroupedDepartments();

        return $this->render('admin/department_group/index.html.twig', [
            'groups' => $groups,
            'ungroupedDepartments' => $ungroupedDepartments,
            'colorOptions' => $this->groupService->getColorOptions(),
        ]);
    }

    #[Route('/create', name: 'admin_department_group_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            // Validate required fields
            if (empty($data['name'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Group name is required'
                ], 400);
            }

            $group = $this->groupService->createGroup($data);
            
            // Log the activity
            $this->activityLogService->log(
                'department_group.created',
                "Department group created: {$group->getName()}",
                'DepartmentGroup',
                $group->getId(),
                [
                    'name' => $group->getName(),
                    'description' => $group->getDescription(),
                    'color' => $group->getColor()
                ]
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Department group created successfully',
                'group' => [
                    'id' => $group->getId(),
                    'name' => $group->getName(),
                    'description' => $group->getDescription(),
                    'color' => $group->getColor(),
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error creating department group: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/{id}/update', name: 'admin_department_group_update', methods: ['POST'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $group = $this->groupRepository->find($id);
            
            if (!$group) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Group not found'
                ], 404);
            }

            $data = json_decode($request->getContent(), true);

            // Validate required fields
            if (isset($data['name']) && empty($data['name'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Group name cannot be empty'
                ], 400);
            }

            $this->groupService->updateGroup($group, $data);
            
            // Log the activity
            $this->activityLogService->log(
                'department_group.updated',
                "Department group updated: {$group->getName()}",
                'DepartmentGroup',
                $group->getId(),
                ['name' => $group->getName()]
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Department group updated successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error updating department group: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/{id}/delete', name: 'admin_department_group_delete', methods: ['POST'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $group = $this->groupRepository->find($id);
            
            if (!$group) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Group not found'
                ], 404);
            }
            
            $groupName = $group->getName();
            $this->groupService->deleteGroup($group);
            
            // Log the activity
            $this->activityLogService->log(
                'department_group.deleted',
                "Department group deleted: {$groupName}",
                'DepartmentGroup',
                $id
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Department group deleted successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error deleting department group: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/{id}/assign-department', name: 'admin_department_group_assign', methods: ['POST'])]
    public function assignDepartment(int $id, Request $request): JsonResponse
    {
        try {
            $group = $this->groupRepository->find($id);
            
            if (!$group) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Group not found'
                ], 404);
            }

            $data = json_decode($request->getContent(), true);
            $departmentId = $data['department_id'] ?? null;

            if (!$departmentId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Department ID required'
                ], 400);
            }

            $department = $this->departmentRepository->find($departmentId);
            
            if (!$department) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Department not found'
                ], 404);
            }

            // Validate assignment
            $validation = $this->groupService->canAssignDepartmentToGroup($department, $group);
            if (!$validation['valid']) {
                return new JsonResponse([
                    'success' => false,
                    'message' => implode(', ', $validation['errors'])
                ], 400);
            }

            $this->groupService->assignDepartmentToGroup($department, $group);
            
            // Log the activity
            $this->activityLogService->log(
                'department_group.assigned',
                "Department '{$department->getName()}' assigned to group '{$group->getName()}'",
                'DepartmentGroup',
                $group->getId(),
                [
                    'department_id' => $department->getId(),
                    'department_name' => $department->getName(),
                    'group_name' => $group->getName()
                ]
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Department assigned to group successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error assigning department: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/unassign-department', name: 'admin_department_group_unassign', methods: ['POST'])]
    public function unassignDepartment(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $departmentId = $data['department_id'] ?? null;

            if (!$departmentId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Department ID required'
                ], 400);
            }

            $department = $this->departmentRepository->find($departmentId);
            
            if (!$department) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Department not found'
                ], 404);
            }

            $groupName = $department->getDepartmentGroup() ? $department->getDepartmentGroup()->getName() : 'Unknown';
            
            $this->groupService->unassignDepartment($department);
            
            // Log the activity
            $this->activityLogService->log(
                'department_group.unassigned',
                "Department '{$department->getName()}' removed from group '{$groupName}'",
                'DepartmentGroup',
                null,
                [
                    'department_id' => $department->getId(),
                    'department_name' => $department->getName(),
                    'group_name' => $groupName
                ]
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Department removed from group successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error removing department: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/{id}/statistics', name: 'admin_department_group_statistics', methods: ['GET'])]
    public function getStatistics(int $id): JsonResponse
    {
        try {
            $group = $this->groupRepository->find($id);
            
            if (!$group) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Group not found'
                ], 404);
            }

            $statistics = $this->groupService->getGroupStatistics($group);

            return new JsonResponse([
                'success' => true,
                'statistics' => $statistics
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error fetching statistics: ' . $e->getMessage()
            ], 500);
        }
    }
}
