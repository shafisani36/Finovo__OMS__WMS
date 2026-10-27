<?php

namespace App\Enums;

/**
 * Every action in Finovo that can be allowed or denied for an employee. Roles give a default set
 * (UserRole::defaultPermissions); an admin can allow or deny single actions for one employee on top of that.
 * Keys are stored in the database and sent to the browser, so they must never be renamed casually.
 */
enum Permission: string
{
    // Employees
    case EMPLOYEES_VIEW = 'employees.view';
    case EMPLOYEES_CREATE = 'employees.create';
    case EMPLOYEES_UPDATE = 'employees.update';
    case EMPLOYEES_ASSIGN_ROLE = 'employees.assign_role';
    case EMPLOYEES_SUSPEND = 'employees.suspend';
    case EMPLOYEES_RESET_PASSWORD = 'employees.reset_password';
    case EMPLOYEES_DELETE = 'employees.delete';
    case EMPLOYEES_MANAGE_PERMISSIONS = 'employees.manage_permissions';

    // Stores
    case STORES_VIEW = 'stores.view';
    case STORES_CONNECT = 'stores.connect';
    case STORES_SYNC = 'stores.sync';
    case STORES_DELETE = 'stores.delete';

    // Warehouses
    case WAREHOUSES_VIEW = 'warehouses.view';
    case WAREHOUSES_MANAGE = 'warehouses.manage';
    case WAREHOUSES_DELETE = 'warehouses.delete';
    case WAREHOUSE_EMPLOYEES_VIEW = 'warehouse.employees.view';
    case WAREHOUSE_EMPLOYEES_ASSIGN = 'warehouse.employees.assign';

    // Orders and customers
    case ORDERS_VIEW = 'orders.view';
    case ORDERS_ALLOCATE = 'orders.allocate';
    case ORDERS_CANCEL = 'orders.cancel';
    case ORDERS_MANAGE = 'orders.manage';
    case ORDERS_DELETE = 'orders.delete';
    case CUSTOMERS_VIEW = 'customers.view';

    // Catalog and inventory
    case PRODUCTS_VIEW = 'products.view';
    case PRODUCTS_MANAGE = 'products.manage';
    case PRODUCTS_DELETE = 'products.delete';
    case INVENTORY_VIEW = 'inventory.view';
    case INVENTORY_RECEIVE = 'inventory.receive';
    case INVENTORY_TRANSFER = 'inventory.transfer';
    case INVENTORY_ADJUST = 'inventory.adjust';
    case INVENTORY_APPROVE = 'inventory.approve';

    // Fulfillment
    case PICKING_VIEW = 'picking.view';
    case PICKING_UPDATE = 'picking.update';
    case SHIPMENTS_VIEW = 'shipments.view';
    case SHIPMENTS_DISPATCH = 'shipments.dispatch';
    case RETURNS_VIEW = 'returns.view';
    case RETURNS_MANAGE = 'returns.manage';

    // Purchasing
    case PURCHASING_VIEW = 'purchasing.view';
    case PURCHASING_REQUEST = 'purchasing.request';
    case PURCHASING_APPROVE = 'purchasing.approve';
    case PURCHASING_ORDER = 'purchasing.order';
    case PURCHASING_RECEIVE = 'purchasing.receive';

    // 3PL billing
    case BILLING_VIEW = 'billing.view';
    case BILLING_MANAGE = 'billing.manage';

    // Settings
    case SETTINGS_BARCODE = 'settings.barcode';

    /** Section of the permission screen the action is listed under. */
    public function module(): string
    {
        return match (explode('.', $this->value)[0]) {
            'employees' => 'Employees',
            'stores' => 'Stores',
            'warehouses', 'warehouse' => 'Warehouses',
            'orders', 'customers' => 'Orders & Customers',
            'products', 'inventory' => 'Products & Inventory',
            'picking', 'shipments', 'returns' => 'Fulfillment',
            'purchasing' => 'Purchasing',
            'billing' => 'Billing',
            'settings' => 'Settings',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::EMPLOYEES_VIEW => 'View employees',
            self::EMPLOYEES_CREATE => 'Add employees',
            self::EMPLOYEES_UPDATE => 'Edit employee details',
            self::EMPLOYEES_ASSIGN_ROLE => 'Change employee roles',
            self::EMPLOYEES_SUSPEND => 'Suspend and reactivate employees',
            self::EMPLOYEES_RESET_PASSWORD => 'Reset employee passwords',
            self::EMPLOYEES_DELETE => 'Remove employees',
            self::EMPLOYEES_MANAGE_PERMISSIONS => 'Manage employee permissions',
            self::STORES_VIEW => 'View stores',
            self::STORES_CONNECT => 'Connect and authorize stores',
            self::STORES_SYNC => 'Sync store products and orders',
            self::STORES_DELETE => 'Delete stores',
            self::WAREHOUSES_VIEW => 'View warehouses',
            self::WAREHOUSES_MANAGE => 'Set up warehouses (details, locations, opening stock)',
            self::WAREHOUSES_DELETE => 'Delete warehouses',
            self::WAREHOUSE_EMPLOYEES_VIEW => 'View warehouse staff',
            self::WAREHOUSE_EMPLOYEES_ASSIGN => 'Assign and remove warehouse staff',
            self::ORDERS_VIEW => 'View orders and invoices',
            self::ORDERS_ALLOCATE => 'Send orders to a warehouse (allocate stock)',
            self::ORDERS_CANCEL => 'Cancel orders',
            self::ORDERS_MANAGE => 'Create and edit orders',
            self::ORDERS_DELETE => 'Delete orders created by mistake',
            self::CUSTOMERS_VIEW => 'View customers',
            self::PRODUCTS_VIEW => 'View products',
            self::PRODUCTS_MANAGE => 'Add, edit and archive products',
            self::PRODUCTS_DELETE => 'Delete products',
            self::INVENTORY_VIEW => 'View inventory and stock movements',
            self::INVENTORY_RECEIVE => 'Receive stock',
            self::INVENTORY_TRANSFER => 'Transfer stock between warehouses',
            self::INVENTORY_ADJUST => 'Adjust stock and run cycle counts',
            self::INVENTORY_APPROVE => 'Approve adjustments and counts',
            self::PICKING_VIEW => 'View picking list',
            self::PICKING_UPDATE => 'Pick and pack orders',
            self::SHIPMENTS_VIEW => 'View shipments',
            self::SHIPMENTS_DISPATCH => 'Dispatch and deliver shipments',
            self::RETURNS_VIEW => 'View returns',
            self::RETURNS_MANAGE => 'Create, receive and inspect returns',
            self::PURCHASING_VIEW => 'View purchase documents',
            self::PURCHASING_REQUEST => 'Create purchase requisitions',
            self::PURCHASING_APPROVE => 'Approve or reject requisitions',
            self::PURCHASING_ORDER => 'Create, issue and cancel purchase orders',
            self::PURCHASING_RECEIVE => 'Receive goods (GRN)',
            self::BILLING_VIEW => 'View clients and their invoices',
            self::BILLING_MANAGE => 'Manage clients, rate cards and invoices',
            self::SETTINGS_BARCODE => 'Change barcode label settings',
        };
    }

    /**
     * Actions about people and access. Only an administrator can grant these to someone else,
     * so nobody can hand out more power than an admin intended.
     */
    public function isSensitive(): bool
    {
        return str_starts_with($this->value, 'employees.') && $this !== self::EMPLOYEES_VIEW;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::cases(), 'value');
    }
}
