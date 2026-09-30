<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestReports extends Command
{
    protected $signature = 'test:reports {branch_id=1}';
    protected $description = 'Test Report Controller';

    public function handle()
    {
        try { 
            $c = app(\App\Http\Controllers\v1\ReportController::class); 
            $req = \Illuminate\Http\Request::create('/api/v1/reports', 'GET', [
                'company_id' => '1',
                'branch_id' => $this->argument('branch_id'),
                'start_date' => '2026-09-02',
                'end_date' => '2026-09-24',
                'days' => '7'
            ]); 
            
            $start = microtime(true);
            $r = $c->index($req); 
            $end = microtime(true);
            
            $this->info('SUCCESS: ' . $r->getStatusCode() . ' in ' . round($end - $start, 2) . 's'); 
        } catch(\Exception $e) { 
            $this->error('ERROR: ' . $e->getMessage()); 
            $this->error($e->getTraceAsString()); 
        }
    }
}
