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

function paymentProofStatusLabel($status) {
    if ($status === 'approved') {
        return 'Approved';
    }
    if ($status === 'rejected') {
        return 'Rejected';
    }
    if ($status === 'pending') {
        return 'Waiting Review';
    }
    return 'Not Submitted';
}

function paymentProofStatusClass($status) {
    if ($status === 'approved') {
        return 'bg-success';
    }
    if ($status === 'rejected') {
        return 'bg-danger';
    }
    if ($status === 'pending') {
        return 'bg-warning text-dark';
    }
    return 'bg-secondary';
}

function paymentRecordPayableAmount($record) {
    return max(floatval($record['amount_due'] ?? 0) - floatval($record['waived_amount'] ?? 0), 0);
}

function paymentRecordBalanceAmount($record) {
    return max(paymentRecordPayableAmount($record) - floatval($record['amount_paid'] ?? 0), 0);
}

function recordRentalPaymentSubmission($conn, $rental_id, $record_id, $paid_date, $amount_paid, $receipt_photo, $notes = '') {
    $replace_stmt = $conn->prepare("UPDATE rental_payment_submissions
                                    SET status = 'void', admin_notes = 'Replaced by newer customer submission'
                                    WHERE payment_record_id = ?
                                      AND status = 'pending'");
    $replace_stmt->bind_param("i", $record_id);
    $replace_stmt->execute();
    $replace_stmt->close();

    $stmt = $conn->prepare("INSERT INTO rental_payment_submissions
                            (rental_id, payment_record_id, paid_date, amount_paid, receipt_photo, source, status, notes)
                            VALUES (?, ?, ?, ?, ?, 'customer', 'pending', ?)");
    $stmt->bind_param("iisdss", $rental_id, $record_id, $paid_date, $amount_paid, $receipt_photo, $notes);
    $stmt->execute();
    $stmt->close();
}

function waiverReasonOptions() {
    return [
        'sick' => 'Sick / Medical',
        'hospital' => 'Admitted Hospital',
        'vehicle_maintenance' => 'Vehicle Maintenance',
        'accident_breakdown' => 'Accident / Breakdown',
        'other' => 'Other',
    ];
}

function waiverReasonLabel($reason) {
    $reasons = waiverReasonOptions();
    return $reasons[$reason] ?? 'Other';
}

function waiverStatusClass($status) {
    if ($status === 'approved') {
        return 'bg-success';
    }
    if ($status === 'rejected') {
        return 'bg-danger';
    }
    return 'bg-warning text-dark';
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

$normalize_stmt = $conn->prepare("UPDATE rental_payment_records pr
                                  JOIN rentals r ON pr.rental_id = r.id
                                  SET pr.status = IF(pr.amount_paid >= GREATEST(pr.amount_due - pr.waived_amount, 0), 'paid', 'pending')
                                  WHERE r.customer_id = ?");
$normalize_stmt->bind_param("i", $customer_id);
$normalize_stmt->execute();
$normalize_stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $portal_action = portalInput($_POST['portal_action'] ?? '');

    if ($portal_action === 'request_waiver') {
        $rental_id = intval($_POST['rental_id'] ?? 0);
        $request_start_date = portalInput($_POST['request_start_date'] ?? '');
        $request_end_date = portalInput($_POST['request_end_date'] ?? '');
        $reason = portalInput($_POST['reason'] ?? 'other');
        $notes = portalInput($_POST['notes'] ?? '');
        $allowed_reasons = array_keys(waiverReasonOptions());

        $rental_stmt = $conn->prepare("SELECT id FROM rentals WHERE id = ? AND customer_id = ? AND status = 'active'");
        $rental_stmt->bind_param("ii", $rental_id, $customer_id);
        $rental_stmt->execute();
        $rental = $rental_stmt->get_result()->fetch_assoc();
        $rental_stmt->close();

        if (!$rental || $request_start_date === '' || $request_end_date === '' || strtotime($request_end_date) < strtotime($request_start_date) || !in_array($reason, $allowed_reasons, true)) {
            $error = 'Please complete the waiver request with a valid date range.';
        } else {
            $proof_photo = uploadClaimReceipt('proof_photo');
            $stmt = $conn->prepare("INSERT INTO rental_waiver_requests (customer_id, rental_id, request_start_date, request_end_date, reason, notes, proof_photo) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisssss", $customer_id, $rental_id, $request_start_date, $request_end_date, $reason, $notes, $proof_photo);
            if ($stmt->execute()) {
                header('Location: customer_portal.php?token=' . urlencode($token) . '&waiver_submitted=1');
                exit();
            }
            $error = 'Unable to submit waiver request. Please try again.';
            $stmt->close();
        }
    } elseif ($portal_action === 'upload_payment_receipt') {
        $record_id = intval($_POST['record_id'] ?? 0);
        $paid_date = portalInput($_POST['paid_date'] ?? date('Y-m-d'));
        $amount_paid = floatval($_POST['amount_paid'] ?? 0);
        $payment_notes = portalInput($_POST['payment_notes'] ?? '');

        $record_stmt = $conn->prepare("SELECT pr.id, pr.rental_id, pr.amount_due, pr.amount_paid, pr.waived_amount, pr.customer_receipt_photo
                                       FROM rental_payment_records pr
                                       JOIN rentals r ON pr.rental_id = r.id
                                       WHERE pr.id = ?
                                         AND r.customer_id = ?
                                         AND pr.status = 'pending'");
        $record_stmt->bind_param("ii", $record_id, $customer_id);
        $record_stmt->execute();
        $payment_record = $record_stmt->get_result()->fetch_assoc();
        $record_stmt->close();

        if (!$payment_record || $paid_date === '') {
            $error = 'Selected unpaid record was not found.';
        } else {
            $balance_amount = paymentRecordBalanceAmount($payment_record);
            if ($amount_paid <= 0) {
                $amount_paid = $balance_amount;
            }
            $amount_paid = min($amount_paid, $balance_amount);

            $uploaded_receipt_photo = uploadClaimReceipt('receipt_photo');
            $receipt_was_selected = !empty($_FILES['receipt_photo']['name']);
            $receipt_photo = $uploaded_receipt_photo !== '' ? $uploaded_receipt_photo : ($payment_record['customer_receipt_photo'] ?? '');
            if ($amount_paid <= 0) {
                $error = 'This rental payment record has no outstanding balance.';
            } elseif ($receipt_was_selected && $uploaded_receipt_photo === '') {
                $error = 'Please upload a valid receipt file.';
            } else {
                $stmt = $conn->prepare("UPDATE rental_payment_records
                                        SET customer_receipt_photo = ?,
                                            customer_paid_date = ?,
                                            customer_amount_paid = ?,
                                            customer_payment_status = 'pending',
                                            customer_payment_notes = ?,
                                            admin_payment_notes = NULL
                                        WHERE id = ?");
                $stmt->bind_param("ssdsi", $receipt_photo, $paid_date, $amount_paid, $payment_notes, $record_id);
                $stmt->execute();
                $stmt->close();
                recordRentalPaymentSubmission($conn, intval($payment_record['rental_id']), $record_id, $paid_date, $amount_paid, $receipt_photo, $payment_notes);

                header('Location: customer_portal.php?token=' . urlencode($token) . '&payment_submitted=1');
                exit();
            }
        }
    } elseif ($portal_action === 'submit_claim') {
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
if (isset($_GET['payment_submitted'])) {
    $success = 'Payment update submitted. The office will review it.';
}
if (isset($_GET['waiver_submitted'])) {
    $success = 'Waiver request submitted. The office will review it.';
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

$waiver_requests = [];
$waiver_stmt = $conn->prepare("SELECT wr.*, c.brand, c.model, c.plate_number
                               FROM rental_waiver_requests wr
                               JOIN rentals r ON wr.rental_id = r.id
                               JOIN cars c ON r.car_id = c.id
                               WHERE wr.customer_id = ?
                               ORDER BY wr.created_at DESC");
$waiver_stmt->bind_param("i", $customer_id);
$waiver_stmt->execute();
$waiver_result = $waiver_stmt->get_result();
while ($waiver = $waiver_result->fetch_assoc()) {
    $waiver_requests[] = $waiver;
}
$waiver_stmt->close();

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
            <a href="login.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </nav>

    <main class="container py-4">
        <div class="row mb-4">
            <div class="col-md-8">
                <h2>Welcome, <?php echo e($customer['full_name']); ?></h2>
                <p class="text-muted mb-0">View unpaid rental records and submit maintenance claims.</p>
            </div>
            <div class="col-md-4 text-md-end">
                <button type="button" class="btn btn-outline-dark me-2" data-bs-toggle="modal" data-bs-target="#waiverModal">
                    <i class="bi bi-file-earmark-medical me-2"></i>Request Waiver
                </button>
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
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Waiver Requests</p>
                        <h3 class="mb-0"><?php echo e(count($waiver_requests)); ?></h3>
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
                                <th>Balance</th>
                                <th>Receipt Review</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unpaid_records as $record): ?>
                            <?php $payable_amount = paymentRecordPayableAmount($record); ?>
                            <?php $balance_amount = paymentRecordBalanceAmount($record); ?>
                            <tr>
                                <td><strong><?php echo e($record['plate_number']); ?></strong><br><small class="text-muted"><?php echo e($record['brand'] . ' ' . $record['model']); ?></small></td>
                                <td><?php echo e(formatDate($record['period_start'])); ?> - <?php echo e(formatDate($record['period_end'])); ?></td>
                                <td><?php echo e(formatDate($record['due_date'])); ?></td>
                                <td>
                                    <strong><?php echo e(formatCurrency($balance_amount)); ?></strong>
                                    <?php if (floatval($record['amount_paid'] ?? 0) > 0): ?>
                                    <br><small class="text-success">Paid <?php echo e(formatCurrency($record['amount_paid'])); ?></small>
                                    <?php endif; ?>
                                    <?php if (floatval($record['waived_amount'] ?? 0) > 0): ?>
                                    <br><small class="text-muted">Original <?php echo e(formatCurrency($record['amount_due'])); ?></small>
                                    <br><small class="text-success">Waived <?php echo e(formatCurrency($record['waived_amount'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?php echo e(paymentProofStatusClass($record['customer_payment_status'] ?? 'none')); ?>"><?php echo e(paymentProofStatusLabel($record['customer_payment_status'] ?? 'none')); ?></span></td>
                                <td>
                                    <span class="badge <?php echo floatval($record['amount_paid'] ?? 0) > 0 ? 'bg-warning text-dark' : 'bg-danger'; ?>">
                                        <?php echo floatval($record['amount_paid'] ?? 0) > 0 ? 'Partial' : 'Pending'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#paymentReceiptModal<?php echo e($record['id']); ?>">
                                        <i class="bi bi-pencil-square me-1"></i>Update Payment
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#waiverModal">
                                        <i class="bi bi-file-earmark-medical me-1"></i>Appeal
                                    </button>
                                </td>
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

        <?php foreach ($unpaid_records as $record): ?>
        <?php $payable_amount = paymentRecordPayableAmount($record); ?>
        <?php $balance_amount = paymentRecordBalanceAmount($record); ?>
        <div class="modal fade" id="paymentReceiptModal<?php echo e($record['id']); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="customer_portal.php" enctype="multipart/form-data" class="modal-content">
                    <input type="hidden" name="portal_action" value="upload_payment_receipt">
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">
                    <input type="hidden" name="record_id" value="<?php echo e($record['id']); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <p class="text-muted mb-1">Balance to Pay</p>
                            <h5><?php echo e(formatCurrency($balance_amount)); ?></h5>
                            <?php if (floatval($record['amount_paid'] ?? 0) > 0): ?>
                            <div class="small text-success">Already paid <?php echo e(formatCurrency($record['amount_paid'])); ?></div>
                            <?php endif; ?>
                            <?php if (floatval($record['waived_amount'] ?? 0) > 0): ?>
                            <div class="small text-muted">Original <?php echo e(formatCurrency($record['amount_due'])); ?>, waived <?php echo e(formatCurrency($record['waived_amount'])); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Paid Date <span class="text-danger">*</span></label>
                                <input type="date" name="paid_date" value="<?php echo e(date('Y-m-d')); ?>" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount Paid (RM) <span class="text-danger">*</span></label>
                                <input type="number" name="amount_paid" value="<?php echo e($balance_amount); ?>" step="0.01" min="0.01" max="<?php echo e($balance_amount); ?>" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Receipt</label>
                            <input type="file" name="receipt_photo" accept="image/*,.pdf" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="payment_notes" rows="3" class="form-control"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-dark">
                            <i class="bi bi-check-circle me-2"></i>Submit Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0">Waiver / Appeal Requests</h5>
            </div>
            <div class="card-body">
                <?php if (count($waiver_requests) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Car</th>
                                <th>Date Range</th>
                                <th>Reason</th>
                                <th>Approved Waiver</th>
                                <th>Proof</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($waiver_requests as $waiver): ?>
                            <tr>
                                <td><strong><?php echo e($waiver['plate_number']); ?></strong><br><small class="text-muted"><?php echo e($waiver['brand'] . ' ' . $waiver['model']); ?></small></td>
                                <td><?php echo e(formatDate($waiver['request_start_date'])); ?> - <?php echo e(formatDate($waiver['request_end_date'])); ?></td>
                                <td><?php echo e(waiverReasonLabel($waiver['reason'])); ?></td>
                                <td><?php echo e(formatCurrency($waiver['approved_waived_amount'])); ?><br><small class="text-muted"><?php echo e(intval($waiver['approved_waived_days'])); ?> days</small></td>
                                <td>
                                    <?php if (!empty($waiver['proof_photo'])): ?>
                                    <a href="<?php echo e('uploads/receipts/' . $waiver['proof_photo']); ?>" target="_blank" class="btn btn-sm btn-info">
                                        <i class="bi bi-file-earmark-text me-1"></i>Proof
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?php echo e(waiverStatusClass($waiver['status'])); ?>"><?php echo e(ucfirst($waiver['status'])); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-4 mb-0">No waiver requests submitted yet.</p>
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

    <div class="modal fade" id="waiverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form method="POST" action="customer_portal.php" enctype="multipart/form-data" class="modal-content">
                <input type="hidden" name="portal_action" value="request_waiver">
                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Request Payment Waiver</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (count($active_rentals) === 0): ?>
                    <div class="alert alert-warning mb-0">No active rental is available for waiver request.</div>
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
                            <label class="form-label">Reason <span class="text-danger">*</span></label>
                            <select class="form-select" name="reason" required>
                                <?php foreach (waiverReasonOptions() as $reason_key => $reason_label): ?>
                                <option value="<?php echo e($reason_key); ?>"><?php echo e($reason_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="request_start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="request_end_date" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Proof</label>
                        <input type="file" class="form-control" name="proof_photo" accept="image/*,.pdf">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason Details</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark" <?php echo count($active_rentals) === 0 ? 'disabled' : ''; ?>>
                        <i class="bi bi-send me-2"></i>Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>

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
