<?php

namespace justinholtweb\bee\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bee\services\Diagnostics;
use justinholtweb\bee\Plugin;
use yii\console\ExitCode;

/**
 * `php craft bee/diagnostics/check` — the same preflight the CP screen runs, for CI and deploys.
 */
class DiagnosticsController extends Controller
{
    public $defaultAction = 'check';

    /** Skip anything that makes a request to Recombee. */
    public bool $offline = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'check' => array_merge(parent::options($actionID), ['offline']),
            default => parent::options($actionID),
        };
    }

    public function actionCheck(): int
    {
        $checks = Plugin::getInstance()->getDiagnostics()->run(!$this->offline);

        foreach ($checks as $check) {
            [$mark, $colour] = match ($check['status']) {
                Diagnostics::OK => ['✓', Console::FG_GREEN],
                Diagnostics::WARNING => ['!', Console::FG_YELLOW],
                Diagnostics::ERROR => ['✗', Console::FG_RED],
                default => ['·', Console::FG_GREY],
            };

            $this->stdout(sprintf("%s %-22s ", $mark, $check['label']), $colour);
            $this->stdout($check['message'] . "\n");

            if ($check['fix'] !== null && $check['status'] !== Diagnostics::OK) {
                $this->stdout('    → ' . $check['fix'] . "\n", Console::FG_GREY);
            }
        }

        $worst = Plugin::getInstance()->getDiagnostics()->worstStatus($checks);

        // A non-zero exit on error means this can gate a deploy.
        return $worst === Diagnostics::ERROR ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
