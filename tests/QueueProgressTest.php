<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Console\OutputStyle;
use PaperleafTech\LaravelMigration\Tests\Fixtures\ScriptedQueueCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueProgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createJobsTable();

        ScriptedQueueCommand::$queueCounts = [];
        ScriptedQueueCommand::$onPoll = null;
    }

    public function test_progress_is_reported_as_completed_jobs(): void
    {
        ScriptedQueueCommand::$queueCounts = [5, 2, 0];

        $output = $this->await(jobCount: 10);

        $this->assertStringContainsString('8/10 jobs', $output);
        $this->assertStringContainsString('10/10 jobs', $output);
    }

    public function test_the_bar_reflects_the_depth_of_the_poll_just_taken(): void
    {
        ScriptedQueueCommand::$queueCounts = [5, 2, 0];

        $output = $this->await(jobCount: 10);

        $this->assertStringNotContainsString(
            '5/10 jobs',
            $output,
            'Redrawing before the poll would show the previous round\'s depth.'
        );
    }

    public function test_the_progress_bar_carries_no_process_memory_reading(): void
    {
        ScriptedQueueCommand::$queueCounts = [0];

        $this->assertStringNotContainsString(
            'MiB',
            $this->await(jobCount: 4),
            "The command's own memory says nothing about work happening on the workers."
        );
    }

    public function test_the_progress_bar_carries_no_elapsed_or_estimated_time(): void
    {
        ScriptedQueueCommand::$queueCounts = [0];

        $output = $this->await(jobCount: 4);

        $this->assertStringNotContainsString('elapsed', $output);
        $this->assertStringNotContainsString('eta', $output);
        $this->assertStringNotContainsString('< 1 ms', $output);
    }

    private function await(int $jobCount): string
    {
        $command = new ScriptedQueueCommand;
        $command->setLaravel($this->app);

        $buffer = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $command->awaitQueue($command->getOutput()->createProgressBar(1), $jobCount);

        return $buffer->fetch();
    }
}
