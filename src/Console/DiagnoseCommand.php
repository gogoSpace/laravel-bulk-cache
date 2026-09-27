<?php

namespace GogoSpace\BulkCache\Console;

use GogoSpace\BulkCache\Support\Diagnostics;
use Illuminate\Console\Command;

final class DiagnoseCommand extends Command
{
    protected $signature = 'bulk-cache:diagnose {--json : Output a machine-readable report}';

    protected $description = 'Inspect bulk cache configuration without contacting a backend';

    public function handle(Diagnostics $diagnostics): int
    {
        $report = $diagnostics->inspect();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Configuration only; backend availability and loader construction were not checked.');
            foreach ($report['errors'] as $error) {
                $this->error($error);
            }
            foreach ($report['datasets'] as $dataset) {
                $this->line($dataset['dataset'].': '.$dataset['driver'].'; store='.($dataset['store'] ?? '-').'; connection='.($dataset['connection'] ?? '-').'; namespace='.($dataset['namespace_fingerprint'] ?? '-').'; guarded='.($dataset['guarded_publication'] ? 'yes' : 'no').'; loader='.$dataset['loader']);
                foreach ($dataset['errors'] as $error) {
                    $this->error($error);
                }
            }
        }

        return $report['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
