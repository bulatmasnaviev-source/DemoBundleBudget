<?php

/*
 * This file is part of the "DemoBundle" for Kimai.
 * All rights reserved by Kevin Papst (www.kevinpapst.de).
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\DemoBundle\Controller;

use App\Configuration\LocaleService;
use App\Controller\AbstractController;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Utils\PageSetup;
use KimaiPlugin\DemoBundle\Configuration\DemoConfiguration;
use KimaiPlugin\DemoBundle\Form\DemoType;
use KimaiPlugin\DemoBundle\Report\DemoReportForm;
use KimaiPlugin\DemoBundle\Report\DemoReportQuery;
use KimaiPlugin\DemoBundle\Repository\BudgetPlanStorage;
use KimaiPlugin\DemoBundle\Repository\DemoRepository;
use KimaiPlugin\DemoBundle\Repository\ResourcePlanStorage;
use KimaiPlugin\DemoBundle\Repository\TodoListStorage;
use KimaiPlugin\DemoBundle\Repository\WorkingPlanStorage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/demo')]
#[IsGranted('demo')]
final class DemoController extends AbstractController
{
    private const ALLOWED_PLANNING_ROLES = ['ROLE_TEAMLEAD', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(private DemoRepository $repository, private DemoConfiguration $configuration, private EntityManagerInterface $entityManager, private BudgetPlanStorage $budgetPlanStorage, private ResourcePlanStorage $resourcePlanStorage, private WorkingPlanStorage $workingPlanStorage, private TodoListStorage $todoListStorage)
    {
    }

    #[Route(path: '', name: 'demo', methods: ['GET', 'POST'])]
    public function index(LocaleService $localeService): Response
    {
        $this->denyPlanningAccessUnlessGranted();

        // some demo data, which can be viewed in the "test locale" box
        $begin = 240 * 3600 + rand(1, 3 * 3600);
        $end = $begin + (rand(10 * 3600, 15 * 3600));
        $timesheet = new Timesheet();
        $timesheet->setBegin(new \DateTime('-' . $begin . 'seconds'));
        $timesheet->setEnd(new \DateTime('-' . $end . 'seconds'));
        $timesheet->setDuration($end - $begin);
        $timesheet->setHourlyRate(48.25);
        $timesheet->setRate(1241.25);

        $entity = $this->repository->getDemoEntity();

        $entity->increaseCounter();
        $this->repository->saveDemoEntity($entity);

        $form = $this->createForm(DemoType::class, $entity, [
            'action' => $this->generateUrl('demo'),
        ]);

        $page = new PageSetup('Demo');
        $page->setActionName('demo');
        $page->setActionPayload(['counter' => $entity->getCounter()]);

        $projects = array_values(array_filter(
            $this->entityManager->getRepository(Project::class)->findAll(),
            fn (Project $project): bool => $this->isProjectVisible($project)
        ));
        usort($projects, static fn (Project $a, Project $b) => strcasecmp($a->getName(), $b->getName()));

        $projectData = [];
        foreach ($projects as $project) {
            $start = method_exists($project, 'getStart') ? $project->getStart() : null;
            $end = method_exists($project, 'getEnd') ? $project->getEnd() : null;
            $budget = method_exists($project, 'getBudget') ? $project->getBudget() : null;

            $projectData[] = [
                'id' => $project->getId(),
                'name' => $project->getName(),
                'start' => $start instanceof \DateTimeInterface ? $start->format('Y-m-d') : null,
                'end' => $end instanceof \DateTimeInterface ? $end->format('Y-m-d') : null,
                'budget' => is_numeric($budget) ? (float) $budget : 0.0,
                'assignedEmployeeIds' => $this->buildAssignedEmployeeIds($project),
            ];
        }

        $activeProjectStatuses = [];
        foreach ($projects as $project) {
            $statusData = $this->budgetPlanStorage->loadByProjectId((int) $project->getId());
            $activeProjectStatuses[] = [
                'id' => $project->getId(),
                'name' => $project->getName(),
                'status' => $this->normalizePlanStatus(\is_array($statusData) ? (string) ($statusData['status'] ?? 'NEW') : 'NEW'),
            ];
        }

        $employees = $this->buildEmployeeData();

        return $this->render('@Demo/index.html.twig', [
            'page_setup' => $page,
            'entity' => $entity,
            'configuration' => $this->configuration,
            'projects' => $projects,
            'project_data' => $projectData,
            'employees' => $employees,
            'active_project_statuses' => $activeProjectStatuses,
            'is_admin' => $this->isGranted('ROLE_ADMIN'),
            // for locale testing
            'now' => new \DateTime(),
            'timesheet' => $timesheet,
            'locales' => $localeService->getAllLocales(),
            // TODO - unused
            'form' => $form->createView(),
        ]);
    }


    #[Route(path: '/resource-plan', name: 'demo_resource_plan', methods: ['GET'])]
    public function resourcePlan(): Response
    {
        $this->denyPlanningAccessUnlessGranted();

        $page = new PageSetup('Ресурсный план');
        $page->setActionName('demo_resource_plan');

        return $this->render('@Demo/resource_plan.html.twig', [
            'page_setup' => $page,
            'employees' => $this->buildEmployeeData(),
            'active_projects' => $this->buildApprovedBudgetProjects(),
            'all_active_projects' => $this->buildActiveProjects(),
        ]);
    }

    #[Route(path: '/todo-list', name: 'demo_todo_list', methods: ['GET'])]
    public function todoList(): Response
    {
        $this->denyPlanningAccessUnlessGranted();

        $page = new PageSetup('To-Do list');
        $page->setActionName('demo_todo_list');

        return $this->render('@Demo/todo_list.html.twig', [
            'page_setup' => $page,
        ]);
    }

    #[Route(path: '/todo-list/data', name: 'demo_todo_list_data', methods: ['GET'])]
    public function getTodoListData(): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        return new JsonResponse($this->todoListStorage->loadBuckets());
    }
    #[Route(path: '/todo-list/entries', name: 'demo_todo_list_create', methods: ['POST'])]
    public function createTodoListEntry(Request $request): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $payload = json_decode($request->getContent(), true);
        $block = $this->normalizeTodoListBlock((string) ($payload['block'] ?? ''));
        $stage = 'Инициация';
        $substage = $this->normalizeTodoListSubstage((string) ($payload['substage'] ?? ''));
        $project = trim((string) ($payload['project'] ?? ''));
        $task = trim((string) ($payload['task'] ?? ''));
        $taskStatus = trim((string) ($payload['taskStatus'] ?? ''));
        $nextStep = trim((string) ($payload['nextStep'] ?? ''));
        $responsible = trim((string) ($payload['responsible'] ?? ''));
        $date = trim((string) ($payload['date'] ?? ''));

        foreach ([$block, $stage, $substage, $project, $task, $taskStatus, $nextStep, $responsible, $date] as $value) {
            if ($value === '') {
                return new JsonResponse(['message' => 'Все поля должны быть заполнены'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $id = $this->todoListStorage->create([
            'stage' => $stage,
            'substage' => $substage,
            'block' => $block,
            'project_name' => $project,
            'task_name' => $task,
            'task_status' => $taskStatus,
            'next_step' => $nextStep,
            'responsible' => $responsible,
            'task_date' => $date,
            'cancel_reason' => null,
            'created_at' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'implementation_started_at' => null,
            'completion_started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
        ]);

        return new JsonResponse([
            'id' => $id,
            'message' => 'Created',
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/todo-list/entries/{id}', name: 'demo_todo_list_update', methods: ['POST'])]
    public function updateTodoListEntry(int $id, Request $request): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $payload = json_decode($request->getContent(), true);
        $block = $this->normalizeTodoListBlock((string) ($payload['block'] ?? ''));
        $substage = $this->normalizeTodoListSubstage((string) ($payload['substage'] ?? ''));
        $project = trim((string) ($payload['project'] ?? ''));
        $task = trim((string) ($payload['task'] ?? ''));
        $taskStatus = trim((string) ($payload['taskStatus'] ?? ''));
        $nextStep = trim((string) ($payload['nextStep'] ?? ''));
        $responsible = trim((string) ($payload['responsible'] ?? ''));
        $date = trim((string) ($payload['date'] ?? ''));

        foreach ([$block, $substage, $project, $task, $taskStatus, $nextStep, $responsible, $date] as $value) {
            if ($value === '') {
                return new JsonResponse(['message' => 'Все поля должны быть заполнены'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $this->todoListStorage->updateById($id, [
            'block' => $block,
            'substage' => $substage,
            'project_name' => $project,
            'task_name' => $task,
            'task_status' => $taskStatus,
            'next_step' => $nextStep,
            'responsible' => $responsible,
            'task_date' => $date,
        ]);

        return new JsonResponse(['message' => 'Updated']);
    }

    #[Route(path: '/todo-list/entries/{id}/stage', name: 'demo_todo_list_update_stage', methods: ['POST'])]
    public function updateTodoListEntryStage(int $id, Request $request): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $payload = json_decode($request->getContent(), true);
        $stage = $this->normalizeTodoListStage((string) ($payload['stage'] ?? ''));
        if ($stage === '') {
            return new JsonResponse(['message' => 'Invalid stage'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->todoListStorage->updateStageById($id, $stage);

        return new JsonResponse(['message' => 'Stage updated']);
    }

    #[Route(path: '/todo-list/entries/{id}/cancel', name: 'demo_todo_list_cancel', methods: ['POST'])]
    public function cancelTodoListEntry(int $id, Request $request): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $payload = json_decode($request->getContent(), true);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            return new JsonResponse(['message' => 'Не указана причина отмены'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->todoListStorage->cancelById($id, $reason);

        return new JsonResponse(['message' => 'Entry cancelled']);
    }

    private function buildEmployeeData(): array
    {
        $users = $this->entityManager->getRepository(User::class)->findBy([], ['alias' => 'ASC']);
        $employees = [];
        foreach ($users as $user) {
            $alias = method_exists($user, 'getAlias') ? (string) $user->getAlias() : '';
            if ($alias === '' && method_exists($user, 'getDisplayName')) {
                $alias = (string) $user->getDisplayName();
            }
            if ($alias === '' && method_exists($user, 'getUsername')) {
                $alias = (string) $user->getUsername();
            }

            $hourlyRate = 0.0;
            if (method_exists($user, 'getPreferenceValue')) {
                $value = $user->getPreferenceValue('hourly_rate', 0);
                $hourlyRate = is_numeric($value) ? (float) $value : 0.0;
            } elseif (method_exists($user, 'getHourlyRate')) {
                $value = $user->getHourlyRate();
                $hourlyRate = is_numeric($value) ? (float) $value : 0.0;
            }

            $employees[] = [
                'id' => $user->getId(),
                'name' => $alias !== '' ? $alias : 'User #' . $user->getId(),
                'hourlyRate' => $hourlyRate,
            ];
        }

        return $employees;
    }

    private function buildAssignedEmployeeIds(Project $project): array
    {
        if (!method_exists($project, 'getTeams')) {
            return [];
        }

        $employeeIds = [];
        foreach ($project->getTeams() as $team) {
            if (!\is_object($team)) {
                continue;
            }

            $members = [];
            if (method_exists($team, 'getUsers')) {
                $members = $team->getUsers();
            } elseif (method_exists($team, 'getMembers')) {
                $members = $team->getMembers();
            }

            foreach ($members as $member) {
                if (!\is_object($member) || !method_exists($member, 'getId')) {
                    continue;
                }

                $employeeIds[(string) $member->getId()] = (int) $member->getId();
            }
        }

        return array_values($employeeIds);
    }

    private function isProjectVisible(Project $project): bool
    {
        if (method_exists($project, 'isVisible')) {
            return (bool) $project->isVisible();
        }

        if (method_exists($project, 'getVisible')) {
            return (bool) $project->getVisible();
        }

        return true;
    }

    private function buildActiveProjects(): array
    {
        $projects = $this->entityManager->getRepository(Project::class)->findAll();
        usort($projects, static fn (Project $a, Project $b) => strcasecmp($a->getName(), $b->getName()));

        $activeProjects = [];
        foreach ($projects as $project) {
            $isVisible = true;
            if (method_exists($project, 'isVisible')) {
                $isVisible = (bool) $project->isVisible();
            } elseif (method_exists($project, 'getVisible')) {
                $isVisible = (bool) $project->getVisible();
            }

            if (!$isVisible) {
                continue;
            }

            $activeProjects[] = [
                'id' => $project->getId(),
                'name' => $project->getName(),
            ];
        }

        return $activeProjects;
    }

    private function buildApprovedBudgetProjects(): array
    {
        return array_values(array_filter(
            $this->buildActiveProjects(),
            function (array $project): bool {
                $statusData = $this->budgetPlanStorage->loadByProjectId((int) ($project['id'] ?? 0));

                return \is_array($statusData) && $this->normalizePlanStatus((string) ($statusData['status'] ?? 'NEW')) === 'APPROVED';
            }
        ));
    }



    #[Route(path: '/resource-plan/{intervalId}', name: 'demo_resource_plan_get', methods: ['GET'])]
    public function getResourcePlan(string $intervalId): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $data = $this->resourcePlanStorage->loadByIntervalId($intervalId);
        $status = $this->normalizeResourcePlanStatus($data === null ? 'NEW' : (string) ($data['status'] ?? 'NEW'));
        $cells = $data === null || !\is_array($data['cells'] ?? null) ? [] : $data['cells'];
        $actualCells = $this->buildResourcePlanActualCells($intervalId);

        if ($data === null) {
            return new JsonResponse(['status' => 'NEW', 'cells' => $cells, 'actualCells' => $actualCells]);
        }

        return new JsonResponse([
            'status' => $status,
            'cells' => $cells,
            'actualCells' => $actualCells,
        ]);
    }

    #[Route(path: '/resource-plans', name: 'demo_resource_plan_bulk', methods: ['GET'])]
    public function getResourcePlans(Request $request): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $intervalIds = $request->query->all('intervals');
        if (!\is_array($intervalIds)) {
            $intervalIds = [];
        }

        $plans = [];
        foreach ($intervalIds as $intervalId) {
            if (!\is_string($intervalId) || $intervalId === '') {
                continue;
            }

            $data = $this->resourcePlanStorage->loadByIntervalId($intervalId);
            $plans[$intervalId] = [
                'intervalId' => $intervalId,
                'status' => $this->normalizeResourcePlanStatus($data === null ? 'NEW' : (string) ($data['status'] ?? 'NEW')),
                'cells' => $data === null || !\is_array($data['cells'] ?? null) ? [] : $data['cells'],
            ];
        }

        return new JsonResponse([
            'plans' => $plans,
        ]);
    }

    #[Route(path: '/resource-plan/{intervalId}/status', name: 'demo_resource_plan_status', methods: ['POST'])]
    public function setResourcePlanStatus(Request $request, string $intervalId): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $payload = json_decode($request->getContent(), true);
        $status = $this->normalizeResourcePlanStatus($payload['status'] ?? 'NEW');
        $cells = \is_array($payload['cells'] ?? null) ? $payload['cells'] : [];

        $this->resourcePlanStorage->saveByIntervalId($intervalId, $status, $cells);
        if ($status === 'APPROVED') {
            $this->syncWorkingPlansFromApprovedResourcePlan($intervalId, $cells);
        }

        return new JsonResponse(['status' => $status, 'cells' => $cells]);
    }

    #[Route(path: '/working-plan/{project}', name: 'demo_working_plan_get', methods: ['GET'])]
    public function getWorkingPlan(Project $project): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $saved = $this->workingPlanStorage->loadByProjectId((int) $project->getId());
        $savedRows = \is_array($saved['rows'] ?? null) ? $saved['rows'] : null;

        return new JsonResponse([
            'rows' => $savedRows ?? $this->buildDefaultWorkingPlanRows($project),
            'savedRows' => $savedRows,
            'hasSaved' => $savedRows !== null,
        ]);
    }

    #[Route(path: '/working-plan/{project}', name: 'demo_working_plan_save', methods: ['POST'])]
    public function saveWorkingPlan(Request $request, Project $project): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $payload = json_decode($request->getContent(), true);
        $rows = \is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        $this->workingPlanStorage->saveByProjectId((int) $project->getId(), $rows);

        return new JsonResponse([
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/budget-plan/{project}', name: 'demo_budget_plan_get', methods: ['GET'])]
    public function getBudgetPlan(Project $project): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $data = $this->budgetPlanStorage->loadByProjectId((int) $project->getId());

        if ($data === null) {
            return new JsonResponse(['status' => 'NEW', 'rows' => []]);
        }

        return new JsonResponse([
            'status' => $this->normalizePlanStatus($data['status'] ?? 'NEW'),
            'rows' => \is_array($data['rows'] ?? null) ? $data['rows'] : [],
        ]);
    }

    #[Route(path: '/budget-plan/{project}/status', name: 'demo_budget_plan_status', methods: ['POST'])]
    public function setBudgetPlanStatus(Request $request, Project $project): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        $payload = json_decode($request->getContent(), true);
        $status = $this->normalizePlanStatus($payload['status'] ?? 'NEW');
        $rows = \is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        if (\in_array($status, ['APPROVED', 'REJECTED'], true) && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Only admins can approve or reject the plan.');
        }

        if ($status !== 'NEW') {
            $this->budgetPlanStorage->saveByProjectId((int) $project->getId(), $status, $rows);
        }

        return new JsonResponse(['status' => $status, 'rows' => $rows]);
    }

    private function normalizePlanStatus(string $status): string
    {
        return match ($status) {
            'SENT', 'APPROVED', 'REJECTED' => $status,
            default => 'NEW',
        };
    }

    private function normalizeResourcePlanStatus(string $status): string
    {
        return match ($status) {
            'DISCUSSION', 'APPROVED', 'CORRECTING' => $status,
            default => 'NEW',
        };
    }
    private function normalizeTodoListBlock(string $block): string
    {
        return match (trim($block)) {
            'Проект', 'Проекты' => 'Проекты',
            'Исследование', 'Исследования' => 'Исследования',
            'PR' => 'PR',
            'G&A' => 'G&A',
            default => trim($block),
        };
    }

    private function normalizeTodoListStage(string $stage): string
    {
        return match (trim($stage)) {
            'Инициация', 'Реализация', 'Завершение', 'Завершено', 'Отменено' => trim($stage),
            default => '',
        };
    }

    private function normalizeTodoListSubstage(string $substage): string
    {
        return match (trim($substage)) {
            'Инициация темы', 'Разработка ТЗ', 'Подготовка закупки', 'Формирование КП' => trim($substage),
            default => '',
        };
    }

    private function denyPlanningAccessUnlessGranted(): void
    {
        foreach (self::ALLOWED_PLANNING_ROLES as $role) {
            if ($this->isGranted($role)) {
                return;
            }
        }

        throw $this->createAccessDeniedException('Access allowed only for Teamlead, Admin or Super Admin users.');
    }


    #[Route(path: '/budget-plan/{project}/actual-costs', name: 'demo_budget_plan_actual_costs', methods: ['GET'])]
    public function getActualCosts(Project $project): JsonResponse
    {
        $this->denyPlanningAccessUnlessGranted();

        return new JsonResponse($this->buildActualCostData($project));
    }

    private function buildActualCostData(Project $project): array
    {
        $start = method_exists($project, 'getStart') ? $project->getStart() : null;
        $end = method_exists($project, 'getEnd') ? $project->getEnd() : null;

        if (!$start instanceof \DateTimeInterface || !$end instanceof \DateTimeInterface || $start > $end) {
            return ['weeks' => [], 'matrix' => []];
        }

        $periodStart = (new \DateTimeImmutable($start->format('Y-m-d')))->modify('monday this week')->modify('-1 week')->setTime(0, 0, 0);
        $periodEnd = (new \DateTimeImmutable($end->format('Y-m-d')))->modify('sunday this week')->modify('+2 week')->setTime(23, 59, 59);

        $weeks = [];
        for ($cursor = $periodStart; $cursor <= $periodEnd; $cursor = $cursor->modify('+1 week')) {
            $weeks[] = $cursor;
        }

        $indexByWeekStart = [];
        foreach ($weeks as $idx => $weekStart) {
            $indexByWeekStart[$weekStart->format('Y-m-d')] = $idx;
        }

        $matrix = [];
        $approvedWeekMap = $this->getApprovedWeekMap($periodStart, $periodEnd);
        $timesheets = $this->entityManager->getRepository(Timesheet::class)->createQueryBuilder('t')
            ->andWhere('t.project = :project')
            ->andWhere('t.begin >= :begin')
            ->andWhere('t.begin <= :end')
            ->setParameter('project', $project)
            ->setParameter('begin', $periodStart)
            ->setParameter('end', $periodEnd)
            ->getQuery()
            ->getResult();

        foreach ($timesheets as $timesheet) {
            if (!$timesheet instanceof Timesheet || $timesheet->getBegin() === null || $timesheet->getUser() === null) {
                continue;
            }

            $weekStart = (new \DateTimeImmutable($timesheet->getBegin()->format('Y-m-d')))->modify('monday this week')->format('Y-m-d');
            if (!isset($indexByWeekStart[$weekStart])) {
                continue;
            }

            $userId = (string) $timesheet->getUser()->getId();
            if (\is_array($approvedWeekMap) && !isset($approvedWeekMap[$userId . '|' . $weekStart])) {
                continue;
            }

            $weekIndex = $indexByWeekStart[$weekStart];
            $matrix[$userId][$weekIndex] = ($matrix[$userId][$weekIndex] ?? 0.0) + (max(0, (int) $timesheet->getDuration()) / 3600);
        }

        return [
            'weeks' => array_map(static function (\DateTimeImmutable $weekStart): array {
                return [
                    'start' => $weekStart->format('Y-m-d'),
                    'label' => 'W' . $weekStart->format('W') . '-' . $weekStart->format('y'),
                ];
            }, $weeks),
            'matrix' => $matrix,
        ];
    }

    private function buildDefaultWorkingPlanRows(Project $project): array
    {
        $intervals = $this->buildAlignedBiweeklyIntervals($project);
        $currentBiweeklyIndex = $this->findCurrentBiweeklyIndex($intervals);
        $basePlanData = $this->budgetPlanStorage->loadByProjectId((int) $project->getId());
        $baseRows = \is_array($basePlanData['rows'] ?? null) ? $basePlanData['rows'] : [];
        $actualCostData = $this->buildActualCostData($project);
        $actualWeeks = \is_array($actualCostData['weeks'] ?? null) ? $actualCostData['weeks'] : [];
        $actualMatrix = \is_array($actualCostData['matrix'] ?? null) ? $actualCostData['matrix'] : [];
        $currentIntervalId = ($currentBiweeklyIndex >= 0 && $currentBiweeklyIndex < \count($intervals)) ? (string) ($intervals[$currentBiweeklyIndex]['resourcePlanId'] ?? '') : '';
        $currentResourcePlan = $currentIntervalId !== '' ? $this->resourcePlanStorage->loadByIntervalId($currentIntervalId) : null;
        $isCurrentResourcePlanApproved = \is_array($currentResourcePlan) && $this->normalizeResourcePlanStatus((string) ($currentResourcePlan['status'] ?? 'NEW')) === 'APPROVED';
        $currentResourceCells = \is_array($currentResourcePlan['cells'] ?? null) ? $currentResourcePlan['cells'] : [];
        $submittedEmployeeIds = $this->buildSubmittedEmployeeIds($project);

        $baseRowsByEmployee = [];
        $employeeIds = [];
        $lockedEmployeeIds = [];
        foreach ($baseRows as $row) {
            if (!\is_array($row) || !isset($row['employeeId'])) {
                continue;
            }

            $employeeId = (string) $row['employeeId'];
            $employeeIds[$employeeId] = true;
            $lockedEmployeeIds[$employeeId] = true;
            $baseRowsByEmployee[$employeeId] = \is_array($row['hours'] ?? null) ? $row['hours'] : [];
        }

        foreach ($submittedEmployeeIds as $employeeId) {
            $employeeIds[(string) $employeeId] = true;
            $lockedEmployeeIds[(string) $employeeId] = true;
        }

        $employeeOrder = array_map(static fn (array $employee): string => (string) $employee['id'], $this->buildEmployeeData());
        $employeeIdList = array_keys($employeeIds);
        usort($employeeIdList, static function (string $a, string $b) use ($employeeOrder): int {
            $rankA = array_search($a, $employeeOrder, true);
            $rankB = array_search($b, $employeeOrder, true);

            return ($rankA === false ? PHP_INT_MAX : $rankA) <=> ($rankB === false ? PHP_INT_MAX : $rankB) ?: strcmp($a, $b);
        });

        $actualWeekIndexByStart = [];
        foreach ($actualWeeks as $index => $week) {
            if (\is_array($week) && isset($week['start'])) {
                $actualWeekIndexByStart[(string) $week['start']] = $index;
            }
        }

        $rows = [];
        foreach ($employeeIdList as $employeeId) {
            $hours = [];
            foreach ($intervals as $intervalIndex => $_interval) {
                $hours[] = (string) $this->resolveWorkingPlanHoursForInterval(
                    $project,
                    $employeeId,
                    $intervalIndex,
                    $intervals,
                    $currentBiweeklyIndex,
                    $isCurrentResourcePlanApproved,
                    $baseRowsByEmployee,
                    $actualWeekIndexByStart,
                    $actualMatrix,
                    $currentResourceCells
                );
            }

            $rows[] = [
                'employeeId' => $employeeId,
                'hours' => $hours,
                'locked' => isset($lockedEmployeeIds[$employeeId]),
            ];
        }

        return $rows;
    }

    private function buildSubmittedEmployeeIds(Project $project): array
    {
        $start = method_exists($project, 'getStart') ? $project->getStart() : null;
        $end = method_exists($project, 'getEnd') ? $project->getEnd() : null;

        if (!$start instanceof \DateTimeInterface || !$end instanceof \DateTimeInterface || $start > $end) {
            return [];
        }

        $periodStart = (new \DateTimeImmutable($start->format('Y-m-d')))->modify('monday this week')->modify('-1 week')->setTime(0, 0, 0);
        $periodEnd = (new \DateTimeImmutable($end->format('Y-m-d')))->modify('sunday this week')->modify('+2 week')->setTime(23, 59, 59);
        $employeeIds = [];

        $timesheets = $this->entityManager->getRepository(Timesheet::class)->createQueryBuilder('t')
            ->andWhere('t.project = :project')
            ->andWhere('t.begin >= :begin')
            ->andWhere('t.begin <= :end')
            ->setParameter('project', $project)
            ->setParameter('begin', $periodStart)
            ->setParameter('end', $periodEnd)
            ->getQuery()
            ->getResult();

        foreach ($timesheets as $timesheet) {
            if (!$timesheet instanceof Timesheet || $timesheet->getUser() === null) {
                continue;
            }

            $employeeIds[(string) $timesheet->getUser()->getId()] = (string) $timesheet->getUser()->getId();
        }

        return array_values($employeeIds);
    }

    private function resolveWorkingPlanHoursForInterval(Project $project, string $employeeId, int $intervalIndex, array $intervals, int $currentBiweeklyIndex, bool $isCurrentResourcePlanApproved, array $baseRowsByEmployee, array $actualWeekIndexByStart, array $actualMatrix, array $currentResourceCells): float
    {
        if ($currentBiweeklyIndex >= 0 && $intervalIndex < $currentBiweeklyIndex) {
            $intervalStart = \DateTimeImmutable::createFromFormat('Y-m-d', (string) ($intervals[$intervalIndex]['start'] ?? ''));
            if (!$intervalStart instanceof \DateTimeImmutable) {
                return 0.0;
            }

            $firstWeekStart = $intervalStart->modify('monday this week')->format('Y-m-d');
            $secondWeekStart = $intervalStart->modify('monday this week')->modify('+7 days')->format('Y-m-d');

            return round(
                (float) ($actualMatrix[$employeeId][$actualWeekIndexByStart[$firstWeekStart] ?? -1] ?? 0)
                + (float) ($actualMatrix[$employeeId][$actualWeekIndexByStart[$secondWeekStart] ?? -1] ?? 0),
                1
            );
        }

        if ($currentBiweeklyIndex >= 0 && $intervalIndex === $currentBiweeklyIndex && $isCurrentResourcePlanApproved) {
            $intervalId = (string) ($intervals[$intervalIndex]['resourcePlanId'] ?? '');

            return round((float) ($currentResourceCells[$intervalId . '::' . $project->getId() . '::' . $employeeId] ?? 0), 1);
        }

        return round((float) ($baseRowsByEmployee[$employeeId][$intervalIndex] ?? 0), 1);
    }

    private function buildAlignedBiweeklyIntervals(Project $project): array
    {
        $start = method_exists($project, 'getStart') ? $project->getStart() : null;
        $end = method_exists($project, 'getEnd') ? $project->getEnd() : null;

        if (!$start instanceof \DateTimeInterface || !$end instanceof \DateTimeInterface || $start > $end) {
            return [];
        }

        $rangeStart = (new \DateTimeImmutable($start->format('Y-m-d')))->modify('monday this week')->modify('-14 days')->setTime(0, 0, 0);
        $rangeEnd = (new \DateTimeImmutable($end->format('Y-m-d')))->modify('sunday this week')->modify('+14 days')->setTime(23, 59, 59);
        $cursor = (new \DateTimeImmutable($start->format('Y-01-01')))->modify('monday this week')->setTime(0, 0, 0);
        $intervals = [];

        while ($cursor <= $rangeEnd) {
            $intervalStart = $cursor;
            $intervalEnd = $cursor->modify('+13 days')->setTime(23, 59, 59);

            if ($intervalEnd >= $rangeStart) {
                $intervals[] = [
                    'start' => $intervalStart->format('Y-m-d'),
                    'resourcePlanId' => $intervalStart->format('Y-m-d'),
                ];
            }

            $cursor = $cursor->modify('+14 days');
        }

        return $intervals;
    }

    private function findCurrentBiweeklyIndex(array $intervals): int
    {
        if ($intervals === []) {
            return -1;
        }

        $now = new \DateTimeImmutable('today 12:00:00');
        foreach ($intervals as $index => $interval) {
            $start = \DateTimeImmutable::createFromFormat('Y-m-d', (string) ($interval['start'] ?? ''));
            if (!$start instanceof \DateTimeImmutable) {
                continue;
            }

            $start = $start->setTime(0, 0, 0);
            $end = $start->modify('+13 days')->setTime(23, 59, 59);
            if ($now >= $start && $now <= $end) {
                return $index;
            }
        }

        $firstStart = \DateTimeImmutable::createFromFormat('Y-m-d', (string) ($intervals[0]['start'] ?? ''));
        if ($firstStart instanceof \DateTimeImmutable && $now < $firstStart->setTime(0, 0, 0)) {
            return 0;
        }

        return \count($intervals);
    }

    private function syncWorkingPlansFromApprovedResourcePlan(string $intervalId, array $cells): void
    {
        $projectEmployeeHours = [];
        $prefix = $intervalId . '::';

        foreach ($cells as $cellKey => $value) {
            if (!\is_string($cellKey) || !str_starts_with($cellKey, $prefix)) {
                continue;
            }

            $parts = explode('::', $cellKey);
            if (\count($parts) !== 3) {
                continue;
            }

            [, $projectId, $employeeId] = $parts;
            if ($projectId === '__base__' || $projectId === '__projects__' || $projectId === '__project_snapshots__') {
                continue;
            }

            $projectEmployeeHours[(string) $projectId][(string) $employeeId] = (string) $value;
        }

        if ($projectEmployeeHours === []) {
            return;
        }

        $projectRepository = $this->entityManager->getRepository(Project::class);
        foreach ($projectEmployeeHours as $projectId => $employeeHours) {
            $project = $projectRepository->find((int) $projectId);
            if (!$project instanceof Project) {
                continue;
            }

            $intervals = $this->buildAlignedBiweeklyIntervals($project);
            $intervalIndex = null;
            foreach ($intervals as $index => $interval) {
                if ((string) ($interval['resourcePlanId'] ?? '') === $intervalId) {
                    $intervalIndex = $index;
                    break;
                }
            }

            if ($intervalIndex === null) {
                continue;
            }

            $saved = $this->workingPlanStorage->loadByProjectId((int) $project->getId());
            $rows = \is_array($saved['rows'] ?? null) ? $saved['rows'] : $this->buildDefaultWorkingPlanRows($project);
            $rowsByEmployee = [];

            foreach ($rows as $row) {
                if (!\is_array($row) || !isset($row['employeeId'])) {
                    continue;
                }

                $employeeId = (string) $row['employeeId'];
                $hours = \is_array($row['hours'] ?? null) ? array_values($row['hours']) : [];
                if (!isset($hours[$intervalIndex])) {
                    $hours = array_pad($hours, \count($intervals), '0');
                }
                $hours[$intervalIndex] = '0';
                $rowsByEmployee[$employeeId] = [
                    'employeeId' => $employeeId,
                    'hours' => $hours,
                ];
            }

            foreach ($employeeHours as $employeeId => $hours) {
                if (!isset($rowsByEmployee[$employeeId])) {
                    $rowsByEmployee[$employeeId] = [
                        'employeeId' => $employeeId,
                        'hours' => array_fill(0, \count($intervals), '0'),
                    ];
                }

                $rowsByEmployee[$employeeId]['hours'][$intervalIndex] = (string) $hours;
            }

            $orderedEmployeeIds = array_map(static fn (array $employee): string => (string) $employee['id'], $this->buildEmployeeData());
            $normalizedRows = array_values($rowsByEmployee);
            usort($normalizedRows, static function (array $left, array $right) use ($orderedEmployeeIds): int {
                $leftId = (string) ($left['employeeId'] ?? '');
                $rightId = (string) ($right['employeeId'] ?? '');
                $leftRank = array_search($leftId, $orderedEmployeeIds, true);
                $rightRank = array_search($rightId, $orderedEmployeeIds, true);

                return ($leftRank === false ? PHP_INT_MAX : $leftRank) <=> ($rightRank === false ? PHP_INT_MAX : $rightRank) ?: strcmp($leftId, $rightId);
            });

            $this->workingPlanStorage->saveByProjectId((int) $project->getId(), $normalizedRows);
        }
    }


    /**
     * Returns a map keyed by "userId|YYYY-mm-dd(Monday)" for approved weeks.
     * Returns null if ApprovalBundle tables are unavailable to keep integration soft.
     */
    private function getApprovedWeekMap(\DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): ?array
    {
        try {
            $connection = $this->entityManager->getConnection();
            $schemaManager = $connection->createSchemaManager();
            $tables = array_map('strtolower', $schemaManager->listTableNames());

            if (!\in_array('kimai2_ext_approval', $tables, true)
                || !\in_array('kimai2_ext_approval_history', $tables, true)
                || !\in_array('kimai2_ext_approval_status', $tables, true)) {
                return null;
            }

            $rows = $connection->fetchAllAssociative(
                'SELECT a.user_id, a.start_date, a.end_date
                 FROM kimai2_ext_approval a
                 INNER JOIN (
                    SELECT h.approval_id, h.status_id
                    FROM kimai2_ext_approval_history h
                    INNER JOIN (
                        SELECT approval_id, MAX(date) AS max_date
                        FROM kimai2_ext_approval_history
                        GROUP BY approval_id
                    ) latest ON latest.approval_id = h.approval_id AND latest.max_date = h.date
                 ) current_status ON current_status.approval_id = a.id
                 INNER JOIN kimai2_ext_approval_status s ON s.id = current_status.status_id
                 WHERE s.name = :approved
                   AND a.end_date >= :periodStart
                   AND a.start_date <= :periodEnd',
                [
                    'approved' => 'approved',
                    'periodStart' => $periodStart->format('Y-m-d'),
                    'periodEnd' => $periodEnd->format('Y-m-d'),
                ]
            );

            $map = [];
            foreach ($rows as $row) {
                if (!isset($row['user_id'], $row['start_date'], $row['end_date'])) {
                    continue;
                }

                $userId = (string) $row['user_id'];
                $start = (new \DateTimeImmutable((string) $row['start_date']))->modify('monday this week');
                $end = (new \DateTimeImmutable((string) $row['end_date']))->modify('monday this week');

                for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 week')) {
                    $map[$userId . '|' . $cursor->format('Y-m-d')] = true;
                }
            }

            return $map;
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildResourcePlanActualCells(string $intervalId): array
    {
        $intervalStart = \DateTimeImmutable::createFromFormat('Y-m-d', $intervalId);
        if (!$intervalStart instanceof \DateTimeImmutable) {
            return [];
        }

        $periodStart = $intervalStart->setTime(0, 0, 0);
        $periodEnd = $intervalStart->modify('+13 days')->setTime(23, 59, 59);
        $approvedWeekMap = $this->getApprovedWeekMap($periodStart, $periodEnd);
        $actualCells = [];

        $timesheets = $this->entityManager->getRepository(Timesheet::class)->createQueryBuilder('t')
            ->andWhere('t.begin >= :begin')
            ->andWhere('t.begin <= :end')
            ->setParameter('begin', $periodStart)
            ->setParameter('end', $periodEnd)
            ->getQuery()
            ->getResult();

        foreach ($timesheets as $timesheet) {
            if (!$timesheet instanceof Timesheet || $timesheet->getBegin() === null || $timesheet->getUser() === null || $timesheet->getProject() === null) {
                continue;
            }

            $weekStart = (new \DateTimeImmutable($timesheet->getBegin()->format('Y-m-d')))->modify('monday this week')->format('Y-m-d');
            $userId = (string) $timesheet->getUser()->getId();
            if (\is_array($approvedWeekMap) && !isset($approvedWeekMap[$userId . '|' . $weekStart])) {
                continue;
            }

            $cellKey = $intervalId . '::' . $timesheet->getProject()->getId() . '::' . $userId;
            if (!isset($actualCells[$cellKey])) {
                $actualCells[$cellKey] = 0.0;
            }

            $actualCells[$cellKey] += max(0, (int) $timesheet->getDuration()) / 3600;
        }

        return array_map(static fn (float $hours): string => (string) round($hours, 1), $actualCells);
    }

    private function buildResourcePlanSourceCells(string $intervalId): array
    {
        $intervalStart = \DateTimeImmutable::createFromFormat('Y-m-d', $intervalId);
        if (!$intervalStart instanceof \DateTimeImmutable) {
            return [];
        }

        $cells = [];
        foreach ($this->entityManager->getRepository(Project::class)->findAll() as $project) {
            if (!$project instanceof Project) {
                continue;
            }

            $projectId = (int) $project->getId();
            $planData = $this->budgetPlanStorage->loadByProjectId($projectId);
            $rows = \is_array($planData['rows'] ?? null) ? $planData['rows'] : [];
            $intervalIndex = $this->findBudgetPlanIntervalIndex($project, $intervalStart);
            if ($intervalIndex === null) {
                continue;
            }

            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $employeeId = $row['employeeId'] ?? null;
                $hours = \is_array($row['hours'] ?? null) ? $row['hours'] : [];
                if ($employeeId === null) {
                    continue;
                }

                $value = $hours[$intervalIndex] ?? '';
                if ($value === '' || $value === null) {
                    continue;
                }

                $cells[$intervalId . '::' . $projectId . '::' . $employeeId] = (string) $value;
            }
        }

        return $cells;
    }

    private function findBudgetPlanIntervalIndex(Project $project, \DateTimeImmutable $intervalStart): ?int
    {
        $projectStart = method_exists($project, 'getStart') ? $project->getStart() : null;
        $projectEnd = method_exists($project, 'getEnd') ? $project->getEnd() : null;

        if (!$projectStart instanceof \DateTimeInterface || !$projectEnd instanceof \DateTimeInterface || $projectStart > $projectEnd) {
            return null;
        }

        $rangeStart = (new \DateTimeImmutable($projectStart->format('Y-m-d')))->modify('monday this week')->modify('-14 days');
        $rangeEnd = (new \DateTimeImmutable($projectEnd->format('Y-m-d')))->modify('sunday this week')->modify('+14 days')->setTime(23, 59, 59);
        $cursor = (new \DateTimeImmutable($projectStart->format('Y-01-01')))->modify('monday this week');
        $index = 0;

        while ($cursor <= $rangeEnd) {
            $currentIntervalStart = $cursor;
            $currentIntervalEnd = $cursor->modify('+13 days')->setTime(23, 59, 59);

            if ($currentIntervalEnd >= $rangeStart) {
                if ($currentIntervalStart->format('Y-m-d') === $intervalStart->format('Y-m-d')) {
                    return $index;
                }

                ++$index;
            }

            $cursor = $cursor->modify('+14 days');
        }

        return null;
    }

    #[Route(path: '{code}', name: 'demo_error', methods: ['GET'])]
    public function error(string $code): Response
    {
        if ($code === '403') {
            throw $this->createAccessDeniedException();
        } elseif ($code === '404') {
            throw $this->createNotFoundException();
        }

        throw new \Exception('Error 500');
    }

    #[Route(path: '/report', name: 'demo_report', methods: ['GET', 'POST'])]
    public function report(Request $request): Response
    {
        $dateTimeFactory = $this->getDateTimeFactory();

        $values = new DemoReportQuery($dateTimeFactory->getStartOfMonth());

        $form = $this->createFormForGetRequest(DemoReportForm::class, $values, [
            'timezone' => $dateTimeFactory->getTimezone()->getName(),
        ]);

        $form->submit($request->query->all(), false);

        if ($form->isSubmitted()) {
            if (!$form->isValid()) {
                $values->setMonth($dateTimeFactory->getStartOfMonth());
            }
        }

        if ($values->getMonth() === null) {
            $values->setMonth($dateTimeFactory->getStartOfMonth());
        }

        /** @var \DateTime $start */
        $start = $values->getMonth();
        $start->modify('first day of 00:00:00');

        $end = clone $start;
        $end->modify('last day of 23:59:59');

        $previous = clone $start;
        $previous->modify('-1 month');

        $next = clone $start;
        $next->modify('+1 month');

        $data = [
            'report_title' => 'Demo report',
            'form' => $form->createView(),
            'current' => $start,
            'next' => $next,
            'previous' => $previous,
            'hasData' => false,
            'box_id' => 'demo_box_id',
        ];

        return $this->render('@Demo/report.html.twig', $data);
    }
}
