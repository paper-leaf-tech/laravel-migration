<?php

namespace PaperleafTech\LaravelMigration\Interfaces;

interface MigrationJobInterface
{
    public function getItemKey(object $item): string;
    public function handleItem(object $item): void;
}
