<?php
require_once 'config/config.php';
requireLogin();

$page_title = 'Dashboard';
$conn = getDBConnection();
ensureRentalSchema($conn);
ensureMaintenanceSchema($conn);

if (isAdmin()) {
    $total_cars = $conn->query("SELECT COUNT(*) as count FROM cars")->fetch_assoc()['count'];
    $available_cars = $conn->query("SELECT COUNT(*) as count FROM cars WHERE status = 'available'")->fetch_assoc()['count'];
    $maintenance_cars = $conn->query("SELECT COUNT(*) as count FROM cars WHERE status = 'maintenance'")->fetch_assoc()['count'];
    $total_customers = $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'];
    $active_rentals = $conn->query("SELECT COUNT(*) as count FROM rentals WHERE status = 'active'")->fetch_assoc()['count'];
    $pending_payments = $conn->query("SELECT COUNT(*) as count
                                      FROM rental_payment_records pr
                                      JOIN rentals r ON pr.rental_id = r.id
                                      WHERE pr.status = 'pending' AND r.status = 'active'")->fetch_assoc()['count'];
    $total_maintenance_spent = $conn->query("SELECT COALESCE(SUM(cost), 0) as total FROM maintenance_records WHERE expense_category IN ('maintenance', 'repair', 'parts_replacement', 'inspection')")->fetch_assoc()['total'] ?? 0;
    $recent_cars = $conn->query("SELECT c.*, u.company_name, u.full_name
                                 FROM cars c
                                 JOIN users u ON c.user_id = u.id
                                 ORDER BY c.created_at DESC LIMIT 5");
} else {
    $user_id = intval($_SESSION['user_id']);
    $total_cars = $conn->query("SELECT COUNT(*) as count FROM cars WHERE user_id = $user_id")->fetch_assoc()['count'];
    $available_cars = $conn->query("SELECT COUNT(*) as count FROM cars WHERE user_id = $user_id AND status = 'available'")->fetch_assoc()['count'];
    $maintenance_cars = $conn->query("SELECT COUNT(*) as count FROM cars WHERE user_id = $user_id AND status = 'maintenance'")->fetch_assoc()['count'];
    $total_customers = $conn->query("SELECT COUNT(*) as count FROM customers WHERE user_id = $user_id")->fetch_assoc()['count'];
    $active_rentals = 0;
    $pending_payments = 0;
    $total_maintenance_spent = 0;
    $recent_cars = $conn->query("SELECT * FROM cars WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 5");
}

include 'includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2>Dashboard</h2>
        <p class="text-muted">Welcome back, <?php echo $_SESSION['full_name']; ?>!</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Total Cars</p>
                        <h3 class="mb-0"><?php echo $total_cars; ?></h3>
                    </div>
                    <div class="bg-dark bg-opacity-10 p-3 rounded">
                        <i class="bi bi-car-front fs-2 text-dark"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Available Cars</p>
                        <h3 class="mb-0"><?php echo $available_cars; ?></h3>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded">
                        <i class="bi bi-check-circle fs-2 text-success"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Maintenance</p>
                        <h3 class="mb-0"><?php echo $maintenance_cars; ?></h3>
                    </div>
                    <div class="bg-warning bg-opacity-10 p-3 rounded">
                        <i class="bi bi-tools fs-2 text-warning"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Customers</p>
                        <h3 class="mb-0"><?php echo $total_customers; ?></h3>
                    </div>
                    <div class="bg-info bg-opacity-10 p-3 rounded">
                        <i class="bi bi-people fs-2 text-info"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (isAdmin()): ?>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Active Rentals</p>
                        <h3 class="mb-0"><?php echo $active_rentals; ?></h3>
                    </div>
                    <div class="bg-primary bg-opacity-10 p-3 rounded">
                        <i class="bi bi-clipboard-check fs-2 text-primary"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Pending Payments</p>
                        <h3 class="mb-0"><?php echo $pending_payments; ?></h3>
                    </div>
                    <div class="bg-danger bg-opacity-10 p-3 rounded">
                        <i class="bi bi-clock-history fs-2 text-danger"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Maintenance / Repairs</p>
                        <h3 class="mb-0"><?php echo formatCurrency($total_maintenance_spent); ?></h3>
                    </div>
                    <div class="bg-secondary bg-opacity-10 p-3 rounded">
                        <i class="bi bi-cash-coin fs-2 text-secondary"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Cars</h5>
                <a href="cars.php?add=1" class="btn btn-sm btn-dark">
                    <i class="bi bi-plus-circle me-1"></i>Add Car
                </a>
            </div>
            <div class="card-body">
                <?php if ($recent_cars->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Plate Number</th>
                                <th>Car</th>
                                <th>Daily Rate</th>
                                <th>Status</th>
                                <?php if (isAdmin()): ?>
                                <th>Owner</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($car = $recent_cars->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo $car['plate_number']; ?></strong></td>
                                <td><?php echo $car['brand'] . ' ' . $car['model']; ?></td>
                                <td><?php echo formatCurrency($car['daily_rate']); ?></td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    switch($car['status']) {
                                        case 'available': $status_class = 'bg-success'; break;
                                        case 'rented': $status_class = 'bg-warning'; break;
                                        case 'maintenance': $status_class = 'bg-danger'; break;
                                    }
                                    ?>
                                    <span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($car['status']); ?></span>
                                </td>
                                <?php if (isAdmin()): ?>
                                <td><?php echo $car['company_name'] ?? $car['full_name']; ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-4">No cars found</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
closeDBConnection($conn);
include 'includes/footer.php';
?>
