<?php

namespace PaperleafTech\LaravelMigration\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Identifier quoting for the source connection's chunk queries.
 *
 * The chunk SQL is assembled from raw fragments (qualified key columns,
 * aliased select columns) because it is shipped to the job as a string. Every
 * identifier in it is quoted by the source connection's own query grammar, so
 * MySQL gets backticks, PostgreSQL and SQLite get double quotes and SQL Server
 * gets brackets.
 *
 * How a dot in a configured table name is read depends on the source driver:
 *
 * - MySQL and MariaDB keep the whole name as one identifier, so a legacy
 *   table literally named `LEGACY.USERS` keeps working as it always has.
 * - Every other driver reads the dot as a schema separator, so on PostgreSQL
 *   `legacy.user_account` is table `user_account` in schema `legacy`.
 */
class SourceIdentifiers
{
    /**
     * Drivers on which a dot stays part of a single table identifier.
     */
    protected const LITERAL_DOT_DRIVERS = ['mysql', 'mariadb'];

    /**
     * Drivers that fold unquoted identifiers to lower case, so a legacy
     * UPPER_CASE schema created without quotes reads back lower case.
     */
    protected const LOWER_CASE_FOLDING_DRIVERS = ['pgsql'];

    protected const ALIAS_CASES = ['upper', 'lower', 'preserve'];

    public function __construct(protected Connection $connection) {}

    /**
     * @param  string|null  $connection  The source connection, or null for the configured one.
     */
    public static function for(?string $connection = null): static
    {
        return new static(DB::connection($connection ?? config('laravel-migration.database_connection')));
    }

    /**
     * Whether a dot in a table name separates schema and table.
     */
    public function splitsQualifiedNames(): bool
    {
        return ! in_array($this->connection->getDriverName(), self::LITERAL_DOT_DRIVERS, true);
    }

    /**
     * Quote a table name, applying the connection's table prefix.
     */
    public function table(string $table): string
    {
        if ($this->splitsQualifiedNames()) {
            return $this->connection->getQueryGrammar()->wrapTable($table);
        }

        return $this->wrapValue($this->connection->getTablePrefix().$table);
    }

    /**
     * Quote a column qualified by its table.
     */
    public function column(string $table, string $column): string
    {
        return $this->table($table).'.'.$this->wrapValue($column);
    }

    /**
     * Quote a column alias. Quoted so that PostgreSQL does not fold it to
     * lower case; the alias reaches the job exactly as it is built here.
     */
    public function alias(string $alias): string
    {
        return $this->wrapValue($alias);
    }

    /**
     * The table name without its schema, which is what alias prefixes are
     * built from. Unchanged on drivers where a dot is part of the name.
     */
    public function baseName(string $table): string
    {
        if (! $this->splitsQualifiedNames() || ! str_contains($table, '.')) {
            return $table;
        }

        return substr($table, strrpos($table, '.') + 1);
    }

    /**
     * The case column aliases are built in: the configured alias_case, or by
     * default upper case on drivers that fold unquoted identifiers to lower
     * case and the catalog's own case everywhere else.
     */
    public function aliasCase(): string
    {
        $case = config('laravel-migration.alias_case');

        if ($case === null || $case === '') {
            return in_array($this->connection->getDriverName(), self::LOWER_CASE_FOLDING_DRIVERS, true)
                ? 'upper'
                : 'preserve';
        }

        $case = strtolower((string) $case);

        if (! in_array($case, self::ALIAS_CASES, true)) {
            throw new InvalidArgumentException(sprintf(
                'laravel-migration.alias_case must be one of %s, or null for the driver default; "%s" given.',
                implode(', ', self::ALIAS_CASES),
                $case
            ));
        }

        return $case;
    }

    /**
     * Apply the alias case to an unquoted alias.
     */
    public function caseAlias(string $alias): string
    {
        return match ($this->aliasCase()) {
            'upper' => strtoupper($alias),
            'lower' => strtolower($alias),
            default => $alias,
        };
    }

    /**
     * Quote one identifier segment with the grammar's own quote characters,
     * without the grammar splitting it on dots. The grammar only exposes that
     * as a protected method, so it is called from within the grammar's scope.
     */
    protected function wrapValue(string $value): string
    {
        $wrap = fn (string $value): string => $this->wrapValue($value);

        return $wrap->call($this->connection->getQueryGrammar(), $value);
    }
}
