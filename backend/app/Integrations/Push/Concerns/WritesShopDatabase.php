<?php

namespace App\Integrations\Push\Concerns;

use Illuminate\Database\Connection;

/**
 * Writing to a shop's own database (OpenCart and osCommerce stores connected with database details), where
 * order statuses are names the merchant chose and tables have many required columns.
 */
trait WritesShopDatabase
{
    /** Words that identify the shop's status for each role, most specific first. */
    private const STATUS_WORDS = [
        'unpaid' => ['pending'],
        'paid' => ['processing', 'paid'],
        'processing' => ['processing', 'preparing', 'packed'],
        'shipped' => ['shipped', 'dispatched', 'delivered'],
        'delivered' => ['delivered', 'complete'],
        'cancelled' => ['cancel'],
    ];

    /**
     * The shop's status for a role (see STATUS_WORDS), recognised by its name.
     *
     * @param  array<int, string>  $statuses  status name by ID
     */
    protected function statusFor(array $statuses, string $role): ?int
    {
        foreach (self::STATUS_WORDS[$role] as $word) {
            foreach ($statuses as $id => $name) {
                if (str_contains(strtolower($name), $word)) {
                    return (int) $id;
                }
            }
        }

        return null;
    }

    /**
     * Insert a row into whichever version of the shop's table exists: values for columns the table does not have
     * are left out, and required columns without a default get an empty value of their type. Returns the new ID.
     *
     * @param  array<string, mixed>  $values
     */
    protected function insertRow(Connection $db, string $table, array $values): int
    {
        $columns = $db->getSchemaBuilder()->getColumns($table);
        $values = array_intersect_key($values, array_flip(array_column($columns, 'name')));
        $blanks = [];

        foreach ($columns as $column) {
            if (array_key_exists($column['name'], $values) || $column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
                continue;
            }

            $type = strtolower((string) $column['type_name']);
            $blanks[$column['name']] = match (true) {
                (bool) preg_match('/int|dec|float|double|numeric|real|bit|bool/', $type) => 0,
                (bool) preg_match('/date|time/', $type) => now()->format('Y-m-d H:i:s'),
                default => '',
            };
        }

        return (int) $db->table($table)->insertGetId([...$blanks, ...$values]);
    }
}
