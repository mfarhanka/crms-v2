<?php
require_once 'config/config.php';
requireAdmin();

$page_title = 'Vehicle Expenses';
$conn = getDBConnection();
ensureMaintenanceSchema($conn);

$error = '';
$success = '';
$open_modal = '';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function maintenanceInput($value) {
    return strip_tags(trim((string) $value));
}

function uploadMaintenanceReceipt($field_name) {
    if (empty($_FILES[$field_name]['name'])) {
        return '';
    }

    $upload_dir = 'uploads/receipts/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($_FILES[$field_name]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_types, true)) {
        return '';
    }

    $receipt_photo = 'maintenance_receipt_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    move_uploaded_file($_FILES[$field_name]['tmp_name'], $upload_dir . $receipt_photo);
    return $receipt_photo;
}

function deleteMaintenanceReceiptFile($receipt_photo) {
    if (!empty($receipt_photo) && file_exists('uploads/receipts/' . $receipt_photo)) {
        unlink('uploads/receipts/' . $receipt_photo);
    }
}

function updateCarMaintenanceStatus($conn, $car_id) {
    $active_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM maintenance_records
                                   WHERE car_id = ?
                                     AND status IN ('sent', 'in_progress')
                                     AND expense_category IN ('maintenance', 'repair', 'parts_replacement', 'inspection')");
    $active_stmt->bind_param("i", $car_id);
    $active_stmt->execute();
    $active_count = intval($active_stmt->get_result()->fetch_assoc()['count'] ?? 0);
    $active_stmt->close();

    if ($active_count > 0) {
        $status_stmt = $conn->prepare("UPDATE cars SET status = 'maintenance' WHERE id = ?");
    } else {
        $status_stmt = $conn->prepare("UPDATE cars SET status = 'available' WHERE id = ? AND status = 'maintenance'");
    }

    $status_stmt->bind_param("i", $car_id);
    $status_stmt->execute();
    $status_stmt->close();
}

function maintenanceCategoryOptions() {
    return [
        'maintenance' => 'Maintenance / Servicing',
        'cleaning' => 'Cleaning',
        'repair' => 'Repair',
        'parts_replacement' => 'Parts Replacement',
        'accessory' => 'Accessory / Upgrade',
        'loan_installment' => 'Loan / Bank Installment',
        'insurance_roadtax' => 'Insurance / Road Tax',
        'fuel_toll_parking' => 'Fuel / Toll / Parking',
        'inspection' => 'Inspection / Compliance',
        'other' => 'Other',
    ];
}

function maintenanceCategoryLabel($category) {
    $categories = maintenanceCategoryOptions();
    return $categories[$category] ?? 'Maintenance / Servicing';
}

function maintenanceCategoryClass($category) {
    $classes = [
        'maintenance' => 'bg-secondary',
        'cleaning' => 'bg-info',
        'repair' => 'bg-danger',
        'parts_replacement' => 'bg-warning text-dark',
        'accessory' => 'bg-primary',
        'loan_installment' => 'bg-dark',
        'insurance_roadtax' => 'bg-success',
        'fuel_toll_parking' => 'bg-light text-dark',
        'inspection' => 'bg-secondary',
        'other' => 'bg-secondary',
    ];
    return $classes[$category] ?? 'bg-secondary';
}

function maintenanceStatusLabel($status) {
    $labels = [
        'sent' => 'Sent',
        'in_progress' => 'In Progress',
        'completed' => 'Completed / Paid',
    ];
    return $labels[$status] ?? 'Sent';
}

function maintenanceStatusClass($status) {
    $classes = [
        'sent' => 'bg-warning',
        'in_progress' => 'bg-primary',
        'completed' => 'bg-success',
    ];
    return $classes[$status] ?? 'bg-warning';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $maintenance_action = sanitize($_POST['maintenance_action'] ?? '');

    if ($maintenance_action === 'create' || $maintenance_action === 'update') {
        $record_id = intval($_POST['record_id'] ?? 0);
        $car_id = intval($_POST['car_id'] ?? 0);
        $expense_category = maintenanceInput($_POST['expense_category'] ?? 'maintenance');
        $service_date = maintenanceInput($_POST['service_date'] ?? '');
        $service_type = maintenanceInput($_POST['service_type'] ?? '');
        $vendor = maintenanceInput($_POST['vendor'] ?? '');
        $cost = floatval($_POST['cost'] ?? 0);
        $status = maintenanceInput($_POST['status'] ?? 'sent');
        $notes = maintenanceInput($_POST['notes'] ?? '');
        $allowed_statuses = ['sent', 'in_progress', 'completed'];
        $allowed_categories = array_keys(maintenanceCategoryOptions());

        if ($car_id <= 0 || empty($service_date) || empty($service_type) || $cost < 0 || !in_array($status, $allowed_statuses, true) || !in_array($expense_category, $allowed_categories, true)) {
            $error = 'Please fill in all required vehicle expense fields.';
            $open_modal = $maintenance_action === 'create' ? 'addMaintenanceModal' : 'editMaintenanceModal' . $record_id;
        } else {
            $car_stmt = $conn->prepare("SELECT id FROM cars WHERE id = ?");
            $car_stmt->bind_param("i", $car_id);
            $car_stmt->execute();
            $car_exists = $car_stmt->get_result()->num_rows === 1;
            $car_stmt->close();

            if (!$car_exists) {
                $error = 'Selected car was not found.';
                $open_modal = $maintenance_action === 'create' ? 'addMaintenanceModal' : 'editMaintenanceModal' . $record_id;
            } elseif ($maintenance_action === 'create') {
                $receipt_photo = uploadMaintenanceReceipt('receipt_photo');
                $stmt = $conn->prepare("INSERT INTO maintenance_records (car_id, expense_category, service_date, service_type, vendor, cost, status, receipt_photo, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issssdsss", $car_id, $expense_category, $service_date, $service_type, $vendor, $cost, $status, $receipt_photo, $notes);

                if ($stmt->execute()) {
                    updateCarMaintenanceStatus($conn, $car_id);
                    header('Location: maintenance.php');
                    exit();
                }

                $error = 'Unable to add vehicle expense record. Please try again.';
                $open_modal = 'addMaintenanceModal';
                $stmt->close();
            } else {
                $old_stmt = $conn->prepare("SELECT car_id, receipt_photo FROM maintenance_records WHERE id = ?");
                $old_stmt->bind_param("i", $record_id);
                $old_stmt->execute();
                $old_result = $old_stmt->get_result();

                if ($old_result->num_rows === 0) {
                    $error = 'Selected vehicle expense record was not found.';
                    $open_modal = 'editMaintenanceModal' . $record_id;
                } else {
                    $old_record = $old_result->fetch_assoc();
                    $old_car_id = intval($old_record['car_id']);
                    $receipt_photo = uploadMaintenanceReceipt('receipt_photo');

                    if ($receipt_photo) {
                        deleteMaintenanceReceiptFile($old_record['receipt_photo'] ?? '');
                        $stmt = $conn->prepare("UPDATE maintenance_records SET car_id = ?, expense_category = ?, service_date = ?, service_type = ?, vendor = ?, cost = ?, status = ?, receipt_photo = ?, notes = ? WHERE id = ?");
                        $stmt->bind_param("issssdsssi", $car_id, $expense_category, $service_date, $service_type, $vendor, $cost, $status, $receipt_photo, $notes, $record_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE maintenance_records SET car_id = ?, expense_category = ?, service_date = ?, service_type = ?, vendor = ?, cost = ?, status = ?, notes = ? WHERE id = ?");
                        $stmt->bind_param("isssdssi", $car_id, $expense_category, $service_date, $service_type, $vendor, $cost, $status, $notes, $record_id);
                    }

                    if ($stmt->execute()) {
                        updateCarMaintenanceStatus($conn, $old_car_id);
                        updateCarMaintenanceStatus($conn, $car_id);
                        header('Location: maintenance.php');
                        exit();
                    }

                    $error = 'Unable to update vehicle expense record. Please try again.';
                    $open_modal = 'editMaintenanceModal' . $record_id;
                    $stmt->close();
                }

                $old_stmt->close();
            }
        }
    } elseif ($maintenance_action === 'duplicate') {
        $record_id = intval($_POST['record_id'] ?? 0);
        $stmt = $conn->prepare("SELECT car_id, expense_category, service_date, service_type, vendor, cost, status, notes FROM maintenance_records WHERE id = ?");
        $stmt->bind_param("i", $record_id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($record) {
            $duplicate_car_id = intval($record['car_id']);
            $duplicate_expense_category = $record['expense_category'];
            $duplicate_service_date = $record['service_date'];
            $duplicate_service_type = $record['service_type'];
            $duplicate_vendor = $record['vendor'];
            $duplicate_cost = floatval($record['cost']);
            $duplicate_status = $record['status'];
            $receipt_photo = '';
            $duplicate_notes = $record['notes'];
            $insert_stmt = $conn->prepare("INSERT INTO maintenance_records (car_id, expense_category, service_date, service_type, vendor, cost, status, receipt_photo, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->bind_param(
                "issssdsss",
                $duplicate_car_id,
                $duplicate_expense_category,
                $duplicate_service_date,
                $duplicate_service_type,
                $duplicate_vendor,
                $duplicate_cost,
                $duplicate_status,
                $receipt_photo,
                $duplicate_notes
            );
            $insert_stmt->execute();
            $insert_stmt->close();
            updateCarMaintenanceStatus($conn, $duplicate_car_id);
        }

        header('Location: maintenance.php');
        exit();
    } elseif ($maintenance_action === 'upload_receipt') {
        $record_id = intval($_POST['record_id'] ?? 0);
        $stmt = $conn->prepare("SELECT receipt_photo FROM maintenance_records WHERE id = ? AND status = 'completed'");
        $stmt->bind_param("i", $record_id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($record) {
            $receipt_photo = uploadMaintenanceReceipt('receipt_photo');
            if ($receipt_photo) {
                deleteMaintenanceReceiptFile($record['receipt_photo'] ?? '');
                $update_stmt = $conn->prepare("UPDATE maintenance_records SET receipt_photo = ? WHERE id = ?");
                $update_stmt->bind_param("si", $receipt_photo, $record_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
        }

        header('Location: maintenance.php');
        exit();
    }

    if ($maintenance_action === 'delete') {
        $record_id = intval($_POST['record_id'] ?? 0);
        $old_stmt = $conn->prepare("SELECT car_id, receipt_photo FROM maintenance_records WHERE id = ?");
        $old_stmt->bind_param("i", $record_id);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result();

        if ($old_result->num_rows === 1) {
            $old_record = $old_result->fetch_assoc();
            $car_id = intval($old_record['car_id']);
            $delete_stmt = $conn->prepare("DELETE FROM maintenance_records WHERE id = ?");
            $delete_stmt->bind_param("i", $record_id);
            $delete_stmt->execute();
            $delete_stmt->close();
            deleteMaintenanceReceiptFile($old_record['receipt_photo'] ?? '');
            updateCarMaintenanceStatus($conn, $car_id);
        }

        $old_stmt->close();
        header('Location: maintenance.php');
        exit();
    }
}

$cars_result = $conn->query("SELECT c.id, c.brand, c.model, c.plate_number, c.status, u.company_name, u.full_name
                             FROM cars c
                             JOIN users u ON c.user_id = u.id
                             ORDER BY c.plate_number");
$car_rows = [];
while ($car = $cars_result->fetch_assoc()) {
    $car_rows[] = $car;
}

$records_result = $conn->query("SELECT mr.*, c.brand, c.model, c.plate_number, u.company_name, u.full_name
                                FROM maintenance_records mr
                                JOIN cars c ON mr.car_id = c.id
                                JOIN users u ON c.user_id = u.id
                                ORDER BY mr.service_date DESC, mr.created_at DESC");
$records = [];
while ($record = $records_result->fetch_assoc()) {
    $records[] = $record;
}

$total_spent = $conn->query("SELECT COALESCE(SUM(cost), 0) AS total FROM maintenance_records")->fetch_assoc()['total'] ?? 0;
$maintenance_spent = $conn->query("SELECT COALESCE(SUM(cost), 0) AS total FROM maintenance_records WHERE expense_category IN ('maintenance', 'repair', 'parts_replacement', 'inspection')")->fetch_assoc()['total'] ?? 0;
$loan_spent = $conn->query("SELECT COALESCE(SUM(cost), 0) AS total FROM maintenance_records WHERE expense_category = 'loan_installment'")->fetch_assoc()['total'] ?? 0;
$active_count = $conn->query("SELECT COUNT(*) AS count FROM maintenance_records WHERE status IN ('sent', 'in_progress')")->fetch_assoc()['count'] ?? 0;
$completed_count = $conn->query("SELECT COUNT(*) AS count FROM maintenance_records WHERE status = 'completed'")->fetch_assoc()['count'] ?? 0;

include 'includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2>Vehicle Expenses</h2>
        <p class="text-muted">Track maintenance, accessories, bank installments, and receipts</p>
    </div>
    <div class="col-md-6 text-md-end">
        <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
            <i class="bi bi-plus-circle me-2"></i>Add Expense
        </button>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-1">Total Vehicle Expenses</p>
                <h3 class="mb-0"><?php echo e(formatCurrency($total_spent)); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-1">Maintenance / Repairs</p>
                <h3 class="mb-0"><?php echo e(formatCurrency($maintenance_spent)); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-1">Loan Installments</p>
                <h3 class="mb-0"><?php echo e(formatCurrency($loan_spent)); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-1">Active / Completed</p>
                <h3 class="mb-0"><?php echo e($active_count . ' / ' . $completed_count); ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <?php if (count($records) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Car</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Vendor</th>
                        <th>Amount</th>
                        <th>Receipt</th>
                        <th>Status</th>
                        <th>Owner</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?php echo e(formatDate($record['service_date'])); ?></td>
                        <td>
                            <strong><?php echo e($record['plate_number']); ?></strong><br>
                            <small class="text-muted"><?php echo e($record['brand'] . ' ' . $record['model']); ?></small>
                        </td>
                        <td><span class="badge <?php echo e(maintenanceCategoryClass($record['expense_category'] ?? 'maintenance')); ?>"><?php echo e(maintenanceCategoryLabel($record['expense_category'] ?? 'maintenance')); ?></span></td>
                        <td><?php echo e($record['service_type']); ?></td>
                        <td><?php echo e($record['vendor'] ?: 'N/A'); ?></td>
                        <td><strong><?php echo e(formatCurrency($record['cost'])); ?></strong></td>
                        <td>
                            <?php if (!empty($record['receipt_photo'])): ?>
                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#receiptModal<?php echo e($record['id']); ?>">
                                <i class="bi bi-receipt me-1"></i>Receipt
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo e(maintenanceStatusClass($record['status'])); ?>"><?php echo e(maintenanceStatusLabel($record['status'])); ?></span></td>
                        <td><?php echo e($record['company_name'] ?: $record['full_name']); ?></td>
                        <td>
                            <?php if ($record['status'] === 'completed' && empty($record['receipt_photo'])): ?>
                            <form method="POST" action="maintenance.php" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 mb-2">
                                <input type="hidden" name="maintenance_action" value="upload_receipt">
                                <input type="hidden" name="record_id" value="<?php echo e($record['id']); ?>">
                                <input type="file" name="receipt_photo" accept="image/*,.pdf" class="form-control form-control-sm" style="max-width: 220px;" required>
                                <button type="submit" class="btn btn-sm btn-info">Upload Receipt</button>
                            </form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#editMaintenanceModal<?php echo e($record['id']); ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#duplicateMaintenanceModal<?php echo e($record['id']); ?>">
                                <i class="bi bi-copy"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteMaintenanceModal<?php echo e($record['id']); ?>">
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
            <i class="bi bi-tools fs-1 text-muted"></i>
            <p class="text-muted mt-3">No vehicle expense records found.</p>
            <button type="button" class="btn btn-dark mt-2" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
                <i class="bi bi-plus-circle me-2"></i>Add Expense
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addMaintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" action="maintenance.php" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="maintenance_action" value="create">
            <div class="modal-header">
                <h5 class="modal-title">Add Vehicle Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php $maintenance_form = ['car_id' => '', 'expense_category' => 'maintenance', 'service_date' => date('Y-m-d'), 'service_type' => '', 'vendor' => '', 'cost' => '', 'status' => 'completed', 'receipt_photo' => '', 'notes' => '']; ?>
                <?php include __DIR__ . '/includes/maintenance_form_fields.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark">
                    <i class="bi bi-plus-circle me-2"></i>Add Expense
                </button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($records as $record): ?>
<div class="modal fade" id="editMaintenanceModal<?php echo e($record['id']); ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" action="maintenance.php" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="maintenance_action" value="update">
            <input type="hidden" name="record_id" value="<?php echo e($record['id']); ?>">
            <div class="modal-header">
                <h5 class="modal-title">Edit Vehicle Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php $maintenance_form = $record; ?>
                <?php include __DIR__ . '/includes/maintenance_form_fields.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark">
                    <i class="bi bi-check-circle me-2"></i>Update Record
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="duplicateMaintenanceModal<?php echo e($record['id']); ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="maintenance.php" class="modal-content">
            <input type="hidden" name="maintenance_action" value="duplicate">
            <input type="hidden" name="record_id" value="<?php echo e($record['id']); ?>">
            <div class="modal-header">
                <h5 class="modal-title">Duplicate Vehicle Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Create a copy of this record?</p>
                <div class="small text-muted">
                    <strong><?php echo e($record['plate_number']); ?></strong> -
                    <?php echo e(maintenanceCategoryLabel($record['expense_category'] ?? 'maintenance')); ?> -
                    <?php echo e($record['service_type']); ?> -
                    <?php echo e(formatCurrency($record['cost'])); ?>
                </div>
                <p class="small text-muted mb-0 mt-2">Receipt/proof will not be copied to avoid sharing the same file between records.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark">
                    <i class="bi bi-copy me-2"></i>Duplicate
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="deleteMaintenanceModal<?php echo e($record['id']); ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="maintenance.php" class="modal-content">
            <input type="hidden" name="maintenance_action" value="delete">
            <input type="hidden" name="record_id" value="<?php echo e($record['id']); ?>">
            <div class="modal-header">
                <h5 class="modal-title">Delete Vehicle Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Delete vehicle expense for <strong><?php echo e($record['plate_number']); ?></strong> on <strong><?php echo e(formatDate($record['service_date'])); ?></strong>?</p>
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

<?php foreach ($records as $record): ?>
<?php if (!empty($record['receipt_photo'])): ?>
<?php
$receipt_path = 'uploads/receipts/' . $record['receipt_photo'];
$receipt_ext = strtolower(pathinfo($record['receipt_photo'], PATHINFO_EXTENSION));
?>
<div class="modal fade" id="receiptModal<?php echo e($record['id']); ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Vehicle Expense Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($receipt_ext === 'pdf'): ?>
                <iframe src="<?php echo e($receipt_path); ?>" class="w-100 border rounded" style="height: 70vh;"></iframe>
                <?php else: ?>
                <img src="<?php echo e($receipt_path); ?>" alt="Maintenance receipt" class="img-fluid rounded border d-block mx-auto">
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <a href="<?php echo e($receipt_path); ?>" target="_blank" class="btn btn-dark">
                    <i class="bi bi-box-arrow-up-right me-2"></i>Open File
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($open_modal): ?>
    new bootstrap.Modal(document.getElementById('<?php echo e($open_modal); ?>')).show();
    <?php endif; ?>
});
</script>

<?php
closeDBConnection($conn);
include 'includes/footer.php';
?>
