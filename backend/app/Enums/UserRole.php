<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case OPERATOR = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrator',
            self::OPERATOR => 'Warehouse Operator',
        };
    }

    public function permissions(): array
    {
        return match ($this) {
            self::ADMIN => [
                'view_dashboard',
                'manage_orders',
                'manage_inventory',
                'manage_warehouses',
                'manage_stores',
                'manage_security',
                'view_audit_logs',
            ],
            self::OPERATOR => [
                'view_dashboard',
                'view_orders',
                'process_picking',
                'stock_movements',
                'view_shipments',
            ],
        };
    }
}
