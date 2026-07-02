<?php
require_once 'config/config.php';
requireLogin();

$page_title = 'Cars Management';
$conn = getDBConnection();
ensurePricingSchema($conn);
$user_id = intval($_SESSION['user_id']);
$form_error = '';
$open_modal = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $car_action = sanitize($_POST['car_action'] ?? '');

    if ($car_action == 'create' || $car_action == 'update') {
        $car_id = intval($_POST['car_id'] ?? 0);
        $brand = sanitize($_POST['brand'] ?? '');
        $model = sanitize($_POST['model'] ?? '');
        $year = intval($_POST['year'] ?? date('Y'));
        $color = sanitize($_POST['color'] ?? '');
        $plate_number = sanitize($_POST['plate_number'] ?? '');
        $daily_rate = floatval($_POST['daily_rate'] ?? 0);
        $weekly_rate = ($_POST['weekly_rate'] ?? '') !== '' ? floatval($_POST['weekly_rate']) : null;
        $monthly_rate = ($_POST['monthly_rate'] ?? '') !== '' ? floatval($_POST['monthly_rate']) : null;
        $status = sanitize($_POST['status'] ?? 'available');
        $description = sanitize($_POST['description'] ?? '');

        if (empty($brand) || empty($model) || empty($plate_number) || $daily_rate <= 0) {
            $form_error = 'Please fill in all required fields';
            $open_modal = $car_action == 'create' ? 'addCarModal' : 'editCarModal' . $car_id;
        } else {
            if ($car_action == 'create') {
                $owner_id = $user_id;
                if ($owner_id <= 0) {
                    $owner_result = $conn->query("SELECT id FROM users WHERE status = 'active' ORDER BY role = 'admin' DESC, role = 'agent' DESC, full_name LIMIT 1");
                    if ($owner_result && $owner_result->num_rows === 1) {
                        $owner_id = intval($owner_result->fetch_assoc()['id']);
                    }
                }

                if ($owner_id <= 0) {
                    $form_error = 'No active account is available to own this car.';
                    $open_modal = 'addCarModal';
                } else {
                    $check_stmt = $conn->prepare("SELECT id FROM cars WHERE plate_number = ?");
                    $check_stmt->bind_param("s", $plate_number);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();

                    if ($check_result->num_rows > 0) {
                        $form_error = 'A car with this plate number already exists';
                        $open_modal = 'addCarModal';
                    } else {
                        $insert_stmt = $conn->prepare("INSERT INTO cars (user_id, brand, model, year, color, plate_number, daily_rate, weekly_rate, monthly_rate, status, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $insert_stmt->bind_param("ississdddss", $owner_id, $brand, $model, $year, $color, $plate_number, $daily_rate, $weekly_rate, $monthly_rate, $status, $description);

                        if ($insert_stmt->execute()) {
                            header('Location: cars.php');
                            exit();
                        }

                        $form_error = 'Something went wrong. Please try again.';
                        $open_modal = 'addCarModal';
                        $insert_stmt->close();
                    }

                    $check_stmt->close();
                }
            } else {
                $access_sql = isAdmin() ? "SELECT id FROM cars WHERE id = ?" : "SELECT id FROM cars WHERE id = ? AND user_id = ?";
                $access_stmt = $conn->prepare($access_sql);
                if (isAdmin()) {
                    $access_stmt->bind_param("i", $car_id);
                } else {
                    $access_stmt->bind_param("ii", $car_id, $user_id);
                }
                $access_stmt->execute();
                $access_result = $access_stmt->get_result();
                $access_stmt->close();

                if ($access_result->num_rows == 0) {
                    $form_error = 'Selected car not found';
                    $open_modal = 'editCarModal' . $car_id;
                } else {
                    $check_stmt = $conn->prepare("SELECT id FROM cars WHERE plate_number = ? AND id != ?");
                    $check_stmt->bind_param("si", $plate_number, $car_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();

                    if ($check_result->num_rows > 0) {
                        $form_error = 'A car with this plate number already exists';
                        $open_modal = 'editCarModal' . $car_id;
                    } else {
                        $update_stmt = $conn->prepare("UPDATE cars SET brand = ?, model = ?, year = ?, color = ?, plate_number = ?, daily_rate = ?, weekly_rate = ?, monthly_rate = ?, status = ?, description = ? WHERE id = ?");
                        $update_stmt->bind_param("ssissdddssi", $brand, $model, $year, $color, $plate_number, $daily_rate, $weekly_rate, $monthly_rate, $status, $description, $car_id);

                        if ($update_stmt->execute()) {
                            header('Location: cars.php');
                            exit();
                        }

                        $form_error = 'Something went wrong. Please try again.';
                        $open_modal = 'editCarModal' . $car_id;
                        $update_stmt->close();
                    }

                    $check_stmt->close();
                }
            }
        }
    } elseif ($car_action == 'delete') {
        $car_id = intval($_POST['car_id'] ?? 0);
        $delete_stmt = isAdmin()
            ? $conn->prepare("DELETE FROM cars WHERE id = ?")
            : $conn->prepare("DELETE FROM cars WHERE id = ? AND user_id = ?");

        if (isAdmin()) {
            $delete_stmt->bind_param("i", $car_id);
        } else {
            $delete_stmt->bind_param("ii", $car_id, $user_id);
        }

        $delete_stmt->execute();
        $delete_stmt->close();
        header('Location: cars.php');
        exit();
    }
}

if (isAdmin()) {
    $cars = $conn->query("SELECT c.*, u.company_name, u.full_name
                          FROM cars c
                          JOIN users u ON c.user_id = u.id
                          ORDER BY c.created_at DESC");
} else {
    $cars = $conn->query("SELECT * FROM cars WHERE user_id = $user_id ORDER BY created_at DESC");
}

$car_rows = [];
while ($car = $cars->fetch_assoc()) {
    $car_rows[] = $car;
}

include 'includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2>Cars Management</h2>
        <p class="text-muted">Manage your fleet of vehicles</p>
    </div>
    <div class="col-md-6 text-end">
        <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#addCarModal">
            <i class="bi bi-plus-circle me-2"></i>Add New Car
        </button>
    </div>
</div>

<?php if ($form_error): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $form_error; ?>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <?php if (count($car_rows) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Plate Number</th>
                        <th>Brand</th>
                        <th>Model</th>
                        <th>Year</th>
                        <th>Color</th>
                        <th>Rates</th>
                        <th>Status</th>
                        <?php if (isAdmin()): ?>
                        <th>Owner</th>
                        <?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($car_rows as $car): ?>
                    <tr>
                        <td><strong><?php echo $car['plate_number']; ?></strong></td>
                        <td><?php echo $car['brand']; ?></td>
                        <td><?php echo $car['model']; ?></td>
                        <td><?php echo $car['year']; ?></td>
                        <td><?php echo $car['color']; ?></td>
                        <td>
                            <strong><?php echo formatCurrency($car['daily_rate']); ?>/day</strong><br>
                            <small class="text-muted">
                                <?php echo $car['weekly_rate'] ? formatCurrency($car['weekly_rate']) . '/week' : 'No weekly rate'; ?><br>
                                <?php echo $car['monthly_rate'] ? formatCurrency($car['monthly_rate']) . '/month' : 'No monthly rate'; ?>
                            </small>
                        </td>
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
                        <td>
                            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#editCarModal<?php echo $car['id']; ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteCarModal<?php echo $car['id']; ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5">
            <i class="bi bi-car-front fs-1 text-muted"></i>
            <p class="text-muted mt-3">No cars found. Add your first car to get started!</p>
            <button type="button" class="btn btn-dark mt-2" data-bs-toggle="modal" data-bs-target="#addCarModal">
                <i class="bi bi-plus-circle me-2"></i>Add New Car
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addCarModal" tabindex="-1" aria-labelledby="addCarModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" action="cars.php" class="modal-content">
            <input type="hidden" name="car_action" value="create">
            <div class="modal-header">
                <h5 class="modal-title" id="addCarModalLabel">Add New Car</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php $car_form = ['brand' => '', 'model' => '', 'year' => date('Y'), 'color' => '', 'plate_number' => '', 'daily_rate' => '', 'weekly_rate' => '', 'monthly_rate' => '', 'status' => 'available', 'description' => '']; ?>
                <?php include __DIR__ . '/includes/car_form_fields.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark">
                    <i class="bi bi-plus-circle me-2"></i>Add Car
                </button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($car_rows as $car): ?>
<div class="modal fade" id="editCarModal<?php echo $car['id']; ?>" tabindex="-1" aria-labelledby="editCarModalLabel<?php echo $car['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" action="cars.php" class="modal-content">
            <input type="hidden" name="car_action" value="update">
            <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="editCarModalLabel<?php echo $car['id']; ?>">Edit Car</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php $car_form = $car; ?>
                <?php include __DIR__ . '/includes/car_form_fields.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark">
                    <i class="bi bi-check-circle me-2"></i>Update Car
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="deleteCarModal<?php echo $car['id']; ?>" tabindex="-1" aria-labelledby="deleteCarModalLabel<?php echo $car['id']; ?>" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="cars.php" class="modal-content">
            <input type="hidden" name="car_action" value="delete">
            <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteCarModalLabel<?php echo $car['id']; ?>">Delete Car</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Delete <strong><?php echo $car['brand'] . ' ' . $car['model']; ?></strong> with plate <strong><?php echo $car['plate_number']; ?></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash me-2"></i>Delete
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const addCar = new URLSearchParams(window.location.search).get('add');
    if (addCar) {
        new bootstrap.Modal(document.getElementById('addCarModal')).show();
    }

    <?php if ($open_modal): ?>
    new bootstrap.Modal(document.getElementById('<?php echo $open_modal; ?>')).show();
    <?php endif; ?>
});
</script>

<?php
closeDBConnection($conn);
include 'includes/footer.php';
?>
