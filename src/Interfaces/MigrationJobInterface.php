<?php

namespace PaperleafTech\LaravelMigration\Interfaces;

interface MigrationJobInterface
{
    public function handleItem(object $item): void;
}
