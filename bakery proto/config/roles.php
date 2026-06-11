<?php
// config/roles.php - Pure data repository for roles and departments
// Version: 4.0 (PHP 8.3+ with enhanced structure and validation)

declare(strict_types=1);

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * Configuration Data Structure
 * 
 * This file serves as the single source of truth for:
 * - Department definitions
 * - Role definitions with privilege levels
 * - Department-to-role mappings
 * - Permission flags
 * 
 * All values can be overridden via the system_config table.
 * 
 * @version 4.0
 */

return [
    // =====================================================
    // DEPARTMENT DEFINITIONS
    // =====================================================
    // Colors and icons can be overridden via system_config table
    'departments' => [
        'HR' => [
            'name' => 'Human Resources',
            'code' => 'HR',
            'description' => 'Manages personnel, recruitment, and employee relations',
            'color' => '#4CAF50',
            'icon' => 'users',
            'order' => 10,
        ],
        'IT' => [
            'name' => 'Information Technology',
            'code' => 'IT',
            'description' => 'Handles systems, networks, and technical support',
            'color' => '#2196F3',
            'icon' => 'laptop',
            'order' => 20,
        ],
        'FN' => [
            'name' => 'Finance',
            'code' => 'FN',
            'description' => 'Manages budgets, payments, and financial reporting',
            'color' => '#FF9800',
            'icon' => 'money-bill',
            'order' => 30,
        ],
        'PD' => [
            'name' => 'Production',
            'code' => 'PD',
            'description' => 'Overseas baking and product manufacturing',
            'color' => '#9C27B0',
            'icon' => 'industry',
            'order' => 40,
        ],
        'SL' => [
            'name' => 'Sales',
            'code' => 'SL',
            'description' => 'Handles customer orders and revenue generation',
            'color' => '#F44336',
            'icon' => 'chart-line',
            'order' => 50,
        ],
        'LG' => [
            'name' => 'Logistics',
            'code' => 'LG',
            'description' => 'Manages delivery and supply chain',
            'color' => '#795548',
            'icon' => 'truck',
            'order' => 60,
        ],
        'QC' => [
            'name' => 'Quality Control',
            'code' => 'QC',
            'description' => 'Ensures product quality and standards',
            'color' => '#607D8B',
            'icon' => 'check-circle',
            'order' => 70,
        ],
        'IN' => [
            'name' => 'Inventory',
            'code' => 'IN',
            'description' => 'Manages stock and raw materials',
            'color' => '#8BC34A',
            'icon' => 'boxes',
            'order' => 80,
        ],
        'ADMIN' => [
            'name' => 'Administration',
            'code' => 'ADMIN',
            'description' => 'System administration and oversight',
            'color' => '#673AB7',
            'icon' => 'crown',
            'order' => 5,
        ],
    ],
    
    // =====================================================
    // ROLE DEFINITIONS WITH PRIVILEGE LEVELS
    // =====================================================
    // Privilege levels:
    // 100: Full system access (ADMIN)
    // 80+:  Department leadership (HOD)
    // 60+:  Supervisory roles (SUPERVISOR)
    // 50+:  Management roles (MANAGER)
    // 30+:  Operational staff (OFFICER)
    // 20+:  Support staff (ASSISTANT, CASHIER)
    // 15:   Production/Logistics staff (BAKER, DRIVER)
    // 1:    External users (CUSTOMER, VENDOR)
    'roles' => [
        'ADMIN' => [
            'level' => 100,
            'name' => 'Administrator',
            'description' => 'Full system access and configuration',
            'badge' => 'danger',
            'permissions' => [
                'can_manage_users' => true,
                'can_approve_requests' => true,
                'can_manage_budget' => true,
                'can_view_reports' => true,
                'can_manage_system' => true,
            ],
        ],
        'HOD' => [
            'level' => 80,
            'name' => 'Head of Department',
            'description' => 'Department oversight and approvals',
            'badge' => 'primary',
            'permissions' => [
                'can_manage_users' => true,
                'can_approve_requests' => true,
                'can_manage_budget' => true,
                'can_view_reports' => true,
                'can_manage_system' => false,
            ],
        ],
        'SUPERVISOR' => [
            'level' => 60,
            'name' => 'Supervisor',
            'description' => 'Team supervision and task management',
            'badge' => 'info',
            'permissions' => [
                'can_manage_users' => false,
                'can_approve_requests' => true,
                'can_manage_budget' => false,
                'can_view_reports' => true,
                'can_manage_system' => false,
            ],
        ],
        'MANAGER' => [
            'level' => 50,
            'name' => 'Manager',
            'description' => 'Branch or section management',
            'badge' => 'success',
            'permissions' => [
                'can_manage_users' => false,
                'can_approve_requests' => true,
                'can_manage_budget' => false,
                'can_view_reports' => true,
                'can_manage_system' => false,
            ],
        ],
        'OFFICER' => [
            'level' => 30,
            'name' => 'Officer',
            'description' => 'Regular staff with operational duties',
            'badge' => 'secondary',
            'permissions' => [
                'can_manage_users' => false,
                'can_approve_requests' => false,
                'can_manage_budget' => false,
                'can_view_reports' => true,
                'can_manage_system' => false,
            ],
        ],
        'ASSISTANT' => [
            'level' => 20,
            'name' => 'Assistant',
            'description' => 'Entry-level support staff',
            'badge' => 'light',
            'permissions' => [
                'can_manage_users' => false,
                'can_approve_requests' => false,
                'can_manage_budget' => false,
                'can_view_reports' => false,
                'can_manage_system' => false,
            ],
        ],
        'CASHIER' => [
            'level' => 20,
            'name' => 'Cashier',
            'description' => 'Sales and payment processing',
            'badge' => 'warning',
            'permissions' => [
                'can_manage_users' => false,
                'can_approve_requests' => false,
                'can_manage_budget' => false,
                'can_view_reports' => true,
                'can_manage_system' => false,
            ],
        ],
        'BAKER' => [
            'level' => 15,
            'name' => 'Baker',
            'description' => 'Production and baking staff',
            'badge' => 'dark',
            'permissions' => [
                'can_manage_users' => false,
                'can_approve_requests' => false,
                'can_manage_budget' => false,
                'can_view_reports' => false,
                'can_manage_system' => false,
            ],
        ],
        'DRIVER' => [
            'level' => 15,
            'name' => 'Driver',
            'description' => 'Delivery and logistics staff',
            'badge' => 'dark',
            'permissions' => [
                'can_manage_users' => false,
                'can_approve_requests' => false,
                'can_manage_budget' => false,
                'can_view_reports' => false,
                'can_manage_system' => false,
            ],
        ],
        'CUSTOMER' => [
            'level' => 1,
            'name' => 'Customer',
            'description' => 'Regular customer account',
            'badge' => 'secondary',
            'permissions' => [
                'can_manage_users' => false,
                'can_approve_requests' => false,
                'can_manage_budget' => false,
                'can_view_reports' => false,
                'can_manage_system' => false,
            ],
        ],
        'VENDOR' => [
            'level' => 1,
            'name' => 'Vendor',
            'description' => 'Supplier and wholesale partner',
            'badge' => 'secondary',
            'permissions' => [
                'can_manage_users' => false,
                'can_approve_requests' => false,
                'can_manage_budget' => false,
                'can_view_reports' => false,
                'can_manage_system' => false,
            ],
        ],
    ],
    
    // =====================================================
    // DEPARTMENT TO ROLE MAPPINGS
    // =====================================================
    // Defines which roles can exist in each department
    'department_roles' => [
        'HR' => ['HOD', 'SUPERVISOR', 'OFFICER', 'ASSISTANT'],
        'IT' => ['HOD', 'SUPERVISOR', 'OFFICER', 'ASSISTANT'],
        'FN' => ['HOD', 'SUPERVISOR', 'OFFICER', 'ASSISTANT'],
        'PD' => ['HOD', 'SUPERVISOR', 'BAKER', 'ASSISTANT'],
        'SL' => ['HOD', 'SUPERVISOR', 'CASHIER', 'ASSISTANT'],
        'LG' => ['HOD', 'SUPERVISOR', 'DRIVER', 'ASSISTANT'],
        'QC' => ['HOD', 'SUPERVISOR', 'OFFICER', 'ASSISTANT'],
        'IN' => ['HOD', 'SUPERVISOR', 'OFFICER', 'ASSISTANT'],
        'ADMIN' => ['ADMIN', 'HOD', 'SUPERVISOR', 'OFFICER', 'ASSISTANT'],
    ],
    
    // =====================================================
    // PERMISSION FLAGS
    // =====================================================
    // Database column names for permission flags
    'permission_flags' => [
        'can_manage_users',
        'can_approve_requests',
        'can_manage_budget',
        'can_view_reports',
        'can_manage_system',
    ],
    
    // =====================================================
    // DEFAULT DASHBOARD MAPPINGS
    // =====================================================
    // Can be overridden via system_config table
    'dashboard_mapping' => [
        'customer' => '/dashboards/customer-dashboard.php',
        'vendor' => '/dashboards/customer-dashboard.php',
        'staff' => '/dashboards/staff/general-dashboard.php',
        'default_staff' => '/dashboards/staff/general-dashboard.php',
        'default' => '/dashboards/customer-dashboard.php',
        'ADMIN' => '/dashboards/staff/admin-dashboard.php',
        'HOD' => '/dashboards/staff/general-dashboard.php',
        'department_hr' => '/dashboards/staff/hr-dashboard.php',
        'department_sl' => '/dashboards/staff/sales-dashboard.php',
    ],
    
    // =====================================================
    // VERSION INFORMATION
    // =====================================================
    '_version' => '4.0.0',
    '_last_updated' => '2025-03-27',
    '_compatibility' => 'PHP 8.3+',
    '_note' => 'This is the base configuration. Values can be overridden via system_config table.',
    
    // =====================================================
    // DEPRECATED KEYS (for backward compatibility)
    // =====================================================
    // The following keys are maintained for backward compatibility
    // with code that expects them. They will be removed in a future version.
    '_deprecated' => [
        'settings' => 'Use setting() function or config(\'settings.key\') instead',
    ],
];