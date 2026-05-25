<?php
/**
  * Что показывает проект:
 * - автообновляемый операционный дашборд для руководителя;
 * - разделение входящих заявок и исходящего прозвона;
 * - агрегацию контактов за день и неделю;
 * - агрегацию звонков и времени разговоров из телефонии;
 * - пример подключения к Bitrix24 CRM и таблице телефонии;
 * - безопасный demo-mode без доступа к рабочей CRM.
 *
 * Запуск демо:
 *   php -S localhost:8080 public_calls_requests_dashboard.php
 *
 * Рабочий режим внутри Bitrix:
 *   DASHBOARD_DATA_SOURCE=bitrix
 *   B24_DEPARTMENT_IDS=101,102,103
 *   B24_INCOMING_USER_IDS=1001,1002,1003,1004
 *   B24_COLD_CALL_USER_IDS=1005,1006
 *   B24_CRM_CATEGORY_IDS=10,11
 *   B24_UF_OPERATOR_ID=UF_CRM_OPERATOR_ID
 *   B24_UF_CONTACT_CREATED_AT=UF_CRM_CONTACT_CREATED_AT
 *   B24_UF_SENT_TO_SALES_AT=UF_CRM_SENT_TO_SALES_AT
 */

declare(strict_types=1);

const DASHBOARD_TITLE = 'Дашборд контроля заявок и звонков';
const DEFAULT_DATA_SOURCE = 'demo';

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/Moscow');

function h(string $value): string
{
    if (function_exists('htmlspecialcharsbx')) {
        return htmlspecialcharsbx($value);
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatDuration(int $seconds): string
{
    return sprintf(
        '%02d:%02d:%02d',
        (int)floor($seconds / 3600),
        (int)floor(($seconds % 3600) / 60),
        $seconds % 60
    );
}

function parseEnvIntList(string $name, array $default = []): array
{
    $raw = getenv($name);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    return array_values(array_filter(array_map(
        static fn(string $item): int => (int)trim($item),
        explode(',', $raw)
    )));
}

function validateSqlIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Небезопасное имя SQL-поля в конфигурации.');
    }

    return $identifier;
}

final class DashboardConfig
{
    public array $departmentIds;
    public array $incomingUserIds;
    public array $coldCallUserIds;
    public array $crmCategoryIds;

    public string $ufOperatorId;
    public string $ufContactCreatedAt;
    public string $ufSentToSalesAt;

    public function __construct()
    {
        $this->departmentIds = parseEnvIntList('B24_DEPARTMENT_IDS');
        $this->incomingUserIds = parseEnvIntList('B24_INCOMING_USER_IDS');
        $this->coldCallUserIds = parseEnvIntList('B24_COLD_CALL_USER_IDS');
        $this->crmCategoryIds = parseEnvIntList('B24_CRM_CATEGORY_IDS');

        $this->ufOperatorId = getenv('B24_UF_OPERATOR_ID') ?: 'UF_CRM_OPERATOR_ID';
        $this->ufContactCreatedAt = getenv('B24_UF_CONTACT_CREATED_AT') ?: 'UF_CRM_CONTACT_CREATED_AT';
        $this->ufSentToSalesAt = getenv('B24_UF_SENT_TO_SALES_AT') ?: 'UF_CRM_SENT_TO_SALES_AT';
    }

    public function validateForBitrixMode(): void
    {
        $missing = [];

        if (!$this->departmentIds) {
            $missing[] = 'B24_DEPARTMENT_IDS';
        }

        if (!$this->incomingUserIds) {
            $missing[] = 'B24_INCOMING_USER_IDS';
        }

        if (!$this->coldCallUserIds) {
            $missing[] = 'B24_COLD_CALL_USER_IDS';
        }

        if (!$this->crmCategoryIds) {
            $missing[] = 'B24_CRM_CATEGORY_IDS';
        }

        if ($missing) {
            throw new RuntimeException(
                'Не заданы обязательные переменные окружения: ' . implode(', ', $missing)
            );
        }
    }
}

interface DashboardDataProvider
{
    public function getData(): array;
}

final class DemoDashboardDataProvider implements DashboardDataProvider
{
    public function getData(): array
    {
        $incomingDepartments = [
            [
                'department' => 'Канал входящих A',
                'employees' => [
                    ['id' => 'in_1', 'name' => 'Алексей Орлов'],
                ],
            ],
            [
                'department' => 'Канал входящих B',
                'employees' => [
                    ['id' => 'in_2', 'name' => 'Никита Волков'],
                ],
            ],
            [
                'department' => 'Канал входящих C',
                'employees' => [
                    ['id' => 'in_3', 'name' => 'Мария Соколова'],
                ],
            ],
            [
                'department' => 'Основной поток',
                'employees' => [
                    ['id' => 'in_4', 'name' => 'Анна Белова'],
                ],
            ],
        ];

        $coldDepartments = [
            [
                'department' => 'Исходящий канал A',
                'employees' => [
                    ['id' => 'cold_1', 'name' => 'Елена Смирнова'],
                ],
            ],
            [
                'department' => 'Исходящий канал B',
                'employees' => [
                    ['id' => 'cold_2', 'name' => 'Ольга Морозова'],
                ],
            ],
        ];

        $values = [
            'in_1' => [
                'day_contacts_in' => 18,
                'day_contacts_sent_to_sales' => 7,
                'day_calls_count' => 14,
                'day_calls_duration_seconds' => 870,
                'week_contacts_in' => 54,
                'week_contacts_sent_to_sales' => 21,
                'week_calls_count' => 61,
                'week_calls_duration_seconds' => 3580,
            ],
            'in_2' => [
                'day_contacts_in' => 33,
                'day_contacts_sent_to_sales' => 14,
                'day_calls_count' => 28,
                'day_calls_duration_seconds' => 1890,
                'week_contacts_in' => 96,
                'week_contacts_sent_to_sales' => 38,
                'week_calls_count' => 122,
                'week_calls_duration_seconds' => 8120,
            ],
            'in_3' => [
                'day_contacts_in' => 42,
                'day_contacts_sent_to_sales' => 16,
                'day_calls_count' => 31,
                'day_calls_duration_seconds' => 2160,
                'week_contacts_in' => 118,
                'week_contacts_sent_to_sales' => 47,
                'week_calls_count' => 137,
                'week_calls_duration_seconds' => 9360,
            ],
            'in_4' => [
                'day_contacts_in' => 24,
                'day_contacts_sent_to_sales' => 9,
                'day_calls_count' => 17,
                'day_calls_duration_seconds' => 1280,
                'week_contacts_in' => 73,
                'week_contacts_sent_to_sales' => 29,
                'week_calls_count' => 76,
                'week_calls_duration_seconds' => 5110,
            ],
            'cold_1' => [
                'day_contacts_in' => 6,
                'day_contacts_sent_to_sales' => 4,
                'day_calls_count' => 97,
                'day_calls_duration_seconds' => 3860,
                'week_contacts_in' => 21,
                'week_contacts_sent_to_sales' => 14,
                'week_calls_count' => 238,
                'week_calls_duration_seconds' => 10340,
            ],
            'cold_2' => [
                'day_contacts_in' => 5,
                'day_contacts_sent_to_sales' => 3,
                'day_calls_count' => 104,
                'day_calls_duration_seconds' => 3420,
                'week_contacts_in' => 18,
                'week_contacts_sent_to_sales' => 12,
                'week_calls_count' => 251,
                'week_calls_duration_seconds' => 9680,
            ],
        ];

        $incomingEmployees = self::flattenEmployees($incomingDepartments);
        $coldEmployees = self::flattenEmployees($coldDepartments);
        $allEmployees = array_merge($incomingEmployees, $coldEmployees);

        return [
            'incomingDepartments' => $incomingDepartments,
            'coldDepartments' => $coldDepartments,
            'incomingEmployees' => $incomingEmployees,
            'coldEmployees' => $coldEmployees,
            'metrics' => self::buildMetrics($allEmployees, $values),
            'meta' => [
                'source' => 'Синтетические демо-данные',
                'note' => 'В рабочей версии данные загружаются из Bitrix24 CRM и таблицы телефонии.',
                'generatedAt' => date('Y-m-d H:i:s'),
            ],
        ];
    }

    public static function flattenEmployees(array $departments): array
    {
        $employees = [];

        foreach ($departments as $department) {
            foreach ($department['employees'] as $employee) {
                $employees[] = $employee;
            }
        }

        return $employees;
    }

    public static function buildMetrics(array $employees, array $values): array
    {
        $metricDefinitions = [
            'day_contacts_in' => ['label' => 'Поступило контактов', 'type' => 'number'],
            'day_contacts_sent_to_sales' => ['label' => 'Передано в отдел продаж', 'type' => 'number'],
            'day_calls_count' => ['label' => 'Количество звонков', 'type' => 'number'],
            'day_calls_duration' => ['label' => 'Время разговоров', 'type' => 'duration', 'source' => 'day_calls_duration_seconds'],
            'week_contacts_in' => ['label' => 'Поступило контактов', 'type' => 'number'],
            'week_contacts_sent_to_sales' => ['label' => 'Передано в отдел продаж', 'type' => 'number'],
            'week_calls_count' => ['label' => 'Количество звонков', 'type' => 'number'],
            'week_calls_duration' => ['label' => 'Время разговоров', 'type' => 'duration', 'source' => 'week_calls_duration_seconds'],
            'week_calls_avg_per_day' => ['label' => 'Среднее время на линии в день', 'type' => 'duration_avg', 'source' => 'week_calls_duration_seconds'],
        ];

        $metrics = [];

        foreach ($metricDefinitions as $key => $definition) {
            $sourceKey = $definition['source'] ?? $key;
            $rowValues = [];
            $total = 0;

            foreach ($employees as $employee) {
                $employeeId = $employee['id'];
                $rawValue = (int)($values[$employeeId][$sourceKey] ?? 0);

                if ($definition['type'] === 'duration_avg') {
                    $displayValue = formatDuration((int)round($rawValue / 5));
                    $total += $rawValue;
                } elseif ($definition['type'] === 'duration') {
                    $displayValue = formatDuration($rawValue);
                    $total += $rawValue;
                } else {
                    $displayValue = (string)$rawValue;
                    $total += $rawValue;
                }

                $rowValues[$employeeId] = $displayValue;
            }

            if ($definition['type'] === 'duration_avg') {
                $totalDisplay = formatDuration((int)round($total / 5));
            } elseif ($definition['type'] === 'duration') {
                $totalDisplay = formatDuration($total);
            } else {
                $totalDisplay = (string)$total;
            }

            $metrics[$key] = [
                'label' => $definition['label'],
                'total' => $totalDisplay,
                'values' => $rowValues,
            ];
        }

        return $metrics;
    }
}

final class BitrixTelephonyDataProvider implements DashboardDataProvider
{
    private DashboardConfig $config;

    public function __construct(DashboardConfig $config)
    {
        $this->config = $config;
        $this->config->validateForBitrixMode();
    }

    public function getData(): array
    {
        $this->bootstrapBitrix();

        if (!\Bitrix\Main\Loader::includeModule('crm')) {
            throw new RuntimeException('Модуль CRM не подключен.');
        }

        $telephonyAvailable = \Bitrix\Main\Loader::includeModule('voximplant');

        $incomingDepartments = $this->loadDepartments($this->config->incomingUserIds, 'Входящий поток');
        $coldDepartments = $this->loadDepartments($this->config->coldCallUserIds, 'Исходящий прозвон');

        $incomingEmployees = DemoDashboardDataProvider::flattenEmployees($incomingDepartments);
        $coldEmployees = DemoDashboardDataProvider::flattenEmployees($coldDepartments);
        $allEmployees = array_merge($incomingEmployees, $coldEmployees);

        $values = [];
        foreach ($allEmployees as $employee) {
            $values[$employee['id']] = [
                'day_contacts_in' => 0,
                'day_contacts_sent_to_sales' => 0,
                'day_calls_count' => 0,
                'day_calls_duration_seconds' => 0,
                'week_contacts_in' => 0,
                'week_contacts_sent_to_sales' => 0,
                'week_calls_count' => 0,
                'week_calls_duration_seconds' => 0,
            ];
        }

        $this->loadContactMetrics($values, 'day');
        $this->loadContactMetrics($values, 'week');

        if ($telephonyAvailable) {
            $this->loadCallMetrics($values, 'day');
            $this->loadCallMetrics($values, 'week');
        }

        return [
            'incomingDepartments' => $incomingDepartments,
            'coldDepartments' => $coldDepartments,
            'incomingEmployees' => $incomingEmployees,
            'coldEmployees' => $coldEmployees,
            'metrics' => DemoDashboardDataProvider::buildMetrics($allEmployees, $values),
            'meta' => [
                'source' => 'Bitrix24 CRM + телефония',
                'note' => 'Данные загружены через безопасный Bitrix-провайдер, настроенный переменными окружения.',
                'generatedAt' => date('Y-m-d H:i:s'),
            ],
        ];
    }

    private function bootstrapBitrix(): void
    {
        if (defined('B_PROLOG_INCLUDED')) {
            return;
        }

        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? getenv('BITRIX_DOCUMENT_ROOT') ?: null;
        $bootstrapFile = $documentRoot ? $documentRoot . '/bitrix/modules/main/include/prolog_before.php' : null;

        if (!$bootstrapFile || !is_file($bootstrapFile)) {
            throw new RuntimeException(
                'Не найден prolog_before.php. Запустите файл внутри Bitrix или задайте BITRIX_DOCUMENT_ROOT.'
            );
        }

        require_once $bootstrapFile;
    }

    private function loadDepartments(array $userIds, string $fallbackDepartmentName): array
    {
        $departments = [];

        $result = \Bitrix\Main\UserTable::getList([
            'filter' => [
                '=ID' => $userIds,
                '=ACTIVE' => true,
            ],
            'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN'],
        ]);

        while ($user = $result->fetch()) {
            $userId = (int)$user['ID'];
            $fullName = trim(($user['NAME'] ?: $user['LOGIN']) . ' ' . ($user['LAST_NAME'] ?? ''));

            $departments[] = [
                'department' => $fallbackDepartmentName,
                'employees' => [
                    [
                        'id' => (string)$userId,
                        'name' => $fullName !== '' ? $fullName : ('Пользователь #' . $userId),
                    ],
                ],
            ];
        }

        return $departments;
    }

    private function loadContactMetrics(array &$values, string $period): void
    {
        $db = $GLOBALS['DB'];

        $operatorField = validateSqlIdentifier($this->config->ufOperatorId);
        $createdAtField = validateSqlIdentifier($this->config->ufContactCreatedAt);
        $sentToSalesAtField = validateSqlIdentifier($this->config->ufSentToSalesAt);

        $userIdsSql = implode(',', array_map('intval', array_keys($values)));
        $categoryIdsSql = implode(',', array_map('intval', $this->config->crmCategoryIds));

        [$from, $to] = $this->getPeriodBounds($period);
        $prefix = $period === 'day' ? 'day' : 'week';

        $contactsSql = "
            SELECT u.{$operatorField} AS USER_ID, COUNT(*) AS CNT
            FROM b_crm_deal d
            INNER JOIN b_uts_crm_deal u ON u.VALUE_ID = d.ID
            WHERE d.CATEGORY_ID IN ({$categoryIdsSql})
              AND u.{$operatorField} IN ({$userIdsSql})
              AND u.{$createdAtField} >= '{$db->ForSql($from)}'
              AND u.{$createdAtField} < '{$db->ForSql($to)}'
            GROUP BY u.{$operatorField}
        ";

        $this->applyCountQuery($values, $contactsSql, "{$prefix}_contacts_in");

        $sentToSalesSql = "
            SELECT u.{$operatorField} AS USER_ID, COUNT(*) AS CNT
            FROM b_crm_deal d
            INNER JOIN b_uts_crm_deal u ON u.VALUE_ID = d.ID
            WHERE d.CATEGORY_ID IN ({$categoryIdsSql})
              AND u.{$operatorField} IN ({$userIdsSql})
              AND u.{$sentToSalesAtField} >= '{$db->ForSql($from)}'
              AND u.{$sentToSalesAtField} < '{$db->ForSql($to)}'
            GROUP BY u.{$operatorField}
        ";

        $this->applyCountQuery($values, $sentToSalesSql, "{$prefix}_contacts_sent_to_sales");
    }

    private function loadCallMetrics(array &$values, string $period): void
    {
        $db = $GLOBALS['DB'];
        $userIdsSql = implode(',', array_map('intval', array_keys($values)));

        [$from, $to] = $this->getPeriodBounds($period);
        $prefix = $period === 'day' ? 'day' : 'week';

        $sql = "
            SELECT
                PORTAL_USER_ID AS USER_ID,
                COUNT(*) AS CALLS_COUNT,
                SUM(CALL_DURATION) AS DURATION_SECONDS
            FROM b_voximplant_statistic
            WHERE PORTAL_USER_ID IN ({$userIdsSql})
              AND CALL_START_DATE >= '{$db->ForSql($from)}'
              AND CALL_START_DATE < '{$db->ForSql($to)}'
              AND CALL_DURATION >= 10
            GROUP BY PORTAL_USER_ID
        ";

        $result = $db->Query($sql);

        while ($row = $result->Fetch()) {
            $userId = (string)(int)$row['USER_ID'];

            if (!isset($values[$userId])) {
                continue;
            }

            $values[$userId]["{$prefix}_calls_count"] = (int)$row['CALLS_COUNT'];
            $values[$userId]["{$prefix}_calls_duration_seconds"] = (int)$row['DURATION_SECONDS'];
        }
    }

    private function applyCountQuery(array &$values, string $sql, string $metricKey): void
    {
        $result = $GLOBALS['DB']->Query($sql);

        while ($row = $result->Fetch()) {
            $userId = (string)(int)$row['USER_ID'];

            if (isset($values[$userId])) {
                $values[$userId][$metricKey] = (int)$row['CNT'];
            }
        }
    }

    private function getPeriodBounds(string $period): array
    {
        if ($period === 'week') {
            return [
                date('Y-m-d 00:00:00', strtotime('monday this week')),
                date('Y-m-d 00:00:00', strtotime('monday next week')),
            ];
        }

        return [
            date('Y-m-d 00:00:00'),
            date('Y-m-d 00:00:00', strtotime('+1 day')),
        ];
    }
}

function createDataProvider(): DashboardDataProvider
{
    $source = getenv('DASHBOARD_DATA_SOURCE') ?: DEFAULT_DATA_SOURCE;

    if ($source === 'bitrix') {
        return new BitrixTelephonyDataProvider(new DashboardConfig());
    }

    return new DemoDashboardDataProvider();
}

function renderSectionTitle(string $title, int $totalColumns, string $extraClass = ''): void
{
    echo '<tr>';
    echo '<td colspan="' . $totalColumns . '" class="section-title ' . h($extraClass) . ' border text-center">';
    echo h(mb_strtoupper($title));
    echo '</td>';
    echo '</tr>';
}

function renderMetricRow(array $metric, array $employees, bool $hasSeparator = true): void
{
    echo '<tr>';
    echo '<td class="metric-cell col-metric border text-left">' . h($metric['label']) . '</td>';
    echo '<td class="total-cell col-total border text-center">' . h((string)$metric['total']) . '</td>';

    $incomingCount = 4;
    $index = 0;

    foreach ($employees as $employee) {
        if ($hasSeparator && $index === $incomingCount) {
            echo '<td class="separator-cell col-separator border"></td>';
        }

        $employeeId = (string)$employee['id'];
        echo '<td class="value-cell col-user border text-center">';
        echo h((string)($metric['values'][$employeeId] ?? '0'));
        echo '</td>';

        $index++;
    }

    echo '</tr>';
}

try {
    $data = createDataProvider()->getData();

    $incomingDepartments = $data['incomingDepartments'];
    $coldDepartments = $data['coldDepartments'];
    $incomingEmployees = $data['incomingEmployees'];
    $coldEmployees = $data['coldEmployees'];
    $allEmployees = array_merge($incomingEmployees, $coldEmployees);
    $metrics = $data['metrics'];
    $meta = $data['meta'];

    $totalColumns = count($incomingEmployees) + count($coldEmployees) + 3;
} catch (Throwable $exception) {
    http_response_code(500);
    echo '<h1>Ошибка дашборда</h1>';
    echo '<p>' . h($exception->getMessage()) . '</p>';
    exit;
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= h(DASHBOARD_TITLE) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        :root {
            --page-bg: #dfe6ee;
            --table-bg: #ffffff;
            --header-light-bg: #dce6f1;
            --group-header-bg: #c8d8e8;
            --dept-header-bg: #e6edf5;
            --employee-header-bg: #f6f8fb;
            --section-bg: #b8cde0;
            --week-section-bg: #9fbad2;
            --metric-bg: #edf3f8;
            --total-bg: #d4e3f2;
            --cell-bg: #f8fbff;
            --separator-bg: #c2d1df;
            --text-main: #243241;
            --border-color: #c2ccd7;
            --table-shadow: 0 14px 32px rgba(34, 52, 70, .16);
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
            margin: 0;
            padding: 0;
            background: var(--page-bg);
            color: var(--text-main);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            overflow-x: hidden;
        }

        .report-page {
            width: 100%;
            padding: 12px;
            box-sizing: border-box;
        }

        .portfolio-note {
            margin: 0 0 12px;
            padding: 12px 16px;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: var(--table-shadow);
            font-size: 14px;
            line-height: 1.45;
        }

        .portfolio-note strong {
            font-weight: 800;
        }

        .report-table-wrap {
            width: 100%;
            overflow: hidden;
            border-radius: 18px;
            background: var(--table-bg);
            box-shadow: var(--table-shadow);
        }

        .report-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--table-bg);
        }

        .report-table td {
            border-color: var(--border-color) !important;
            color: var(--text-main);
            font-size: 14px;
            line-height: 1.25;
            padding: 12px 10px;
            vertical-align: middle;
            word-break: break-word;
            overflow-wrap: anywhere;
            white-space: normal;
            box-sizing: border-box;
            font-weight: 650;
        }

        .header-light {
            background: var(--header-light-bg);
            font-weight: 800;
        }

        .group-header {
            background: var(--group-header-bg);
            font-weight: 800;
            letter-spacing: .02em;
        }

        .dept-header {
            background: var(--dept-header-bg);
            font-weight: 800;
        }

        .employee-header {
            background: var(--employee-header-bg);
            font-weight: 800;
        }

        .section-title {
            background: var(--section-bg);
            font-weight: 900;
            letter-spacing: .08em;
        }

        .section-row-week {
            background: var(--week-section-bg);
        }

        .metric-cell {
            background: var(--metric-bg);
            font-weight: 800;
        }

        .total-cell {
            background: var(--total-bg);
            font-weight: 900;
        }

        .value-cell {
            background: var(--cell-bg);
            font-weight: 700;
        }

        .separator-cell {
            background: var(--separator-bg);
            min-width: 34px;
            width: 34px;
            padding: 0 !important;
            border-left: 2px solid #aeb9c6 !important;
            border-right: 2px solid #aeb9c6 !important;
        }

        .col-metric {
            width: 290px;
            min-width: 290px;
            max-width: 290px;
        }

        .col-total {
            width: 110px;
            min-width: 110px;
            max-width: 110px;
        }

        .col-user {
            min-width: 140px;
        }

        .col-separator {
            width: 34px;
            min-width: 34px;
            max-width: 34px;
        }

        .report-table tr:first-child td:first-child {
            border-top-left-radius: 16px;
        }

        .report-table tr:first-child td:last-child {
            border-top-right-radius: 16px;
        }

        .report-table tr:last-child td:first-child {
            border-bottom-left-radius: 16px;
        }

        .report-table tr:last-child td:last-child {
            border-bottom-right-radius: 16px;
        }

        @media (max-width: 1600px) {
            .report-table td {
                font-size: 13px;
                padding: 10px 8px;
            }

            .col-metric {
                width: 250px;
                min-width: 250px;
                max-width: 250px;
            }

            .col-user {
                min-width: 125px;
            }
        }

        @media (max-width: 1200px) {
            .report-table td {
                font-size: 12px;
                padding: 9px 6px;
            }

            .col-metric {
                width: 220px;
                min-width: 220px;
                max-width: 220px;
            }

            .col-total {
                width: 90px;
                min-width: 90px;
                max-width: 90px;
            }

            .col-separator,
            .separator-cell {
                width: 28px;
                min-width: 28px;
                max-width: 28px;
            }
        }
    </style>

    <script>
        setTimeout(() => {
            console.log('Автообновление дашборда');
            location.reload();
        }, 5 * 60 * 1000);
    </script>
</head>
<body>
<div class="report-page">
    <div class="portfolio-note">
        <strong>Безопасная версия для портфолио.</strong>
        Источник данных: <?= h((string)$meta['source']) ?>.
        <?= h((string)$meta['note']) ?>
        Сформировано: <?= h((string)$meta['generatedAt']) ?>.
    </div>

    <div class="report-table-wrap">
        <table class="report-table">
            <tbody class="text-center align-middle">
                <tr>
                    <td rowspan="3" class="header-light col-metric border text-center">Показатель</td>
                    <td rowspan="3" class="header-light col-total border text-center">ИТОГО</td>

                    <td colspan="<?= count($incomingEmployees) ?>" class="group-header border text-center">
                        Входящие заявки
                    </td>

                    <td rowspan="3" class="separator-cell col-separator border"></td>

                    <td colspan="<?= count($coldEmployees) ?>" class="group-header border text-center">
                        Исходящий прозвон
                    </td>
                </tr>

                <tr>
                    <?php foreach ($incomingDepartments as $department): ?>
                        <td colspan="<?= count($department['employees']) ?>" class="dept-header border text-center">
                            <?= h((string)$department['department']) ?>
                        </td>
                    <?php endforeach; ?>

                    <?php foreach ($coldDepartments as $department): ?>
                        <td colspan="<?= count($department['employees']) ?>" class="dept-header border text-center">
                            <?= h((string)$department['department']) ?>
                        </td>
                    <?php endforeach; ?>
                </tr>

                <tr>
                    <?php foreach ($incomingEmployees as $employee): ?>
                        <td class="employee-header col-user border text-center">
                            <?= h((string)$employee['name']) ?>
                        </td>
                    <?php endforeach; ?>

                    <?php foreach ($coldEmployees as $employee): ?>
                        <td class="employee-header col-user border text-center">
                            <?= h((string)$employee['name']) ?>
                        </td>
                    <?php endforeach; ?>
                </tr>

                <?php renderSectionTitle('Контакты за день', $totalColumns); ?>
                <?php renderMetricRow($metrics['day_contacts_in'], $allEmployees); ?>
                <?php renderMetricRow($metrics['day_contacts_sent_to_sales'], $allEmployees); ?>

                <?php renderSectionTitle('Звонки за день', $totalColumns); ?>
                <?php renderMetricRow($metrics['day_calls_count'], $allEmployees); ?>
                <?php renderMetricRow($metrics['day_calls_duration'], $allEmployees); ?>

                <?php renderSectionTitle('Контакты за неделю', $totalColumns, 'section-row-week'); ?>
                <?php renderMetricRow($metrics['week_contacts_in'], $allEmployees); ?>
                <?php renderMetricRow($metrics['week_contacts_sent_to_sales'], $allEmployees); ?>

                <?php renderSectionTitle('Звонки за неделю', $totalColumns, 'section-row-week'); ?>
                <?php renderMetricRow($metrics['week_calls_count'], $allEmployees); ?>
                <?php renderMetricRow($metrics['week_calls_duration'], $allEmployees); ?>
                <?php renderMetricRow($metrics['week_calls_avg_per_day'], $allEmployees); ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
