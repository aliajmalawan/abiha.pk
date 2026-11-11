<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Real configuration (not sample data): the permission catalogue and the four system roles. Safe to re-run. */
class RolesAndPermissionsSeeder extends Seeder
{
    /** slug => [name, group] */
    public const PERMISSIONS = [
        'dashboard.view' => ['View dashboard', 'General'],
        'products.manage' => ['Manage products & variants', 'Catalogue'],
        'categories.manage' => ['Manage categories & collections', 'Catalogue'],
        'inventory.manage' => ['Manage inventory', 'Catalogue'],
        'orders.view' => ['View orders', 'Sales'],
        'orders.manage' => ['Process orders (status, tracking)', 'Sales'],
        'payments.verify' => ['Verify payments', 'Sales'],
        'coupons.manage' => ['Manage coupons', 'Sales'],
        'shipping.manage' => ['Manage shipping methods', 'Sales'],
        'customers.manage' => ['Manage customers', 'Customers'],
        'reviews.manage' => ['Moderate reviews', 'Customers'],
        'messages.manage' => ['Contact messages & newsletter', 'Customers'],
        'content.manage' => ['Manage pages, banners & homepage', 'Content'],
        'settings.manage' => ['Manage site settings', 'System'],
        'reports.view' => ['View and export reports', 'Sales'],
        'staff.manage' => ['Manage staff roles', 'System'],
        'logs.view' => ['View activity log', 'System'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => [$name, $group]) {
            Permission::updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group]);
        }
        $all = array_keys(self::PERMISSIONS);

        $roles = [
            // The slugs are stable (code and existing users refer to them); the names are the six admin roles of the brief.
            Role::ADMIN => ['Super Admin', 'Full access, including staff roles and the activity log', true, $all],
            Role::MANAGER => ['Admin', 'Everything except managing staff roles', true, array_values(array_diff($all, ['staff.manage']))],
            Role::STAFF => ['Order Manager', 'Orders, payment verification, shipping details and customer messages', true,
                ['dashboard.view', 'orders.view', 'orders.manage', 'payments.verify', 'messages.manage']],
            Role::PRODUCT_MANAGER => ['Product Manager', 'Products, variants, categories, collections and reviews', true,
                ['dashboard.view', 'products.manage', 'categories.manage', 'reviews.manage']],
            Role::INVENTORY_MANAGER => ['Inventory Manager', 'Stock levels, adjustments and the stock history', true,
                ['dashboard.view', 'inventory.manage']],
            Role::CONTENT_MANAGER => ['Content Manager', 'Homepage, banners, pages, menus and newsletter', true,
                ['dashboard.view', 'content.manage', 'messages.manage']],
            Role::CUSTOMER => ['Customer', 'Storefront customer (no admin access)', false, []],
        ];

        foreach ($roles as $slug => [$name, $description, $isStaff, $permissions]) {
            $role = Role::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description, 'is_staff' => $isStaff, 'is_system' => true]);
            $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id'));
        }
    }
}
