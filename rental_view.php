<?php
require_once 'config/config.php';
requireAdmin();

$conn = getDBConnection();
ensureRentalSchema($conn);

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function refreshRentalPaymentStatusLocal($conn, $rental_id) {
    $conn->query("UPDATE rental_payment_records
                  SET status = IF(amount_paid >= GREATEST(amount_due - waived_amount, 0), 'paid', 'pending')
                  WHERE rental_id = " . intval($rental_id));

    $summary = $conn->query("SELECT COALESCE(SUM(GREATEST(amount_due - waived_amount, 0)), 0) AS total_amount,
                                    COALESCE(SUM(amount_paid), 0) AS total_paid,
                                    COUNT(*) AS record_count
                             FROM rental_payment_records
                             WHERE rental_id = " . intval($rental_id))->fetch_assoc();
    $total_amount = floatval($summary['total_amount']);
    $total_paid = floatval($summary['total_paid']);
    $record_count = intval($summary['record_count'] ?? 0);
    $payment_status = 'pending';
    if ($record_count > 0 && $total_amount <= 0) {
        $payment_status = 'paid';
    } elseif ($total_paid >= $total_amount && $total_amount > 0) {
        $payment_status = 'paid';
    } elseif ($total_paid > 0) {
        $payment_status = 'partial';
    }

    $stmt = $conn->prepare("UPDATE rentals SET total_amount = ?, total_paid = ?, payment_status = ? WHERE id = ?");
    $stmt->bind_param("ddsi", $total_amount, $total_paid, $payment_status, $rental_id);
    $stmt->execute();
    $stmt->close();
}

function customerPaymentStatusLabel($status) {
    if ($status == 'approved') {
        return 'Approved';
    }
    if ($status == 'rejected') {
        return 'Rejected';
    }
    if ($status == 'pending') {
        return 'Waiting Review';
    }
    return 'Not Submitted';
}

function customerPaymentStatusClass($status) {
    if ($status == 'approved') {
        return 'bg-success';
    }
    if ($status == 'rejected') {
        return 'bg-danger';
    }
    if ($status == 'pending') {
        return 'bg-warning text-dark';
    }
    return 'bg-secondary';
}

function paymentSubmissionStatusClass($status) {
    if ($status == 'approved') {
        return 'bg-success';
    }
    if ($status == 'rejected' || $status == 'void') {
        return 'bg-danger';
    }
    return 'bg-warning text-dark';
}

function paymentRecordPayableAmount($record) {
    return max(floatval($record['amount_due'] ?? 0) - floatval($record['waived_amount'] ?? 0), 0);
}

function paymentRecordBalanceAmount($record) {
    return max(paymentRecordPayableAmount($record) - floatval($record['amount_paid'] ?? 0), 0);
}

function waiverReasonLabel($reason) {
    $labels = [
        'sick' => 'Sick / Medical',
        'hospital' => 'Admitted Hospital',
        'vehicle_maintenance' => 'Vehicle Maintenance',
        'accident_breakdown' => 'Accident / Breakdown',
        'other' => 'Other',
    ];
    return $labels[$reason] ?? 'Other';
}

function waiverStatusClass($status) {
    if ($status == 'approved') {
        return 'bg-success';
    }
    if ($status == 'rejected') {
        return 'bg-danger';
    }
    return 'bg-warning text-dark';
}

$rental_id = intval($_GET['id'] ?? 0);
if ($rental_id <= 0) {
    header('Location: rentals.php');
    exit();
}

refreshRentalPaymentStatusLocal($conn, $rental_id);

$stmt = $conn->prepare("SELECT r.*, c.brand, c.model, c.plate_number, cu.full_name AS customer_name, cu.phone AS customer_phone, u.company_name, u.full_name AS agent_name
                        FROM rentals r
                        JOIN cars c ON r.car_id = c.id
                        JOIN customers cu ON r.customer_id = cu.id
                        JOIN users u ON r.user_id = u.id
                        WHERE r.id = ?");
$stmt->bind_param("i", $rental_id);
$stmt->execute();
$rental = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rental) {
    header('Location: rentals.php');
    exit();
}

$payment_records = [];
$record_ids = [];
$records_result = $conn->query("SELECT * FROM rental_payment_records WHERE rental_id = " . intval($rental_id) . " ORDER BY due_date ASC, id ASC");
while ($record = $records_result->fetch_assoc()) {
    $payment_records[] = $record;
    $record_ids[] = intval($record['id']);
}

$payment_submissions = [];
if (!empty($record_ids)) {
    $ids = implode(',', array_map('intval', $record_ids));
    $submissions = $conn->query("SELECT * FROM rental_payment_submissions WHERE payment_record_id IN ($ids) ORDER BY paid_date ASC, id ASC");
    while ($submission = $submissions->fetch_assoc()) {
        $payment_submissions[intval($submission['payment_record_id'])][] = $submission;
    }
}

$waiver_requests = [];
$waivers = $conn->query("SELECT wr.*, c.full_name AS customer_name
                         FROM rental_waiver_requests wr
                         JOIN customers c ON wr.customer_id = c.id
                         WHERE wr.rental_id = " . intval($rental_id) . "
                         ORDER BY wr.created_at DESC");
while ($waiver = $waivers->fetch_assoc()) {
    $waiver_requests[] = $waiver;
}

$page_title = 'Rental #' . str_pad($rental_id, 6, '0', STR_PAD_LEFT);
include 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <a href="rentals.php" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Rentals</a>
        <h2 class="mb-1">Rental #<?php echo str_pad($rental_id, 6, '0', STR_PAD_LEFT); ?></h2>
        <p class="text-muted mb-0"><?php echo e($rental['plate_number'] . ' - ' . $rental['brand'] . ' ' . $rental['model']); ?></p>
    </div>
    <span class="badge <?php echo $rental['status'] == 'active' ? 'bg-primary' : ($rental['status'] == 'completed' ? 'bg-success' : 'bg-danger'); ?> fs-6"><?php echo e(ucfirst($rental['status'])); ?></span>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><p class="text-muted mb-1">Customer</p><h6 class="mb-0"><?php echo e($rental['customer_name']); ?></h6><small class="text-muted"><?php echo e($rental['customer_phone']); ?></small></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><p class="text-muted mb-1">Agreement</p><h6 class="mb-0"><?php echo e(formatDate($rental['start_date']) . ' - ' . formatDate($rental['end_date'])); ?></h6><small class="text-muted"><?php echo e($rental['agreement_duration']); ?></small></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><p class="text-muted mb-1">Schedule</p><h6 class="mb-0"><?php echo e(paymentFrequencyLabel($rental['payment_frequency'])); ?></h6><small class="text-muted"><?php echo e(formatCurrency($rental['rate_amount'])); ?> each</small></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><p class="text-muted mb-1">Payment</p><h6 class="mb-0"><?php echo e(formatCurrency($rental['total_paid'])); ?> / <?php echo e(formatCurrency($rental['total_amount'])); ?></h6><small class="text-muted"><?php echo e(ucfirst($rental['payment_status'])); ?></small></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h5 class="mb-0">Payment Schedule</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Due Date</th>
                        <th>Amount Due</th>
                        <th>Paid / Balance</th>
                        <th>Receipt</th>
                        <th>Customer Proof</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payment_records as $record): ?>
                    <?php $payable_amount = paymentRecordPayableAmount($record); ?>
                    <?php $balance_amount = paymentRecordBalanceAmount($record); ?>
                    <?php $record_submissions = $payment_submissions[intval($record['id'])] ?? []; ?>
                    <tr>
                        <td><?php echo e(formatDate($record['period_start']) . ' - ' . formatDate($record['period_end'])); ?></td>
                        <td><?php echo e(formatDate($record['due_date'])); ?></td>
                        <td>
                            <?php echo e(formatCurrency($record['amount_due'])); ?>
                            <?php if (floatval($record['waived_amount'] ?? 0) > 0): ?>
                            <br><small class="text-success">Waived <?php echo e(formatCurrency($record['waived_amount'])); ?><?php echo intval($record['waived_days'] ?? 0) > 0 ? ' (' . intval($record['waived_days']) . ' days)' : ''; ?></small>
                            <br><small class="text-muted">Payable <?php echo e(formatCurrency($payable_amount)); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo floatval($record['amount_paid']) > 0 ? e(formatCurrency($record['amount_paid'])) : '-'; ?>
                            <?php if (!empty($record['paid_date'])): ?><br><small class="text-muted">Last paid <?php echo e(formatDate($record['paid_date'])); ?></small><?php endif; ?>
                            <?php if ($balance_amount > 0): ?><br><small class="text-danger">Balance <?php echo e(formatCurrency($balance_amount)); ?></small><?php endif; ?>
                            <?php if (count($record_submissions) > 0): ?>
                            <div class="mt-2 small">
                                <strong>Split payments</strong>
                                <?php foreach ($record_submissions as $submission): ?>
                                <div class="border-top pt-1 mt-1">
                                    <?php echo e(formatCurrency($submission['amount_paid'])); ?>
                                    <span class="text-muted">on <?php echo e(formatDate($submission['paid_date'])); ?></span>
                                    <span class="badge <?php echo e(paymentSubmissionStatusClass($submission['status'])); ?>"><?php echo e(ucfirst($submission['status'])); ?></span>
                                    <span class="text-muted"><?php echo e(ucfirst($submission['source'])); ?></span>
                                    <?php if (!empty($submission['receipt_photo'])): ?><a href="<?php echo e('uploads/receipts/' . $submission['receipt_photo']); ?>" target="_blank" class="ms-1">Receipt</a><?php endif; ?>
                                    <?php if (in_array($submission['status'], ['void', 'rejected'], true)): ?>
                                    <form method="POST" action="rentals.php" class="d-inline">
                                        <input type="hidden" name="rental_action" value="remove_payment_submission">
                                        <input type="hidden" name="rental_id" value="<?php echo $rental_id; ?>">
                                        <input type="hidden" name="submission_id" value="<?php echo e($submission['id']); ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-danger p-0 ms-1 align-baseline">Remove</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!empty($submission['admin_notes'])): ?><br><span class="text-muted"><?php echo e($submission['admin_notes']); ?></span><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($record['receipt_photo'])): ?>
                            <a href="<?php echo e('uploads/receipts/' . $record['receipt_photo']); ?>" target="_blank" class="btn btn-sm btn-info"><i class="bi bi-receipt me-1"></i>Receipt</a>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo e(customerPaymentStatusClass($record['customer_payment_status'] ?? 'none')); ?>"><?php echo e(customerPaymentStatusLabel($record['customer_payment_status'] ?? 'none')); ?></span>
                            <?php if (!empty($record['customer_receipt_photo'])): ?>
                            <br><a href="<?php echo e('uploads/receipts/' . $record['customer_receipt_photo']); ?>" target="_blank" class="btn btn-sm btn-outline-info mt-1"><i class="bi bi-receipt me-1"></i>Proof</a>
                            <div class="small text-muted mt-1"><?php echo e(formatCurrency($record['customer_amount_paid'])); ?><?php if (!empty($record['customer_paid_date'])): ?> on <?php echo e(formatDate($record['customer_paid_date'])); ?><?php endif; ?></div>
                            <?php endif; ?>
                            <?php if (($record['customer_payment_status'] ?? '') == 'rejected'): ?>
                            <form method="POST" action="rentals.php" class="mt-2">
                                <input type="hidden" name="rental_action" value="remove_rejected_customer_payment">
                                <input type="hidden" name="rental_id" value="<?php echo $rental_id; ?>">
                                <input type="hidden" name="record_id" value="<?php echo e($record['id']); ?>">
                                <button type="submit" class="btn btn-link btn-sm text-danger p-0 align-baseline">Remove Rejected</button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $record['status'] == 'paid' ? 'bg-success' : (floatval($record['amount_paid']) > 0 ? 'bg-warning text-dark' : 'bg-danger'); ?>"><?php echo $record['status'] == 'paid' ? 'Paid' : (floatval($record['amount_paid']) > 0 ? 'Partial' : 'Pending'); ?></span></td>
                        <td>
                            <?php if ($record['status'] == 'pending' && ($record['customer_payment_status'] ?? '') == 'pending'): ?>
                            <form method="POST" action="rentals.php" class="d-flex flex-wrap gap-2 mb-2">
                                <input type="hidden" name="rental_action" value="review_customer_payment">
                                <input type="hidden" name="rental_id" value="<?php echo $rental_id; ?>">
                                <input type="hidden" name="record_id" value="<?php echo e($record['id']); ?>">
                                <select name="review_status" class="form-select form-select-sm" style="max-width: 120px;">
                                    <option value="approved">Approve</option>
                                    <option value="rejected">Reject</option>
                                </select>
                                <input type="text" name="admin_payment_notes" class="form-control form-control-sm" placeholder="Notes" style="max-width: 170px;">
                                <button type="submit" class="btn btn-sm btn-dark">Save</button>
                            </form>
                            <?php endif; ?>

                            <?php if ($record['status'] == 'paid'): ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if (empty($record['receipt_photo'])): ?>
                                <form method="POST" action="rentals.php" enctype="multipart/form-data" class="d-flex flex-wrap gap-2">
                                    <input type="hidden" name="rental_action" value="upload_receipt">
                                    <input type="hidden" name="rental_id" value="<?php echo $rental_id; ?>">
                                    <input type="hidden" name="record_id" value="<?php echo e($record['id']); ?>">
                                    <input type="file" name="receipt_photo" accept="image/*,.pdf" class="form-control form-control-sm" style="max-width: 220px;" required>
                                    <button type="submit" class="btn btn-sm btn-info">Upload Receipt</button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" action="rentals.php">
                                    <input type="hidden" name="rental_action" value="mark_pending">
                                    <input type="hidden" name="rental_id" value="<?php echo $rental_id; ?>">
                                    <input type="hidden" name="record_id" value="<?php echo e($record['id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-warning">Mark Pending</button>
                                </form>
                            </div>
                            <?php else: ?>
                            <form method="POST" action="rentals.php" class="d-flex flex-wrap gap-2">
                                <input type="hidden" name="rental_action" value="mark_paid">
                                <input type="hidden" name="rental_id" value="<?php echo $rental_id; ?>">
                                <input type="hidden" name="record_id" value="<?php echo e($record['id']); ?>">
                                <input type="date" name="paid_date" value="<?php echo e(date('Y-m-d')); ?>" class="form-control form-control-sm" style="max-width: 150px;">
                                <input type="number" name="amount_paid" value="<?php echo e($balance_amount); ?>" step="0.01" min="0.01" max="<?php echo e($balance_amount); ?>" class="form-control form-control-sm" style="max-width: 120px;">
                                <button type="submit" class="btn btn-sm btn-success">Save Payment</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h5 class="mb-0">Waiver / Appeal Requests</h5></div>
    <div class="card-body">
        <?php if (count($waiver_requests) > 0): ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Date Range</th><th>Reason</th><th>Proof</th><th>Approved Waiver</th><th>Status</th><th>Review</th></tr></thead>
                <tbody>
                    <?php foreach ($waiver_requests as $waiver): ?>
                    <tr>
                        <td><?php echo e(formatDate($waiver['request_start_date']) . ' - ' . formatDate($waiver['request_end_date'])); ?></td>
                        <td><?php echo e(waiverReasonLabel($waiver['reason'])); ?><br><small class="text-muted"><?php echo e($waiver['notes'] ?? ''); ?></small></td>
                        <td><?php if (!empty($waiver['proof_photo'])): ?><a href="<?php echo e('uploads/receipts/' . $waiver['proof_photo']); ?>" target="_blank" class="btn btn-sm btn-outline-info"><i class="bi bi-file-earmark-text me-1"></i>Proof</a><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                        <td><?php echo e(formatCurrency($waiver['approved_waived_amount'])); ?><br><small class="text-muted"><?php echo intval($waiver['approved_waived_days']); ?> days</small></td>
                        <td><span class="badge <?php echo e(waiverStatusClass($waiver['status'])); ?>"><?php echo e(ucfirst($waiver['status'])); ?></span></td>
                        <td>
                            <?php if ($waiver['status'] == 'pending'): ?>
                            <form method="POST" action="rentals.php" class="d-flex flex-wrap gap-2">
                                <input type="hidden" name="rental_action" value="review_waiver">
                                <input type="hidden" name="waiver_id" value="<?php echo e($waiver['id']); ?>">
                                <select name="review_status" class="form-select form-select-sm" style="max-width: 120px;">
                                    <option value="approved">Approve</option>
                                    <option value="rejected">Reject</option>
                                </select>
                                <input type="text" name="admin_notes" class="form-control form-control-sm" placeholder="Notes" style="max-width: 170px;">
                                <button type="submit" class="btn btn-sm btn-dark">Save</button>
                            </form>
                            <?php else: ?>
                            <small class="text-muted"><?php echo e($waiver['admin_notes'] ?? ''); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted mb-0">No waiver requests for this rental.</p>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
