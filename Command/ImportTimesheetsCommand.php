<?php

namespace KimaiPlugin\DemoBundle\Command;

use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'demo:import-timesheets',
    description: 'Import timesheets into Kimai from an XLSX file with Login, Date, Project and Time columns.'
)]
final class ImportTimesheetsCommand extends Command
{
    private const REQUIRED_HEADERS = ['login', 'date', 'project', 'time'];
    private const ACTIVITY_NAME = 'Реализация';
    private const EXCEL_DATE_BASE = '1899-12-30';
    private const BATCH_SIZE = 100;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Absolute path to the XLSX file to import');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and preview the import without writing to the database');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Import only the first N data rows', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $this->normalizeLimit($input->getOption('limit'));

        if ($limit === false) {
            $io->error('Option --limit must be a positive integer.');

            return Command::FAILURE;
        }

        if (!is_file($file)) {
            $io->error('File not found: ' . $file);

            return Command::FAILURE;
        }

        if (strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) !== 'xlsx') {
            $io->error('Only .xlsx files are supported.');

            return Command::FAILURE;
        }

        $io->title('Kimai timesheet import');
        $io->text('Source file: ' . $file);
        $io->text('Activity rule: per-project activity named "' . self::ACTIVITY_NAME . '"');
        $io->text('Mode: ' . ($dryRun ? 'dry-run (no database writes)' : 'write to database'));
        if ($limit !== null) {
            $io->text('Row limit: ' . $limit);
        }
        $io->newLine();

        try {
            $rows = $this->readRowsFromXlsx($file);
        } catch (\Throwable $exception) {
            $io->error('Failed to read XLSX file: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        if ($rows === []) {
            $io->warning('No data rows found in the file.');

            return Command::SUCCESS;
        }

        if ($limit !== null) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $usersByLogin = $this->buildUserIndex();
        $projectsByName = $this->buildProjectIndex();
        $activitiesByProjectAndName = $this->buildActivityIndex();

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $row) {
            $line = (int) ($row['line'] ?? 0);
            $login = trim((string) ($row['login'] ?? ''));
            $projectName = trim((string) ($row['project'] ?? ''));
            $hours = $this->normalizeHours($row['time'] ?? null);
            $date = $this->normalizeExcelDate($row['date'] ?? null);

            if ($login === '' || $projectName === '' || $hours === null || $date === null) {
                $errors[] = sprintf('Line %d: invalid data set', $line);
                ++$skipped;
                continue;
            }

            $user = $usersByLogin[mb_strtolower($login)] ?? null;
            if (!$user instanceof User) {
                $errors[] = sprintf('Line %d: user "%s" not found', $line, $login);
                ++$skipped;
                continue;
            }

            $project = $projectsByName[$projectName] ?? null;
            if (!$project instanceof Project) {
                $errors[] = sprintf('Line %d: project "%s" not found', $line, $projectName);
                ++$skipped;
                continue;
            }

            $activityKey = $this->buildActivityKey((int) $project->getId(), self::ACTIVITY_NAME);
            $activity = $activitiesByProjectAndName[$activityKey] ?? null;
            if (!$activity instanceof Activity) {
                $errors[] = sprintf(
                    'Line %d: activity "%s" not found for project "%s"',
                    $line,
                    self::ACTIVITY_NAME,
                    $projectName
                );
                ++$skipped;
                continue;
            }

            $timesheet = $this->createTimesheet($user, $project, $activity, $date, $hours);
            if (!$dryRun) {
                $this->entityManager->persist($timesheet);
            }
            ++$imported;

            if (!$dryRun && ($imported % self::BATCH_SIZE) === 0) {
                $this->entityManager->flush();
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%s %d rows. Skipped %d rows.',
            $dryRun ? 'Validated' : 'Imported',
            $imported,
            $skipped
        ));

        if ($errors !== []) {
            $io->newLine();
            $io->section('Skipped rows');
            $io->listing($errors);
        }

        return $skipped > 0 ? Command::INVALID : Command::SUCCESS;
    }

    private function normalizeLimit(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return false;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : false;
    }

    /**
     * @return array<int, array{line:int, login:mixed, date:mixed, project:mixed, time:mixed}>
     */
    private function readRowsFromXlsx(string $file): array
    {
        $archive = new \ZipArchive();
        if ($archive->open($file) !== true) {
            throw new \RuntimeException('Cannot open XLSX archive');
        }

        try {
            $sharedStrings = $this->readSharedStrings($archive);
            $sheetPath = $this->resolveFirstWorksheetPath($archive);
            $sheetXml = $archive->getFromName($sheetPath);
            if (!is_string($sheetXml)) {
                throw new \RuntimeException('Worksheet XML not found');
            }

            $xml = simplexml_load_string($sheetXml);
            if (!$xml instanceof \SimpleXMLElement) {
                throw new \RuntimeException('Worksheet XML is invalid');
            }

            $xml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rows = $xml->xpath('/main:worksheet/main:sheetData/main:row');
            if (!is_array($rows) || $rows === []) {
                return [];
            }

            $headerMap = [];
            $result = [];

            foreach ($rows as $rowIndex => $row) {
                if (!$row instanceof \SimpleXMLElement) {
                    continue;
                }

                $line = (int) ($row['r'] ?? ($rowIndex + 1));
                $rowValues = $this->extractRowValues($row, $sharedStrings);

                if ($headerMap === []) {
                    $headerMap = $this->resolveHeaderMap($rowValues);
                    continue;
                }

                if ($this->rowIsEmpty($rowValues)) {
                    continue;
                }

                $result[] = [
                    'line' => $line,
                    'login' => $rowValues[$headerMap['login']] ?? null,
                    'date' => $rowValues[$headerMap['date']] ?? null,
                    'project' => $rowValues[$headerMap['project']] ?? null,
                    'time' => $rowValues[$headerMap['time']] ?? null,
                ];
            }

            return $result;
        } finally {
            $archive->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(\ZipArchive $archive): array
    {
        $xml = $archive->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml)) {
            return [];
        }

        $sharedStringsXml = simplexml_load_string($xml);
        if (!$sharedStringsXml instanceof \SimpleXMLElement) {
            return [];
        }

        $sharedStringsXml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $items = $sharedStringsXml->xpath('/main:sst/main:si');
        if (!is_array($items)) {
            return [];
        }

        $strings = [];
        foreach ($items as $item) {
            if (!$item instanceof \SimpleXMLElement) {
                $strings[] = '';
                continue;
            }

            $textNodes = $item->xpath('.//main:t');
            if (!is_array($textNodes) || $textNodes === []) {
                $strings[] = '';
                continue;
            }

            $value = '';
            foreach ($textNodes as $textNode) {
                $value .= (string) $textNode;
            }

            $strings[] = $value;
        }

        return $strings;
    }

    private function resolveFirstWorksheetPath(\ZipArchive $archive): string
    {
        $workbookXml = $archive->getFromName('xl/workbook.xml');
        $relationsXml = $archive->getFromName('xl/_rels/workbook.xml.rels');

        if (!is_string($workbookXml) || !is_string($relationsXml)) {
            throw new \RuntimeException('Workbook metadata is incomplete');
        }

        $workbook = simplexml_load_string($workbookXml);
        $relations = simplexml_load_string($relationsXml);
        if (!$workbook instanceof \SimpleXMLElement || !$relations instanceof \SimpleXMLElement) {
            throw new \RuntimeException('Workbook metadata is invalid');
        }

        $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $relations->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $sheetNodes = $workbook->xpath('/main:workbook/main:sheets/main:sheet');
        if (!is_array($sheetNodes) || $sheetNodes === []) {
            throw new \RuntimeException('Workbook contains no sheets');
        }

        $sheetId = (string) ($sheetNodes[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        if ($sheetId === '') {
            throw new \RuntimeException('Cannot resolve first worksheet id');
        }

        $relationNodes = $relations->xpath('/rel:Relationships/rel:Relationship');
        if (!is_array($relationNodes)) {
            throw new \RuntimeException('Worksheet relations are missing');
        }

        foreach ($relationNodes as $relationNode) {
            if (!$relationNode instanceof \SimpleXMLElement) {
                continue;
            }

            if ((string) ($relationNode['Id'] ?? '') !== $sheetId) {
                continue;
            }

            $target = trim((string) ($relationNode['Target'] ?? ''));
            if ($target === '') {
                break;
            }

            return 'xl/' . ltrim($target, '/');
        }

        throw new \RuntimeException('Cannot resolve worksheet path');
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, string>
     */
    private function extractRowValues(\SimpleXMLElement $row, array $sharedStrings): array
    {
        $row->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cells = $row->xpath('./main:c');
        if (!is_array($cells)) {
            return [];
        }

        $values = [];
        foreach ($cells as $cell) {
            if (!$cell instanceof \SimpleXMLElement) {
                continue;
            }

            $reference = (string) ($cell['r'] ?? '');
            $column = $this->columnReferenceToIndex($reference);
            if ($column === null) {
                continue;
            }

            $type = (string) ($cell['t'] ?? '');
            $value = '';

            if ($type === 'inlineStr') {
                $textNodes = $cell->xpath('./main:is/main:t');
                if (is_array($textNodes)) {
                    foreach ($textNodes as $textNode) {
                        $value .= (string) $textNode;
                    }
                }
            } else {
                $valueNode = $cell->xpath('./main:v');
                $value = is_array($valueNode) && isset($valueNode[0]) ? (string) $valueNode[0] : '';
                if ($type === 's') {
                    $sharedIndex = (int) $value;
                    $value = $sharedStrings[$sharedIndex] ?? '';
                }
            }

            $values[$column] = $value;
        }

        return $values;
    }

    /**
     * @param array<int, string> $rowValues
     * @return array<string, int>
     */
    private function resolveHeaderMap(array $rowValues): array
    {
        $headers = [];
        foreach ($rowValues as $index => $value) {
            $headers[mb_strtolower(trim($value))] = $index;
        }

        foreach (self::REQUIRED_HEADERS as $header) {
            if (!isset($headers[$header])) {
                throw new \RuntimeException('Required header missing: ' . $header);
            }
        }

        return [
            'login' => $headers['login'],
            'date' => $headers['date'],
            'project' => $headers['project'],
            'time' => $headers['time'],
        ];
    }

    /**
     * @param array<int, string> $rowValues
     */
    private function rowIsEmpty(array $rowValues): bool
    {
        foreach ($rowValues as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function columnReferenceToIndex(string $reference): ?int
    {
        $letters = preg_replace('/[^A-Z]/i', '', $reference);
        if (!is_string($letters) || $letters === '') {
            return null;
        }

        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    /**
     * @return array<string, User>
     */
    private function buildUserIndex(): array
    {
        $users = $this->entityManager->getRepository(User::class)->findAll();
        $index = [];

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if (method_exists($user, 'getUsername')) {
                $username = trim((string) $user->getUsername());
                if ($username !== '') {
                    $index[mb_strtolower($username)] = $user;
                }
            }

            if (method_exists($user, 'getAlias')) {
                $alias = trim((string) $user->getAlias());
                if ($alias !== '') {
                    $index[mb_strtolower($alias)] = $user;
                }
            }
        }

        return $index;
    }

    /**
     * @return array<string, Project>
     */
    private function buildProjectIndex(): array
    {
        $projects = $this->entityManager->getRepository(Project::class)->findAll();
        $index = [];

        foreach ($projects as $project) {
            if (!$project instanceof Project) {
                continue;
            }

            $name = trim((string) $project->getName());
            if ($name !== '') {
                $index[$name] = $project;
            }
        }

        return $index;
    }

    /**
     * @return array<string, Activity>
     */
    private function buildActivityIndex(): array
    {
        $activities = $this->entityManager->getRepository(Activity::class)->findAll();
        $index = [];

        foreach ($activities as $activity) {
            if (!$activity instanceof Activity || $activity->getProject() === null) {
                continue;
            }

            $name = trim((string) $activity->getName());
            if ($name === '') {
                continue;
            }

            $index[$this->buildActivityKey((int) $activity->getProject()->getId(), $name)] = $activity;
        }

        return $index;
    }

    private function buildActivityKey(int $projectId, string $activityName): string
    {
        return $projectId . '::' . mb_strtolower(trim($activityName));
    }

    private function normalizeHours(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim(str_replace(',', '.', $value));
        }

        if (!is_numeric($value)) {
            return null;
        }

        $hours = (float) $value;
        if ($hours <= 0) {
            return null;
        }

        return $hours;
    }

    private function normalizeExcelDate(mixed $value): ?\DateTimeImmutable
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (is_numeric(str_replace(',', '.', $trimmed))) {
                $value = (float) str_replace(',', '.', $trimmed);
            } else {
                try {
                    return new \DateTimeImmutable($trimmed);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        if (!is_numeric($value)) {
            return null;
        }

        $days = (int) floor((float) $value);
        return (new \DateTimeImmutable(self::EXCEL_DATE_BASE))->modify('+' . $days . ' days');
    }

    private function createTimesheet(
        User $user,
        Project $project,
        Activity $activity,
        \DateTimeImmutable $date,
        float $hours
    ): Timesheet {
        $duration = (int) round($hours * 3600);
        $begin = $date->setTime(0, 0, 0);
        $end = $begin->modify('+' . $duration . ' seconds');

        $timesheet = new Timesheet();
        $timesheet->setUser($user);
        $timesheet->setProject($project);
        $timesheet->setActivity($activity);
        $timesheet->setBegin($begin);
        $timesheet->setEnd($end);
        $timesheet->setDuration($duration);
        $timesheet->setDescription('Imported from Excel');

        return $timesheet;
    }
}
