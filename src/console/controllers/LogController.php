<?php

namespace justinholtweb\bee\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bee\Plugin;
use yii\console\ExitCode;

/**
 * `php craft bee/log/…`
 */
class LogController extends Controller
{
    public $defaultAction = 'tail';

    /** How many entries to show. */
    public int $limit = 25;

    /** Only failures. */
    public bool $failures = false;

    /** Days to keep, overriding the setting. */
    public int $days = 0;

    public function options($actionID): array
    {
        return match ($actionID) {
            'tail' => array_merge(parent::options($actionID), ['limit', 'failures']),
            'prune' => array_merge(parent::options($actionID), ['days']),
            default => parent::options($actionID),
        };
    }

    public function actionTail(): int
    {
        $query = Plugin::getInstance()->getLog()->query()->limit($this->limit);

        if ($this->failures) {
            $query->where(['success' => false]);
        }

        foreach (array_reverse($query->all()) as $entry) {
            $this->stdout(sprintf(
                "%s  %-6s %-3d %6.0fms  %s%s\n",
                $entry['dateCreated'],
                $entry['method'],
                $entry['status'],
                $entry['durationMs'],
                $entry['label'] ?: $entry['path'],
                $entry['error'] ? '  — ' . $entry['error'] : '',
            ), $entry['success'] ? Console::FG_GREY : Console::FG_RED);
        }

        return ExitCode::OK;
    }

    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->getLog()->prune($this->days > 0 ? $this->days : null);
        $deleted += Plugin::getInstance()->getRecommendations()->pruneLedger();

        $this->stdout("Pruned {$deleted} row(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionClear(): int
    {
        if ($this->interactive && !$this->confirm('Delete the entire connection log?')) {
            return ExitCode::OK;
        }

        $this->stdout(sprintf("Cleared %d entry(ies).\n", Plugin::getInstance()->getLog()->clear()), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
