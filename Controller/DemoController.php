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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/demo')]
#[IsGranted('demo')]
final class DemoController extends AbstractController
{
    public function __construct(private DemoRepository $repository, private DemoConfiguration $configuration, private EntityManagerInterface $entityManager, private BudgetPlanStorage $budgetPlanStorage)
    {
    }

    #[Route(path: '', name: 'demo', methods: ['GET', 'POST'])]
    public function index(LocaleService $localeService): Response
    {
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

        $projects = $this->getProjects();
        $projectData = $this->buildProjectData($projects);

        $activeProjectStatuses = [];
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
        return $this->render('@Demo/resource_plan.html.twig', [
            'projects' => $this->buildProjectData($this->getActiveProjects()),
            'employees' => $this->buildEmployeeData(),
            'year' => (int) (new \DateTimeImmutable())->format('Y'),
            'is_admin' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    #[Route(path: '/resource-plan/{year}/{interval}', name: 'demo_resource_plan_get', methods: ['GET'])]
    public function getResourcePlan(int $year, int $interval): JsonResponse
    {
        $payload = $this->budgetPlanStorage->loadByName($this->resourcePlanKey($year, $interval));

        if ($payload === null) {
            return new JsonResponse(['status' => 'NEW', 'matrix' => []]);
        }

        return new JsonResponse([
            'status' => $this->normalizePlanStatus((string) ($payload['status'] ?? 'NEW')),
            'matrix' => \is_array($payload['matrix'] ?? null) ? $payload['matrix'] : [],
        ]);
    }

    #[Route(path: '/resource-plan/{year}/{interval}/status', name: 'demo_resource_plan_status', methods: ['POST'])]
    public function setResourcePlan(int $year, int $interval, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $status = $this->normalizePlanStatus((string) ($payload['status'] ?? 'NEW'));
        $matrix = \is_array($payload['matrix'] ?? null) ? $payload['matrix'] : [];

        if (\in_array($status, ['APPROVED', 'REJECTED'], true) && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Only admins can approve or reject the plan.');
        }

        $this->budgetPlanStorage->saveByName($this->resourcePlanKey($year, $interval), [
            'status' => $status,
            'matrix' => $matrix,
        ]);

        return new JsonResponse(['status' => $status, 'matrix' => $matrix]);
    }


    #[Route(path: '/budget-plan/{project}', name: 'demo_budget_plan_get', methods: ['GET'])]
    public function getBudgetPlan(Project $project): JsonResponse
    {
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


    #[Route(path: '/budget-plan/{project}/actual-costs', name: 'demo_budget_plan_actual_costs', methods: ['GET'])]
    public function getActualCosts(Project $project): JsonResponse
    {
        $start = method_exists($project, 'getStart') ? $project->getStart() : null;
        $end = method_exists($project, 'getEnd') ? $project->getEnd() : null;

        if (!$start instanceof \DateTimeInterface || !$end instanceof \DateTimeInterface || $start > $end) {
            return new JsonResponse(['weeks' => [], 'matrix' => []]);
        }

        $projectStartWeek = (new \DateTimeImmutable($start->format('Y-m-d')))->modify('monday this week')->setTime(0, 0, 0);
        $projectEndWeek = (new \DateTimeImmutable($end->format('Y-m-d')))->modify('monday this week')->setTime(0, 0, 0);
        $periodStart = $projectStartWeek->modify('-2 week');

        $biweeks = [];
        for ($cursor = $periodStart; $cursor <= $projectEndWeek; $cursor = $cursor->modify('+2 week')) {
            $biweeks[] = ['start' => $cursor, 'end' => $cursor->modify('+13 day')];
        }
        $lastBiweek = end($biweeks);
        $lastStart = \is_array($lastBiweek) && isset($lastBiweek['start']) ? $lastBiweek['start'] : $periodStart;
        $nextStart = $lastStart->modify('+2 week');
        $biweeks[] = ['start' => $nextStart, 'end' => $nextStart->modify('+13 day')];

        $periodEnd = end($biweeks)['end']->setTime(23, 59, 59);

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

            $weekStart = (new \DateTimeImmutable($timesheet->getBegin()->format('Y-m-d')))->modify('monday this week');
            $biweekIndex = $this->findBiweekIndex($biweeks, $weekStart);

            if ($biweekIndex === null) {
                continue;
            }

            $userId = (string) $timesheet->getUser()->getId();
            if (\is_array($approvedWeekMap) && !isset($approvedWeekMap[$userId . '|' . $weekStart->format('Y-m-d')])) {
                continue;
            }

            $duration = max(0, (int) $timesheet->getDuration());
            $entryHours = $duration / 3600;

            if (!isset($matrix[$userId])) {
                $matrix[$userId] = [];
            }

            if (!isset($matrix[$userId][$biweekIndex])) {
                $matrix[$userId][$biweekIndex] = 0.0;
            }

            $matrix[$userId][$biweekIndex] += (float) $entryHours;
        }

        $currentBiweekIndex = $this->findBiweekIndex($biweeks, new \DateTimeImmutable('today'));
        $resourceApproved = [];
        if ($currentBiweekIndex !== null) {
            $intervalStart = $biweeks[$currentBiweekIndex]['start'];
            $interval = $this->getYearBiweeklyIntervals((int) $intervalStart->format('Y'));
            foreach ($interval as $idx => $item) {
                if ($item['start']->format('Y-m-d') !== $intervalStart->format('Y-m-d')) {
                    continue;
                }
                $resource = $this->budgetPlanStorage->loadByName($this->resourcePlanKey((int) $intervalStart->format('Y'), $idx));
                if (\is_array($resource) && ($resource['status'] ?? 'NEW') === 'APPROVED' && \is_array($resource['matrix'] ?? null)) {
                    $resourceApproved = $resource['matrix'][(string) $project->getId()] ?? [];
                }
                break;
            }
        }

        return new JsonResponse([
            'weeks' => array_map(static function (array $biweek): array {
                return [
                    'start' => $biweek['start']->format('Y-m-d'),
                    'end' => $biweek['end']->format('Y-m-d'),
                ];
            }, $biweeks),
            'matrix' => $matrix,
            'currentIndex' => $currentBiweekIndex,
            'resourceApproved' => $resourceApproved,
        ]);
    }

    private function findBiweekIndex(array $biweeks, \DateTimeImmutable $date): ?int
    {
        foreach ($biweeks as $idx => $biweek) {
            if ($date >= $biweek['start'] && $date <= $biweek['end']) {
                return $idx;
            }
        }

        return null;
    }

    private function getProjects(): array
    {
        $projects = $this->entityManager->getRepository(Project::class)->findAll();
        usort($projects, static fn (Project $a, Project $b) => strcasecmp($a->getName(), $b->getName()));

        return $projects;
    }

    private function getActiveProjects(): array
    {
        return array_values(array_filter($this->getProjects(), static function (Project $project): bool {
            if (method_exists($project, 'isVisible')) {
                return (bool) $project->isVisible();
            }
            if (method_exists($project, 'getVisible')) {
                return (bool) $project->getVisible();
            }

            return true;
        }));
    }

    private function buildProjectData(array $projects): array
    {
        $projectData = [];
        foreach ($projects as $project) {
            if (!$project instanceof Project) {
                continue;
            }
            $start = method_exists($project, 'getStart') ? $project->getStart() : null;
            $end = method_exists($project, 'getEnd') ? $project->getEnd() : null;
            $budget = method_exists($project, 'getBudget') ? $project->getBudget() : null;
            $projectData[] = [
                'id' => $project->getId(),
                'name' => $project->getName(),
                'start' => $start instanceof \DateTimeInterface ? $start->format('Y-m-d') : null,
                'end' => $end instanceof \DateTimeInterface ? $end->format('Y-m-d') : null,
                'budget' => is_numeric($budget) ? (float) $budget : 0.0,
            ];
        }

        return $projectData;
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
                'shortName' => $this->buildShortName($alias !== '' ? $alias : ('User ' . $user->getId())),
                'hourlyRate' => $hourlyRate,
            ];
        }

        return $employees;
    }

    private function buildShortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (!\is_array($parts) || count($parts) < 2) {
            return $name;
        }

        return $parts[1] . ' ' . mb_substr($parts[0], 0, 1) . '.';
    }

    private function getYearBiweeklyIntervals(int $year): array
    {
        $start = (new \DateTimeImmutable(sprintf('%d-01-01', $year)))->modify('monday this week');
        $end = (new \DateTimeImmutable(sprintf('%d-12-31', $year)))->modify('sunday this week');
        $intervals = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+2 week')) {
            $intervals[] = ['start' => $cursor, 'end' => $cursor->modify('+13 day')];
        }

        return $intervals;
    }

    private function resourcePlanKey(int $year, int $interval): string
    {
        return sprintf('resource_plan_%d_%d', $year, $interval);
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
