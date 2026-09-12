<?php

namespace PaperleafTech\LaravelMigration\Tests;

use PaperleafTech\LaravelMigration\Support\BulkWriter;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The agent skill is discovered by path, by Laravel Boost, in consuming
 * applications. Moving or renaming it does not fail anywhere — the skill just
 * stops being offered — so its location and shape are pinned here.
 */
class AgentSkillTest extends BaseTestCase
{
    private const SKILL = __DIR__.'/../resources/boost/skills/writing-migration-jobs/SKILL.md';

    public function test_the_skill_sits_where_boost_scans_for_it(): void
    {
        $this->assertFileExists(
            self::SKILL,
            'Laravel Boost discovers <package>/resources/boost/skills/<name>/SKILL.md in direct dependencies.'
        );
    }

    public function test_the_skill_declares_a_name_matching_its_directory(): void
    {
        $this->assertSame('writing-migration-jobs', $this->frontmatter()['name']);
        $this->assertSame(
            basename(dirname(self::SKILL)),
            $this->frontmatter()['name'],
            'Boost keys a skill by its directory; a mismatch installs it under the wrong name.'
        );
    }

    public function test_the_skill_describes_when_to_use_it(): void
    {
        $description = $this->frontmatter()['description'] ?? '';

        $this->assertNotSame('', $description, 'Without a description an agent cannot tell when the skill applies.');
        $this->assertStringContainsString('Use when', $description);
    }

    /**
     * The skill documents the writer's API in a table. If a method is renamed
     * without the skill being updated, every consuming project is handed
     * instructions that do not work.
     */
    public function test_every_writer_method_the_skill_documents_exists(): void
    {
        preg_match_all('/^\| `(\w+)\(/m', file_get_contents(self::SKILL), $matches);

        $documented = array_unique($matches[1]);

        $this->assertNotEmpty($documented, 'The writer API table should not be empty.');

        foreach ($documented as $method) {
            $this->assertTrue(
                method_exists(BulkWriter::class, $method),
                "The skill documents BulkWriter::{$method}(), which does not exist."
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function frontmatter(): array
    {
        preg_match('/^---\R(.*?)\R---\R/s', file_get_contents(self::SKILL), $matches);

        $this->assertNotEmpty($matches, 'The skill must open with a YAML frontmatter block.');

        $parsed = [];
        $key = null;

        foreach (explode("\n", $matches[1]) as $line) {
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $pair)) {
                $key = $pair[1];
                $parsed[$key] = trim($pair[2]);

                continue;
            }

            if ($key !== null) {
                $parsed[$key] = trim($parsed[$key].' '.trim($line));
            }
        }

        return $parsed;
    }
}
