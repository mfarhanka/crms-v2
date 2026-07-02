<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' . SITE_NAME : SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>assets/css/style.css">
</head>
<body class="bg-light">
    <?php
    $current_page = basename($_SERVER['PHP_SELF']);
    $section_pages = [
        'dashboard' => ['dashboard.php'],
        'cars' => ['cars.php'],
        'customers' => ['customers.php'],
        'rentals' => ['rentals.php'],
        'maintenance' => ['maintenance.php'],
        'admin' => ['admin.php'],
    ];

    $is_active_section = function($section) use ($current_page, $section_pages) {
        return in_array($current_page, $section_pages[$section] ?? []) ? 'active' : '';
    };
    ?>
    <nav class="navbar navbar-dark bg-dark shadow-sm app-topbar">
        <div class="container-fluid">
            <button class="btn btn-light d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="Open menu">
                <i class="bi bi-list"></i>
            </button>
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-car-front-fill me-2"></i>Car Rental System
            </a>
            <div class="ms-auto">
                <ul class="navbar-nav flex-row">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i><?php echo $_SESSION['full_name']; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <aside class="app-sidebar d-none d-lg-flex flex-column">
        <div class="sidebar-heading">Main Menu</div>
        <nav class="sidebar-nav">
            <a class="sidebar-link <?php echo $is_active_section('dashboard'); ?>" href="dashboard.php">
                <i class="bi bi-speedometer2"></i><span>Dashboard</span>
            </a>
            <a class="sidebar-link <?php echo $is_active_section('cars'); ?>" href="cars.php">
                <i class="bi bi-car-front"></i><span>Cars</span>
            </a>
            <a class="sidebar-link <?php echo $is_active_section('customers'); ?>" href="customers.php">
                <i class="bi bi-people"></i><span>Customers</span>
            </a>
            <?php if (isAdmin()): ?>
            <a class="sidebar-link <?php echo $is_active_section('rentals'); ?>" href="rentals.php">
                <i class="bi bi-clipboard-check"></i><span>Rentals</span>
            </a>
            <a class="sidebar-link <?php echo $is_active_section('maintenance'); ?>" href="maintenance.php">
                <i class="bi bi-receipt-cutoff"></i><span>Vehicle Expenses</span>
            </a>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
            <a class="sidebar-link <?php echo $is_active_section('admin'); ?>" href="admin.php">
                <i class="bi bi-shield-lock"></i><span>Admin Panel</span>
            </a>
            <?php endif; ?>
        </nav>
    </aside>

    <div class="offcanvas offcanvas-start app-mobile-sidebar" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title" id="mobileSidebarLabel">
                <i class="bi bi-car-front-fill me-2"></i>Menu
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <nav class="sidebar-nav">
                <a class="sidebar-link <?php echo $is_active_section('dashboard'); ?>" href="dashboard.php">
                    <i class="bi bi-speedometer2"></i><span>Dashboard</span>
                </a>
                <a class="sidebar-link <?php echo $is_active_section('cars'); ?>" href="cars.php">
                    <i class="bi bi-car-front"></i><span>Cars</span>
                </a>
                <a class="sidebar-link <?php echo $is_active_section('customers'); ?>" href="customers.php">
                    <i class="bi bi-people"></i><span>Customers</span>
                </a>
                <?php if (isAdmin()): ?>
                <a class="sidebar-link <?php echo $is_active_section('rentals'); ?>" href="rentals.php">
                    <i class="bi bi-clipboard-check"></i><span>Rentals</span>
                </a>
                <a class="sidebar-link <?php echo $is_active_section('maintenance'); ?>" href="maintenance.php">
                    <i class="bi bi-receipt-cutoff"></i><span>Vehicle Expenses</span>
                </a>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                <a class="sidebar-link <?php echo $is_active_section('admin'); ?>" href="admin.php">
                    <i class="bi bi-shield-lock"></i><span>Admin Panel</span>
                </a>
                <?php endif; ?>
            </nav>
        </div>
    </div>
    
    <main class="app-main">
        <div class="container-fluid py-4">
