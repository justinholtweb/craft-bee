<?php

namespace justinholtweb\bee\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\models\Settings;
use justinholtweb\bee\Plugin;
use justinholtweb\bee\records\LogRecord;
use yii\base\Component;

/**
 * The connection log.
 *
 * Recombee is a black box from inside Craft: when recommendations go empty, the only questions that
 * matter are "did we ask?", "what did we ask?" and "what came back?". This answers all three.
 *
 * Payloads are truncated, never the status. A log that drops the interesting half of a 400 is worse
 * than no log, so the response body is kept preferentially over the request body.
 */
class Log extends Component
{
    private const MAX_PAYLOAD = 16000;

    /** Set while pruning, so a prune that itself logs cannot recurse. */
    private bool $suspended = false;

    public function record(
        string $method,
        string $path,
        int $status,
        float $ms,
        mixed $request = null,
        mixed $response = null,
        ?string $label = null,
        ?string $error = null,
    ): void {
        $mode = Plugin::getInstance()->getSettings()->logMode;
        $success = $status >= 200 && $status < 300;

        if ($this->suspended || $mode === Settings::LOG_NONE) {
            return;
        }

        if ($mode === Settings::LOG_ERRORS && $success) {
            return;
        }

        // The log is a diagnostic, never a dependency. A logging failure must not be able to take
        // down a checkout or an element save.
        try {
            $record = new LogRecord();
            $record->method = $method;
            $record->path = mb_substr($path, 0, 255);
            $record->label = $label !== null ? mb_substr($label, 0, 255) : null;
            $record->status = $status;
            $record->durationMs = round($ms, 2);
            $record->success = $success;
            $record->request = $this->encode($request);
            $record->response = $this->encode($response);
            $record->error = $error !== null ? mb_substr($error, 0, 1000) : null;
            $record->save(false);
        } catch (\Throwable $e) {
            Craft::warning('Bee could not write to its log: ' . $e->getMessage(), 'bee');
        }
    }

    public function query(): Query
    {
        return (new Query())
            ->from(Table::LOG)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function get(int $id): ?array
    {
        return (new Query())->from(Table::LOG)->where(['id' => $id])->one() ?: null;
    }

    /**
     * Recent failures, for the settings screen and diagnostics.
     */
    public function recentFailures(int $limit = 5): array
    {
        return $this->query()->where(['success' => false])->limit($limit)->all();
    }

    public function stats(int $hours = 24): array
    {
        $since = Db::prepareDateForDb(new DateTime("-{$hours} hours"));

        $row = (new Query())
            ->from(Table::LOG)
            ->where(['>=', 'dateCreated', $since])
            ->select([
                'total' => 'COUNT(*)',
                'failures' => 'SUM(CASE WHEN [[success]] = 0 THEN 1 ELSE 0 END)',
                'avgMs' => 'AVG([[durationMs]])',
            ])
            ->one();

        return [
            // COUNT() and SUM() come back as strings from PDO; the CP templates compare these
            // numerically and a string "0" is not falsy in Twig the way an int 0 is.
            'total' => (int)($row['total'] ?? 0),
            'failures' => (int)($row['failures'] ?? 0),
            'avgMs' => round((float)($row['avgMs'] ?? 0)),
        ];
    }

    public function clear(): int
    {
        return Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    /**
     * Drop rows older than the retention setting. Called from `bee/log/prune` and by garbage
     * collection.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $this->suspended = true;

        try {
            return Craft::$app->getDb()->createCommand()
                ->delete(Table::LOG, ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-{$days} days"))])
                ->execute();
        } finally {
            $this->suspended = false;
        }
    }

    private function encode(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $string = is_string($payload) ? $payload : Json::encode($payload);

        if (mb_strlen($string) > self::MAX_PAYLOAD) {
            $string = mb_substr($string, 0, self::MAX_PAYLOAD) . "\n… truncated";
        }

        return $string;
    }
}
