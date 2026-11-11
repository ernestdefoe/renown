<?php

namespace ErnestDefoe\Renown\Tests\integration\api;

use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every index and foreign key name is Laravel's own, so it carries the table
 * prefix (two forums can share a database), and fits MySQL's 64 characters
 * even with a 9-character prefix.
 */
class MigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-renown');
    }

    #[Test]
    public function index_and_key_names_are_prefixed_and_short_enough(): void
    {
        $this->app();
        $schema = $this->database()->getSchemaBuilder();
        $prefix = $this->database()->getTablePrefix();
        $checked = 0;

        foreach (['renown_xp_log', 'renown_prestiges', 'renown_grants', 'renown_boosts'] as $table) {
            $names = array_merge(
                array_column($schema->getIndexes($table), 'name'),
                array_column($schema->getForeignKeys($table), 'name'),
            );
            foreach (array_filter($names) as $name) {
                if (strtolower($name) === 'primary' || str_starts_with($name, 'sqlite_autoindex')) {
                    continue;
                }
                $this->assertStringStartsWith($prefix.$table.'_', $name, "$name is not Laravel's prefixed name");
                $this->assertLessThanOrEqual(64, strlen($name) - strlen($prefix) + 9, "$name is too long with a 9-character prefix");
                $checked++;
            }
        }

        // 6 indexes everywhere, plus the foreign keys where the driver names them (not SQLite).
        $this->assertGreaterThanOrEqual(6, $checked);
    }
}
