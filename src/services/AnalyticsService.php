<?php

declare(strict_types=1);

namespace johnfmorton\llmready\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\web\Request;
use johnfmorton\llmready\LlmReady;
use johnfmorton\llmready\records\AnalyticsRecord;
use yii\base\Component;

/**
 * Handles analytics logging and dashboard queries
 */
class AnalyticsService extends Component
{
    /**
     * Log a request to the analytics table
     */
    public function logRequest(int $siteId, ?int $entryId, string $requestType, string $botName, string $requestPath): void
    {
        $record = new AnalyticsRecord();
        $record->siteId = $siteId;
        $record->entryId = $entryId;
        $record->requestType = $requestType;
        $record->botName = $botName;
        // Normalize path: strip .md suffix so entries accessed via .md URLs
        // and content negotiation are grouped together
        if (str_ends_with($requestPath, '.md') && $requestPath !== 'llms.txt') {
            $requestPath = substr($requestPath, 0, -3);
        }
        $record->requestPath = $requestPath;
        $record->dateCreated = Db::prepareDateForDb(new \DateTime());
        $record->save(false);
    }

    /**
     * Identify the bot name from the request's user-agent
     */
    public function identifyBot(Request $request): string
    {
        $userAgent = $request->getUserAgent() ?? '';
        if ($userAgent === '') {
            return 'direct';
        }

        $settings = LlmReady::getInstance()->getSettings();
        $botAgents = array_merge(
            DetectionService::BOT_USER_AGENTS,
            $settings->additionalBotUserAgents,
        );

        foreach ($botAgents as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                return $bot;
            }
        }

        return 'direct';
    }

    /**
     * Apply optional bot/type filter conditions to a query
     */
    private function applyFilters(Query $query, ?string $botName, ?string $requestType): Query
    {
        if ($botName !== null) {
            $query->andWhere(['botName' => $botName]);
        }
        if ($requestType !== null) {
            $query->andWhere(['requestType' => $requestType]);
        }
        return $query;
    }

    /**
     * Get request counts over time grouped by granularity
     *
     * @return array<int, array{date: string, count: int}>
     */
    public function getRequestsOverTime(int $siteId, string $startDate, string $endDate, string $granularity = 'day', ?string $botName = null, ?string $requestType = null): array
    {
        $dateExpr = match ($granularity) {
            'week' => 'DATE(DATE_SUB([[dateCreated]], INTERVAL WEEKDAY([[dateCreated]]) DAY))',
            'month' => 'DATE_FORMAT([[dateCreated]], \'%Y-%m-01\')',
            default => 'DATE([[dateCreated]])',
        };

        $query = (new Query())
            ->select(["date" => $dateExpr, 'count' => 'COUNT(*)'])
            ->from(AnalyticsRecord::tableName())
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'dateCreated', $startDate])
            ->andWhere(['<=', 'dateCreated', $endDate]);

        $this->applyFilters($query, $botName, $requestType);

        return $query->groupBy([$dateExpr])
            ->orderBy(['date' => SORT_ASC])
            ->all();
    }

    /**
     * Get request counts over time grouped by bot name
     *
     * @return array<string, array<int, array{date: string, count: int}>>
     */
    public function getRequestsOverTimeByBot(int $siteId, string $startDate, string $endDate, string $granularity = 'day', ?string $botName = null, ?string $requestType = null): array
    {
        $dateExpr = match ($granularity) {
            'week' => 'DATE(DATE_SUB([[dateCreated]], INTERVAL WEEKDAY([[dateCreated]]) DAY))',
            'month' => 'DATE_FORMAT([[dateCreated]], \'%Y-%m-01\')',
            default => 'DATE([[dateCreated]])',
        };

        $query = (new Query())
            ->select(["date" => $dateExpr, 'botName', 'count' => 'COUNT(*)'])
            ->from(AnalyticsRecord::tableName())
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'dateCreated', $startDate])
            ->andWhere(['<=', 'dateCreated', $endDate]);

        $this->applyFilters($query, $botName, $requestType);

        $rows = $query->groupBy([$dateExpr, 'botName'])
            ->orderBy(['date' => SORT_ASC])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['botName']][] = [
                'date' => $row['date'],
                'count' => (int) $row['count'],
            ];
        }

        return $result;
    }

    /**
     * Get request counts over time grouped by request type
     *
     * @return array<string, array<int, array{date: string, count: int}>>
     */
    public function getRequestsOverTimeByType(int $siteId, string $startDate, string $endDate, string $granularity = 'day', ?string $botName = null, ?string $requestType = null): array
    {
        $dateExpr = match ($granularity) {
            'week' => 'DATE(DATE_SUB([[dateCreated]], INTERVAL WEEKDAY([[dateCreated]]) DAY))',
            'month' => 'DATE_FORMAT([[dateCreated]], \'%Y-%m-01\')',
            default => 'DATE([[dateCreated]])',
        };

        $query = (new Query())
            ->select(["date" => $dateExpr, 'requestType', 'count' => 'COUNT(*)'])
            ->from(AnalyticsRecord::tableName())
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'dateCreated', $startDate])
            ->andWhere(['<=', 'dateCreated', $endDate]);

        $this->applyFilters($query, $botName, $requestType);

        $rows = $query->groupBy([$dateExpr, 'requestType'])
            ->orderBy(['date' => SORT_ASC])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['requestType']][] = [
                'date' => $row['date'],
                'count' => (int) $row['count'],
            ];
        }

        return $result;
    }

    /**
     * Get bot breakdown with request count and last seen date
     *
     * @return array<int, array{botName: string, count: int, lastSeen: string}>
     */
    public function getBotBreakdown(int $siteId, string $startDate, string $endDate, ?string $botName = null, ?string $requestType = null): array
    {
        $query = (new Query())
            ->select([
                'botName',
                'count' => 'COUNT(*)',
                'lastSeen' => 'MAX([[dateCreated]])',
            ])
            ->from(AnalyticsRecord::tableName())
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'dateCreated', $startDate])
            ->andWhere(['<=', 'dateCreated', $endDate]);

        $this->applyFilters($query, $botName, $requestType);

        $rows = $query->groupBy(['botName'])
            ->orderBy(['count' => SORT_DESC])
            ->all();

        // Convert lastSeen from UTC (database) to the Craft system timezone
        $timezone = new \DateTimeZone(\Craft::$app->getTimeZone());
        foreach ($rows as &$row) {
            if (!empty($row['lastSeen'])) {
                $dt = new \DateTime($row['lastSeen'], new \DateTimeZone('UTC'));
                $dt->setTimezone($timezone);
                $row['lastSeen'] = $dt->format('c');
            }
        }

        return $rows;
    }

    /**
     * Get request type breakdown
     *
     * @return array<int, array{requestType: string, count: int}>
     */
    public function getRequestTypeBreakdown(int $siteId, string $startDate, string $endDate, ?string $botName = null, ?string $requestType = null): array
    {
        $query = (new Query())
            ->select([
                'requestType',
                'count' => 'COUNT(*)',
            ])
            ->from(AnalyticsRecord::tableName())
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'dateCreated', $startDate])
            ->andWhere(['<=', 'dateCreated', $endDate]);

        $this->applyFilters($query, $botName, $requestType);

        return $query->groupBy(['requestType'])
            ->orderBy(['count' => SORT_DESC])
            ->all();
    }

    /**
     * Get most accessed pages grouped by request path
     *
     * @return array<int, array{requestPath: string, requestType: string, count: int, cpEditUrl: string|null}>
     */
    public function getMostAccessedPages(int $siteId, string $startDate, string $endDate, int $limit = 20, ?string $botName = null, ?string $requestType = null): array
    {
        $query = (new Query())
            ->select([
                'requestPath',
                'requestType',
                'entryId' => 'MAX([[entryId]])',
                'count' => 'COUNT(*)',
            ])
            ->from(AnalyticsRecord::tableName())
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'dateCreated', $startDate])
            ->andWhere(['<=', 'dateCreated', $endDate]);

        $this->applyFilters($query, $botName, $requestType);

        $rows = $query->groupBy(['requestPath', 'requestType'])
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit)
            ->all();

        // Resolve CP edit URLs for entry-type rows
        $entryIds = array_filter(array_column($rows, 'entryId'));
        $entries = [];
        if (!empty($entryIds)) {
            $entries = Entry::find()->id($entryIds)->siteId($siteId)->status(null)->indexBy('id')->all();
        }

        $currentUser = Craft::$app->getUser()->getIdentity();

        foreach ($rows as &$row) {
            $entryId = $row['entryId'] ? (int) $row['entryId'] : null;
            $entry = $entryId ? ($entries[$entryId] ?? null) : null;
            $row['cpEditUrl'] = ($entry && $currentUser && $entry->canView($currentUser))
                ? $entry->getCpEditUrl()
                : null;
        }

        return $rows;
    }

    /**
     * Get total request count for a date range
     */
    public function getTotalRequests(int $siteId, string $startDate, string $endDate, ?string $botName = null, ?string $requestType = null): int
    {
        $query = (new Query())
            ->from(AnalyticsRecord::tableName())
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'dateCreated', $startDate])
            ->andWhere(['<=', 'dateCreated', $endDate]);

        $this->applyFilters($query, $botName, $requestType);

        return (int) $query->count();
    }

    /**
     * Delete analytics rows older than the retention period
     */
    public function purgeOldData(int $retentionDays): int
    {
        $cutoff = (new \DateTime())->modify("-{$retentionDays} days")->format('Y-m-d H:i:s');

        return Craft::$app->getDb()->createCommand()
            ->delete(AnalyticsRecord::tableName(), ['<', 'dateCreated', $cutoff])
            ->execute();
    }
}
