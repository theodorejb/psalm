<?php

declare(strict_types=1);

namespace Psalm\Report;

use Override;
use Psalm\Config;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Report;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

use function implode;
use function str_split;
use function strlen;
use function strtoupper;

final class CompactReport extends Report
{
    /**
     * @psalm-suppress PossiblyNullReference
     */
    #[Override]
    public function create(): string
    {
        /** @var BufferedOutput|null $buffer */
        $buffer = null;

        /** @var Table|null $table */
        $table = null;

        /** @var string|null $current_file */
        $current_file = null;

        $output = [];
        foreach ($this->issues_data as $issue_data) {
            if (!$this->show_info && $issue_data->severity === IssueData::SEVERITY_INFO) {
                continue;
            }

            if ($current_file === null || $current_file !== $issue_data->file_name) {
                // If we're processing a new file, then wrap up the last table and render it out.
                if ($buffer !== null) {
                    $table->render();
                    $output[] = $buffer->fetch();
                }

                $output[] = 'FILE: ' . $issue_data->file_name . "\n";

                $buffer = new BufferedOutput();
                $table = new Table($buffer);
                $table->setHeaders(['SEVERITY', 'LINE', 'ISSUE', 'DESCRIPTION']);
            }

            $is_error = $issue_data->severity === Config::REPORT_ERROR;
            if ($is_error) {
                $severity = ($this->use_color ? "\e[0;31mERROR\e[0m" : 'ERROR');
            } else {
                $severity = strtoupper($issue_data->severity);
            }

            // Since `Table::setColumnMaxWidth` is only available in symfony/console 4.2+ we need do something similar
            // so we have clean tables.
            $message = $issue_data->message;
            if (strlen($message) > 70) {
                $message = implode("\n", str_split($message, 70));
            }

            $table->addRow([
                $severity,
                $issue_data->line_from,
                $issue_data->type,
                $message,
            ]);

            $current_file = $issue_data->file_name;
        }

        if ($buffer !== null) {
            $table->render();
            $output[] = $buffer->fetch();
        }

        return implode("\n", $output);
    }
}
