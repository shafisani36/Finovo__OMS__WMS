<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case MANAGER = 'manager';
    case SALES = 'sales';
    case OPERATOR = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrator',
            self::MANAGER => 'Operations Manager',
            self::SALES => 'Sales Staff',
            self::OPERATOR => 'Warehouse Operator',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ADMIN => 'Everything, including stores, settings, employees and their permissions',
            self::MANAGER => 'Runs orders, inventory, fulfillment and purchasing; can see employees',
            self::SALES => 'Orders, customers, products and returns',
            self::OPERATOR => 'Warehouse work: stock, picking, shipments, returns and receiving',
        };
    }

    /**
     * What everyone with this role can do unless an admin allows or denies a single action for them.
     * Administrators always have every permission.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::ADMIN => Permission::cases(),
            self::MANAGER => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission) => ! $permission->isSensitive()
                    && ! in_array($permission, [Permission::STORES_CONNECT, Permission::STORES_DELETE, Permission::WAREHOUSES_DELETE, Permission::PRODUCTS_DELETE, Permission::ORDERS_DELETE], true),
            )),
            self::SALES => [
                Permission::STORES_VIEW,
                Permission::ORDERS_VIEW,
                Permission::ORDERS_ALLOCATE,
                Permission::ORDERS_CANCEL,
                Permission::ORDERS_MANAGE,
                Permission::CUSTOMERS_VIEW,
                Permission::PRODUCTS_VIEW,
                Permission::INVENTORY_VIEW,
                Permission::SHIPMENTS_VIEW,
                Permission::RETURNS_VIEW,
                Permission::RETURNS_MANAGE,
            ],
            self::OPERATOR => [
                Permission::STORES_VIEW,
                Permission::STORES_SYNC,
                Permission::WAREHOUSES_VIEW,
                Permission::WAREHOUSES_MANAGE,
                Permission::WAREHOUSE_EMPLOYEES_VIEW,
                Permission::ORDERS_VIEW,
                Permission::ORDERS_ALLOCATE,
                Permission::ORDERS_CANCEL,
                Permission::CUSTOMERS_VIEW,
                Permission::PRODUCTS_VIEW,
                Permission::INVENTORY_VIEW,
                Permission::INVENTORY_RECEIVE,
                Permission::INVENTORY_TRANSFER,
                Permission::INVENTORY_ADJUST,
                Permission::INVENTORY_APPROVE,
                Permission::PICKING_VIEW,
                Permission::PICKING_UPDATE,
                Permission::SHIPMENTS_VIEW,
                Permission::SHIPMENTS_DISPATCH,
                Permission::RETURNS_VIEW,
                Permission::RETURNS_MANAGE,
                Permission::PURCHASING_VIEW,
                Permission::PURCHASING_REQUEST,
                Permission::PURCHASING_APPROVE,
                Permission::PURCHASING_ORDER,
                Permission::PURCHASING_RECEIVE,
            ],
        };
    }
}
