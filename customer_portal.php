<?php
require_once 'config/config.php';

$conn = getDBConnection();
ensureRentalSchema($conn);
ensureMaintenanceSchema($conn);
ensureCustomerPortalSchema($conn);

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function portalInput($value) {
    return strip_tags(trim((string) $value));
}

function claimCategoryOptions() {
    return [
        'maintenance' => 'Maintenance / Servicing',
        'cleaning' => 'Cleaning',
        'repair' => 'Repair',
        'parts_replacement' => 'Parts Replacement',
        'accessory' => 'Accessory / Upgrade',
        'inspection' => 'Inspection / Compliance',
        'other' => 'Other',
    ];
}

function claimCategoryLabel($category) {
    $categories = claimCategoryOptions();
    return $categories[$category] ?? 'Maintenance / Servicing';
}

function claimStatusClass($status) {
    if ($status === 'approved') {
        return 'bg-success';
    }
    if ($status === 'rejected') {
        return 'bg-danger';
    }
    return 'bg-warning text-dark';
}

function uploadClaimReceipt($field_name) {
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

    $receipt_photo = 'claim_receipt_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    move_uploaded_file($_FILES[$field_name]['tmp_name'], $upload_dir . $receipt_photo);
    return $receipt_photo;
}

$token = portalInput($_GET['token'] ?? $_POST['token'] ?? '');
$customer = null;
$error = '';
$success = '';

if ($token !== '') {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE access_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$customer) {
    closeDBConnection($conn);
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Customer Portal - <?php echo e(SITE_NAME); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body class="bg-light">
        <div class="container py-5">
            <div class="alert alert-danger">Invalid or expired customer portal link.</div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

$customer_id = intval($customer['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $portal_action = portalInput($_POST['portal_action'] ?? '');

    if ($portal_action === 'submit_claim') {
        $rental_id = intval($_POST['rental_id'] ?? 0);
        $claim_date = portalInput($_POST['claim_date'] ?? date('Y-m-d'));
        $expense_category = portalInput($_POST['expense_category'] ?? 'maintenance');
        $description = portalInput($_POST['description'] ?? '');
        $vendor = portalInput($_POST['vendor'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $allowed_categories = array_keys(claimCategoryOptions());

        $rental_stmt = $conn->prepare("SELECT r.id, r.car_id
                                       FROM rentals r
                                       WHERE r.id = ?
                                         AND r.customer_id = ?
                                         AND r.status = 'active'");
        $rental_stmt->bind_param("ii", $rental_id, $customer_id);
        $rental_stmt->execute();
        $rental = $rental_stmt->get_result()->fetch_assoc();
        $rental_stmt->close();

        if (!$rental || $claim_date === '' || $description === '' || $amount <= 0 || !in_array($expense_category, $allowed_categories, true)) {
            $error = 'Please complete the claim form with a valid active rental.';
        } else {
            $receipt_photo = uploadClaimReceipt('receipt_photo');
            $car_id = intval($rental['car_id']);
            $stmt = $conn->prepare("INSERT INTO maintenance_claims (customer_id, rental_id, car_id, claim_date, expense_category, description, vendor, amount, receipt_photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiissssds", $customer_id, $rental_id, $car_id, $claim_date, $expense_category, $description, $vendor, $amount, $receipt_photo);

            if ($stmt->execute()) {
                header('Location: customer_portal.php?token=' . urlencode($token) . '&submitted=1');
                exit();
            }

            $error = 'Unable to submit claim. Please try again.';
            $stmt->close();
        }
    }
}

if (isset($_GET['submitted'])) {
    $success = 'Claim submitted. The office will review it.';
}

$rentals = [];
$rental_stmt = $conn->prepare("SELECT r.*, c.brand, c.model, c.plate_number
                               FROM rentals r
                               JOIN cars c ON r.car_id = c.id
                               WHERE r.customer_id = ?
                               ORDER BY r.created_at DESC");
$rental_stmt->bind_param("i", $customer_id);
$rental_stmt->execute();
$rental_result = $rental_stmt->get_result();
while ($rental = $rental_result->fetch_assoc()) {
    $rentals[] = $rental;
}
$rental_stmt->close();

$unpaid_records = [];
$unpaid_stmt = $conn->prepare("SELECT pr.*, r.id AS rental_id, c.brand, c.model, c.plate_number
                               FROM rental_payment_records pr
                               JOIN rentals r ON pr.rental_id = r.id
                               JOIN cars c ON r.car_id = c.id
                               WHERE r.customer_id = ?
                                 AND pr.status = 'pending'
                               ORDER BY pr.due_date ASC, pr.id ASC");
$unpaid_stmt->bind_param("i", $customer_id);
$unpaid_stmt->execute();
$unpaid_result = $unpaid_stmt->get_result();
while ($record = $unpaid_result->fetch_assoc()) {
    $unpaid_records[] = $record;
}
$unpaid_stmt->close();

$claims = [];
$claim_stmt = $conn->prepare("SELECT mc.*, c.brand, c.model, c.plate_number
                              FROM maintenance_claims mc
                              JOIN cars c ON mc.car_id = c.id
                              WHERE mc.customer_id = ?
                              ORDER BY mc.created_at DESC");
$claim_stmt->bind_param("i", $customer_id);
$claim_stmt->execute();
$claim_result = $claim_stmt->get_result();
while ($claim = $claim_result->fetch_assoc()) {
    $claims[] = $claim;
}
$claim_stmt->close();

$active_rentals = array_filter($rentals, function($rental) {
    return $rental['status'] === 'active';
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Portal - <?php echo e(SITE_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark shadow-sm">
        <div class="container-fluid">
            <span class="navbar-brand"><i class="bi bi-car-front-fill me-2"></i>Customer Portal</span>
        </div>
    </nav>

    <main class="container py-4">
        <div class="row mb-4">
            <div class="col-md-8">
                <h2>Welcome, <?php echo e($customer['full_name']); ?></h2>
                <p class="text-muted mb-0">View unpaid rental records and submit maintenance claims.</p>
            </div>
            <div class="col-md-4 text-md-end">
                <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#claimModal">
                    <i class="bi bi-upload me-2"></i>Submit Claim
                </button>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?></div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Active Rentals</p>
                        <h3 class="mb-0"><?php echo e(count($active_rentals)); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Unpaid Records</p>
                        <h3 class="mb-0"><?php echo e(count($unpaid_records)); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Claims Submitted</p>
                        <h3 class="mb-0"><?php echo e(count($claims)); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0">Unpaid Records</h5>
            </div>
            <div class="card-body">
                <?php if (count($unpaid_records) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Car</th>
                                <th>Period</th>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unpaid_records as $record): ?>
                            <tr>
                                <td><strong><?php echo e($record['plate_number']); ?></strong><br><small class="text-muted"><?php echo e($record['brand'] . ' ' . $record['model']); ?></small></td>
                                <td><?php echo e(formatDate($record['period_start'])); ?> - <?php echo e(formatDate($record['period_end'])); ?></td>
                                <td><?php echo e(formatDate($record['due_date'])); ?></td>
                                <td><strong><?php echo e(formatCurrency($record['amount_due'])); ?></strong></td>
                                <td><span class="badge bg-danger">Pending</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-4 mb-0">No unpaid records found.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0">Maintenance Claims</h5>
            </div>
            <div class="card-body">
                <?php if (count($claims) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Car</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Receipt</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($claims as $claim): ?>
                            <tr>
                                <td><?php echo e(formatDate($claim['claim_date'])); ?></td>
                                <td><strong><?php echo e($claim['plate_number']); ?></strong><br><small class="text-muted"><?php echo e($claim['brand'] . ' ' . $claim['model']); ?></small></td>
                                <td><?php echo e(claimCategoryLabel($claim['expense_category'])); ?></td>
                                <td><?php echo e($claim['description']); ?></td>
                                <td><strong><?php echo e(formatCurrency($claim['amount'])); ?></strong></td>
                                <td>
                                    <?php if (!empty($claim['receipt_photo'])): ?>
                                    <a href="<?php echo e('uploads/receipts/' . $claim['receipt_photo']); ?>" target="_blank" class="btn btn-sm btn-info">
                                        <i class="bi bi-receipt me-1"></i>Receipt
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?php echo e(claimStatusClass($claim['status'])); ?>"><?php echo e(ucfirst($claim['status'])); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-4 mb-0">No claims submitted yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <div class="modal fade" id="claimModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form method="POST" action="customer_portal.php" enctype="multipart/form-data" class="modal-content">
                <input type="hidden" name="portal_action" value="submit_claim">
                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Submit Maintenance Claim</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (count($active_rentals) === 0): ?>
                    <div class="alert alert-warning mb-0">No active rental is available for claim submission.</div>
                    <?php else: ?>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Rental / Car <span class="text-danger">*</span></label>
                            <select class="form-select" name="rental_id" required>
                                <?php foreach ($active_rentals as $rental): ?>
                                <option value="<?php echo e($rental['id']); ?>">
                                    <?php echo e($rental['plate_number'] . ' - ' . $rental['brand'] . ' ' . $rental['model']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Claim Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="claim_date" value="<?php echo e(date('Y-m-d')); ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select class="form-select" name="expense_category" required>
                                <?php foreach (claimCategoryOptions() as $category_key => $category_label): ?>
                                <option value="<?php echo e($category_key); ?>"><?php echo e($category_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Workshop / Vendor</label>
                            <input type="text" class="form-control" name="vendor">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="description" placeholder="Change wiper, tyre repair, inspection" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Amount (RM) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" min="0.01" step="0.01" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Receipt / Proof</label>
                        <input type="file" class="form-control" name="receipt_photo" accept="image/*,.pdf">
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark" <?php echo count($active_rentals) === 0 ? 'disabled' : ''; ?>>
                        <i class="bi bi-upload me-2"></i>Submit Claim
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
closeDBConnection($conn);
?>
