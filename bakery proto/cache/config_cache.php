<?php
// Configuration cache - Generated: 2026-06-09 11:38:47
return array (
  'timestamp' => 1781001527,
  'config' => 
  array (
    'departments' => 
    array (
      'HR' => 
      array (
        'name' => 'Human Resources',
        'code' => 'HR',
        'description' => 'Manages personnel, recruitment, and employee relations',
        'color' => '#4CAF50',
        'icon' => 'users',
        'order' => 10,
      ),
      'IT' => 
      array (
        'name' => 'Information Technology',
        'code' => 'IT',
        'description' => 'Handles systems, networks, and technical support',
        'color' => '#2196F3',
        'icon' => 'laptop',
        'order' => 20,
      ),
      'FN' => 
      array (
        'name' => 'Finance',
        'code' => 'FN',
        'description' => 'Manages budgets, payments, and financial reporting',
        'color' => '#FF9800',
        'icon' => 'money-bill',
        'order' => 30,
      ),
      'PD' => 
      array (
        'name' => 'Production',
        'code' => 'PD',
        'description' => 'Overseas baking and product manufacturing',
        'color' => '#9C27B0',
        'icon' => 'industry',
        'order' => 40,
      ),
      'SL' => 
      array (
        'name' => 'Sales',
        'code' => 'SL',
        'description' => 'Handles customer orders and revenue generation',
        'color' => '#F44336',
        'icon' => 'chart-line',
        'order' => 50,
      ),
      'LG' => 
      array (
        'name' => 'Logistics',
        'code' => 'LG',
        'description' => 'Manages delivery and supply chain',
        'color' => '#795548',
        'icon' => 'truck',
        'order' => 60,
      ),
      'QC' => 
      array (
        'name' => 'Quality Control',
        'code' => 'QC',
        'description' => 'Ensures product quality and standards',
        'color' => '#607D8B',
        'icon' => 'check-circle',
        'order' => 70,
      ),
      'IN' => 
      array (
        'name' => 'Inventory',
        'code' => 'IN',
        'description' => 'Manages stock and raw materials',
        'color' => '#8BC34A',
        'icon' => 'boxes',
        'order' => 80,
      ),
      'ADMIN' => 
      array (
        'name' => 'Administration',
        'code' => 'ADMIN',
        'description' => 'System administration and oversight',
        'color' => '#673AB7',
        'icon' => 'crown',
        'order' => 5,
      ),
    ),
    'roles' => 
    array (
      'ADMIN' => 
      array (
        'level' => 100,
        'name' => 'Administrator',
        'description' => 'Full system access and configuration',
        'badge' => 'danger',
        'permissions' => 
        array (
          'can_manage_users' => true,
          'can_approve_requests' => true,
          'can_manage_budget' => true,
          'can_view_reports' => true,
          'can_manage_system' => true,
        ),
      ),
      'HOD' => 
      array (
        'level' => 80,
        'name' => 'Head of Department',
        'description' => 'Department oversight and approvals',
        'badge' => 'primary',
        'permissions' => 
        array (
          'can_manage_users' => true,
          'can_approve_requests' => true,
          'can_manage_budget' => true,
          'can_view_reports' => true,
          'can_manage_system' => false,
        ),
      ),
      'SUPERVISOR' => 
      array (
        'level' => 60,
        'name' => 'Supervisor',
        'description' => 'Team supervision and task management',
        'badge' => 'info',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => true,
          'can_manage_budget' => false,
          'can_view_reports' => true,
          'can_manage_system' => false,
        ),
      ),
      'MANAGER' => 
      array (
        'level' => 50,
        'name' => 'Manager',
        'description' => 'Branch or section management',
        'badge' => 'success',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => true,
          'can_manage_budget' => false,
          'can_view_reports' => true,
          'can_manage_system' => false,
        ),
      ),
      'OFFICER' => 
      array (
        'level' => 30,
        'name' => 'Officer',
        'description' => 'Regular staff with operational duties',
        'badge' => 'secondary',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => true,
          'can_manage_system' => false,
        ),
      ),
      'ASSISTANT' => 
      array (
        'level' => 20,
        'name' => 'Assistant',
        'description' => 'Entry-level support staff',
        'badge' => 'light',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => false,
          'can_manage_system' => false,
        ),
      ),
      'CASHIER' => 
      array (
        'level' => 20,
        'name' => 'Cashier',
        'description' => 'Sales and payment processing',
        'badge' => 'warning',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => true,
          'can_manage_system' => false,
        ),
      ),
      'BAKER' => 
      array (
        'level' => 15,
        'name' => 'Baker',
        'description' => 'Production and baking staff',
        'badge' => 'dark',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => false,
          'can_manage_system' => false,
        ),
      ),
      'DRIVER' => 
      array (
        'level' => 15,
        'name' => 'Driver',
        'description' => 'Delivery and logistics staff',
        'badge' => 'dark',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => false,
          'can_manage_system' => false,
        ),
      ),
      'CUSTOMER' => 
      array (
        'level' => 1,
        'name' => 'Customer',
        'description' => 'Regular customer account',
        'badge' => 'secondary',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => false,
          'can_manage_system' => false,
        ),
      ),
      'VENDOR' => 
      array (
        'level' => 1,
        'name' => 'Vendor',
        'description' => 'Supplier and wholesale partner',
        'badge' => 'secondary',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => false,
          'can_manage_system' => false,
        ),
      ),
    ),
    'department_roles' => 
    array (
      'HR' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'OFFICER',
        3 => 'ASSISTANT',
      ),
      'IT' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'OFFICER',
        3 => 'ASSISTANT',
      ),
      'FN' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'OFFICER',
        3 => 'ASSISTANT',
      ),
      'PD' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'BAKER',
        3 => 'ASSISTANT',
      ),
      'SL' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'CASHIER',
        3 => 'ASSISTANT',
      ),
      'LG' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'DRIVER',
        3 => 'ASSISTANT',
      ),
      'QC' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'OFFICER',
        3 => 'ASSISTANT',
      ),
      'IN' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'OFFICER',
        3 => 'ASSISTANT',
      ),
      'ADMIN' => 
      array (
        0 => 'ADMIN',
        1 => 'HOD',
        2 => 'SUPERVISOR',
        3 => 'OFFICER',
        4 => 'ASSISTANT',
      ),
    ),
    'permission_flags' => 
    array (
      0 => 'can_manage_users',
      1 => 'can_approve_requests',
      2 => 'can_manage_budget',
      3 => 'can_view_reports',
      4 => 'can_manage_system',
    ),
    'dashboard_mapping' => 
    array (
      'customer' => '/dashboards/customer-dashboard.php',
      'vendor' => '/dashboards/customer-dashboard.php',
      'staff' => '/dashboards/staff/general-dashboard.php',
      'default_staff' => '/dashboards/staff/general-dashboard.php',
      'default' => '/dashboards/customer-dashboard.php',
      'ADMIN' => '/dashboards/staff/admin-dashboard.php',
      'HOD' => '/dashboards/staff/general-dashboard.php',
      'department_hr' => '/dashboards/staff/hr-dashboard.php',
      'department_sl' => '/dashboards/staff/sales-dashboard.php',
    ),
    '_version' => '4.0.0',
    '_last_updated' => '2025-03-27',
    '_compatibility' => 'PHP 8.3+',
    '_note' => 'This is the base configuration. Values can be overridden via system_config table.',
    '_deprecated' => 
    array (
      'settings' => 'Use setting() function or config(\'settings.key\') instead',
    ),
    'password_min_length' => 8,
    'password_require_uppercase' => true,
    'password_require_lowercase' => true,
    'password_require_numbers' => true,
    'password_require_special' => true,
    'max_login_attempts' => 5,
    'lockout_duration' => 15,
    'session_lifetime' => 28800,
    'session_inactivity_timeout' => 1800,
    'force_password_change_days' => 90,
    'remember_me_days' => 30,
    'two_factor_auth_required' => false,
    'app_name' => 'Fingerchops Ventures',
    'app_version' => '4.0.0',
    'timezone' => 'Africa/Lagos',
    'date_format' => 'Y-m-d',
    'datetime_format' => 'Y-m-d H:i:s',
    'maintenance_mode' => false,
    'site_name' => 'Fingerchops Bakery',
    'admin_email' => 'admin@fingerchops.com',
    'currency' => '₦',
    'currency_code' => 'NGN',
    'low_stock_threshold' => 10,
    'critical_stock_threshold' => 5,
    'min_items_per_product' => 10,
    'min_total_items' => 30,
    'settings' => 
    array (
      'password_min_length' => 8,
      'password_require_uppercase' => true,
      'password_require_lowercase' => true,
      'password_require_numbers' => true,
      'password_require_special' => true,
      'max_login_attempts' => 5,
      'lockout_duration' => 15,
      'session_lifetime' => 28800,
      'session_inactivity_timeout' => 1800,
      'force_password_change_days' => 90,
      'remember_me_days' => 30,
      'two_factor_auth_required' => false,
      'app_name' => 'Fingerchops Ventures',
      'app_version' => '4.0.0',
      'timezone' => 'Africa/Lagos',
      'date_format' => 'Y-m-d',
      'datetime_format' => 'Y-m-d H:i:s',
      'maintenance_mode' => false,
      'site_name' => 'Fingerchops Bakery',
      'admin_email' => 'admin@fingerchops.com',
      'currency' => '₦',
      'currency_code' => 'NGN',
      'low_stock_threshold' => 10,
      'critical_stock_threshold' => 5,
      'min_items_per_product' => 10,
      'min_total_items' => 30,
    ),
  ),
  'fileConfig' => 
  array (
    'departments' => 
    array (
      'HR' => 
      array (
        'name' => 'Human Resources',
        'code' => 'HR',
        'description' => 'Manages personnel, recruitment, and employee relations',
        'color' => '#4CAF50',
        'icon' => 'users',
        'order' => 10,
      ),
      'IT' => 
      array (
        'name' => 'Information Technology',
        'code' => 'IT',
        'description' => 'Handles systems, networks, and technical support',
        'color' => '#2196F3',
        'icon' => 'laptop',
        'order' => 20,
      ),
      'FN' => 
      array (
        'name' => 'Finance',
        'code' => 'FN',
        'description' => 'Manages budgets, payments, and financial reporting',
        'color' => '#FF9800',
        'icon' => 'money-bill',
        'order' => 30,
      ),
      'PD' => 
      array (
        'name' => 'Production',
        'code' => 'PD',
        'description' => 'Overseas baking and product manufacturing',
        'color' => '#9C27B0',
        'icon' => 'industry',
        'order' => 40,
      ),
      'SL' => 
      array (
        'name' => 'Sales',
        'code' => 'SL',
        'description' => 'Handles customer orders and revenue generation',
        'color' => '#F44336',
        'icon' => 'chart-line',
        'order' => 50,
      ),
      'LG' => 
      array (
        'name' => 'Logistics',
        'code' => 'LG',
        'description' => 'Manages delivery and supply chain',
        'color' => '#795548',
        'icon' => 'truck',
        'order' => 60,
      ),
      'QC' => 
      array (
        'name' => 'Quality Control',
        'code' => 'QC',
        'description' => 'Ensures product quality and standards',
        'color' => '#607D8B',
        'icon' => 'check-circle',
        'order' => 70,
      ),
      'IN' => 
      array (
        'name' => 'Inventory',
        'code' => 'IN',
        'description' => 'Manages stock and raw materials',
        'color' => '#8BC34A',
        'icon' => 'boxes',
        'order' => 80,
      ),
      'ADMIN' => 
      array (
        'name' => 'Administration',
        'code' => 'ADMIN',
        'description' => 'System administration and oversight',
        'color' => '#673AB7',
        'icon' => 'crown',
        'order' => 5,
      ),
    ),
    'roles' => 
    array (
      'ADMIN' => 
      array (
        'level' => 100,
        'name' => 'Administrator',
        'description' => 'Full system access and configuration',
        'badge' => 'danger',
        'permissions' => 
        array (
          'can_manage_users' => true,
          'can_approve_requests' => true,
          'can_manage_budget' => true,
          'can_view_reports' => true,
          'can_manage_system' => true,
        ),
      ),
      'HOD' => 
      array (
        'level' => 80,
        'name' => 'Head of Department',
        'description' => 'Department oversight and approvals',
        'badge' => 'primary',
        'permissions' => 
        array (
          'can_manage_users' => true,
          'can_approve_requests' => true,
          'can_manage_budget' => true,
          'can_view_reports' => true,
          'can_manage_system' => false,
        ),
      ),
      'SUPERVISOR' => 
      array (
        'level' => 60,
        'name' => 'Supervisor',
        'description' => 'Team supervision and task management',
        'badge' => 'info',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => true,
          'can_manage_budget' => false,
          'can_view_reports' => true,
          'can_manage_system' => false,
        ),
      ),
      'MANAGER' => 
      array (
        'level' => 50,
        'name' => 'Manager',
        'description' => 'Branch or section management',
        'badge' => 'success',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => true,
          'can_manage_budget' => false,
          'can_view_reports' => true,
          'can_manage_system' => false,
        ),
      ),
      'OFFICER' => 
      array (
        'level' => 30,
        'name' => 'Officer',
        'description' => 'Regular staff with operational duties',
        'badge' => 'secondary',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => true,
          'can_manage_system' => false,
        ),
      ),
      'ASSISTANT' => 
      array (
        'level' => 20,
        'name' => 'Assistant',
        'description' => 'Entry-level support staff',
        'badge' => 'light',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => false,
          'can_manage_system' => false,
        ),
      ),
      'CASHIER' => 
      array (
        'level' => 20,
        'name' => 'Cashier',
        'description' => 'Sales and payment processing',
        'badge' => 'warning',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => true,
          'can_manage_system' => false,
        ),
      ),
      'BAKER' => 
      array (
        'level' => 15,
        'name' => 'Baker',
        'description' => 'Production and baking staff',
        'badge' => 'dark',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => false,
          'can_manage_system' => false,
        ),
      ),
      'DRIVER' => 
      array (
        'level' => 15,
        'name' => 'Driver',
        'description' => 'Delivery and logistics staff',
        'badge' => 'dark',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => false,
          'can_manage_system' => false,
        ),
      ),
      'CUSTOMER' => 
      array (
        'level' => 1,
        'name' => 'Customer',
        'description' => 'Regular customer account',
        'badge' => 'secondary',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => false,
          'can_manage_system' => false,
        ),
      ),
      'VENDOR' => 
      array (
        'level' => 1,
        'name' => 'Vendor',
        'description' => 'Supplier and wholesale partner',
        'badge' => 'secondary',
        'permissions' => 
        array (
          'can_manage_users' => false,
          'can_approve_requests' => false,
          'can_manage_budget' => false,
          'can_view_reports' => false,
          'can_manage_system' => false,
        ),
      ),
    ),
    'department_roles' => 
    array (
      'HR' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'OFFICER',
        3 => 'ASSISTANT',
      ),
      'IT' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'OFFICER',
        3 => 'ASSISTANT',
      ),
      'FN' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'OFFICER',
        3 => 'ASSISTANT',
      ),
      'PD' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'BAKER',
        3 => 'ASSISTANT',
      ),
      'SL' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'CASHIER',
        3 => 'ASSISTANT',
      ),
      'LG' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'DRIVER',
        3 => 'ASSISTANT',
      ),
      'QC' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'OFFICER',
        3 => 'ASSISTANT',
      ),
      'IN' => 
      array (
        0 => 'HOD',
        1 => 'SUPERVISOR',
        2 => 'OFFICER',
        3 => 'ASSISTANT',
      ),
      'ADMIN' => 
      array (
        0 => 'ADMIN',
        1 => 'HOD',
        2 => 'SUPERVISOR',
        3 => 'OFFICER',
        4 => 'ASSISTANT',
      ),
    ),
    'permission_flags' => 
    array (
      0 => 'can_manage_users',
      1 => 'can_approve_requests',
      2 => 'can_manage_budget',
      3 => 'can_view_reports',
      4 => 'can_manage_system',
    ),
    'dashboard_mapping' => 
    array (
      'customer' => '/dashboards/customer-dashboard.php',
      'vendor' => '/dashboards/customer-dashboard.php',
      'staff' => '/dashboards/staff/general-dashboard.php',
      'default_staff' => '/dashboards/staff/general-dashboard.php',
      'default' => '/dashboards/customer-dashboard.php',
      'ADMIN' => '/dashboards/staff/admin-dashboard.php',
      'HOD' => '/dashboards/staff/general-dashboard.php',
      'department_hr' => '/dashboards/staff/hr-dashboard.php',
      'department_sl' => '/dashboards/staff/sales-dashboard.php',
    ),
    '_version' => '4.0.0',
    '_last_updated' => '2025-03-27',
    '_compatibility' => 'PHP 8.3+',
    '_note' => 'This is the base configuration. Values can be overridden via system_config table.',
    '_deprecated' => 
    array (
      'settings' => 'Use setting() function or config(\'settings.key\') instead',
    ),
  ),
  'dbConfig' => 
  array (
    'password_min_length' => 8,
    'password_require_uppercase' => true,
    'password_require_lowercase' => true,
    'password_require_numbers' => true,
    'password_require_special' => true,
    'max_login_attempts' => 5,
    'lockout_duration' => 15,
    'session_lifetime' => 28800,
    'session_inactivity_timeout' => 1800,
    'force_password_change_days' => 90,
    'remember_me_days' => 30,
    'two_factor_auth_required' => false,
    'app_name' => 'Fingerchops Ventures',
    'app_version' => '4.0.0',
    'timezone' => 'Africa/Lagos',
    'date_format' => 'Y-m-d',
    'datetime_format' => 'Y-m-d H:i:s',
    'maintenance_mode' => false,
    'site_name' => 'Fingerchops Bakery',
    'admin_email' => 'admin@fingerchops.com',
    'currency' => '₦',
    'currency_code' => 'NGN',
    'low_stock_threshold' => 10,
    'critical_stock_threshold' => 5,
    'min_items_per_product' => 10,
    'min_total_items' => 30,
  ),
);
