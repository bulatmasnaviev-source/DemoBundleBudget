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

        $projects = $this->entityManager->getRepository(Project::class)->findAll();
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
            ];
        }

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

        $periodStart = (new \DateTimeImmutable($start->format('Y-m-d')))->modify('monday this week')->modify('-1 week')->setTime(0, 0, 0);
        $periodEnd = (new \DateTimeImmutable($end->format('Y-m-d')))->modify('sunday this week')->modify('+2 week')->setTime(23, 59, 59);

        $weeks = [];
        $cursor = $periodStart;
        while ($cursor <= $periodEnd) {
            $weeks[] = $cursor;
            $cursor = $cursor->modify('+1 week');
        }

        $indexByWeekStart = [];
        foreach ($weeks as $idx => $weekStart) {
            $indexByWeekStart[$weekStart->format('Y-m-d')] = $idx;
        }

        $matrix = [];

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
            $weekIndex = $indexByWeekStart[$weekStart];

            $entryCost = $timesheet->getRate();
            if (!\is_numeric($entryCost)) {
                $duration = max(0, (int) $timesheet->getDuration());
                $hourly = (float) $timesheet->getHourlyRate();
                $entryCost = ($duration / 3600) * $hourly;
            }

            if (!isset($matrix[$userId])) {
                $matrix[$userId] = [];
            }

            if (!isset($matrix[$userId][$weekIndex])) {
                $matrix[$userId][$weekIndex] = 0.0;
            }

            $matrix[$userId][$weekIndex] += (float) $entryCost;
        }

        return new JsonResponse([
            'weeks' => array_map(static function (\DateTimeImmutable $weekStart): array {
                return [
                    'start' => $weekStart->format('Y-m-d'),
                    'label' => 'W' . $weekStart->format('W') . '-' . $weekStart->format('y'),
                ];
            }, $weeks),
            'matrix' => $matrix,
        ]);
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
