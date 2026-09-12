<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PaperleafTech\LaravelMigration\Tests\Fixtures\ScriptedQueueCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class FailureReportingTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createJobsTable();
        $this->createFailedJobsTable();

        ScriptedQueueCommand::$queueCounts = [];
        ScriptedQueueCommand::$onPoll = null;
    }

    public function test_a_queue_that_drains_cleanly_reports_success(): void
    {
        ScriptedQueueCommand::$queueCounts = [0];

        $this->assertTrue($this->await(jobCount: 5));
        $this->assertStringNotContainsString('failed', $this->output->fetch());
    }

    public function test_jobs_that_fail_while_the_group_runs_are_reported_and_fail_the_run(): void
    {
        ScriptedQueueCommand::$queueCounts = [2, 0];
        ScriptedQueueCommand::$onPoll = function (int $remaining): void {
            if ($remaining === 0) {
                $this->recordFailedJob('migrations');
                $this->recordFailedJob('migrations');
            }
        };

        $this->assertFalse($this->await(jobCount: 2));

        $output = $this->output->fetch();
        $this->assertStringContainsString('2 job(s) failed', $output);
        $this->assertStringContainsString('queue:failed', $output);
    }

    public function test_failures_that_predate_the_run_are_not_reported(): void
    {
        $this->recordFailedJob('migrations');

        ScriptedQueueCommand::$queueCounts = [0];

        $this->assertTrue($this->await(jobCount: 1));
    }

    public function test_failures_on_other_queues_are_ignored(): void
    {
        ScriptedQueueCommand::$queueCounts = [1, 0];
        ScriptedQueueCommand::$onPoll = function (int $remaining): void {
            if ($remaining === 0) {
                $this->recordFailedJob('default');
            }
        };

        $this->assertTrue($this->await(jobCount: 1));
    }

    public function test_an_application_without_a_failed_jobs_table_still_completes(): void
    {
        Schema::connection('testing')->drop('failed_jobs');

        ScriptedQueueCommand::$queueCounts = [0];

        $this->assertTrue($this->await(jobCount: 1));
    }

    private function await(int $jobCount): bool
    {
        $command = new ScriptedQueueCommand;
        $command->setLaravel($this->app);

        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));

        return $command->awaitQueue($command->getOutput()->createProgressBar(1), $jobCount);
    }

    private function createFailedJobsTable(): void
    {
        Schema::connection('testing')->create('failed_jobs', function ($table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    private function recordFailedJob(string $queue): void
    {
        DB::connection('testing')->table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => '{}',
            'exception' => 'boom',
        ]);
    }
}
