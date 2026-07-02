<?php
require_once 'config/config.php';
requireAdmin();

$page_title = 'Rentals Management';
$conn = getDBConnection();
ensureRentalSchema($conn);
$user_id = intval($_SESSION['user_id']);
$form_error = '';
$open_modal = '';

function rentalRateForFrequency($car, $frequency) {
    $daily_rate = floatval($car['daily_rate']);
    if ($frequency == 'weekly') {
        return floatval($car['weekly_rate'] ?? 0) > 0 ? floatval($car['weekly_rate']) : $daily_rate * 7;
    }
    if ($frequency == 'monthly') {
        return floatval($car['monthly_rate'] ?? 0) > 0 ? floatval($car['monthly_rate']) : $daily_rate * 30;
    }
    return $daily_rate;
}

function rentalIntervalSpec($frequency) {
    if ($frequency == 'weekly') {
        return ['+7 days', '-1 day'];
    }
    if ($frequency == 'monthly') {
        return ['+1 month', '-1 day'];
    }
    return ['+1 day', ''];
}

function generatePaymentRecords($conn, $rental_id, $start_date, $end_date, $frequency, $amount_due, $skip_periods = []) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    [$advance, $back] = rentalIntervalSpec($frequency);
    $total_amount = 0;

    while ($start <= $end) {
        $period_start = clone $start;
        $period_end = clone $start;
        $period_end->modify($advance);
        if ($back) {
            $period_end->modify($back);
        }
        if ($period_end > $end) {
            $period_end = clone $end;
        }

        $period_start_sql = $period_start->format('Y-m-d');
        $period_end_sql = $period_end->format('Y-m-d');
        $due_date = $period_start_sql;
        $period_key = $period_start_sql . '|' . $period_end_sql;

        if (!isset($skip_periods[$period_key])) {
            $stmt = $conn->prepare("INSERT INTO rental_payment_records (rental_id, period_start, period_end, due_date, amount_due) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isssd", $rental_id, $period_start_sql, $period_end_sql, $due_date, $amount_due);
            $stmt->execute();
            $stmt->close();

            $total_amount += $amount_due;
        }
        $start = clone $period_end;
        $start->modify('+1 day');
    }

    return $total_amount;
}

function agreementEndAndLabel($start_date, $duration, $custom_end_date) {
    $start = new DateTime($start_date);
    $end = clone $start;
    $duration_label = '3 Months';

    if ($duration == '1_year') {
        $end->modify('+1 year -1 day');
        $duration_label = '1 Year';
    } elseif ($duration == '6_months') {
        $end->modify('+6 months -1 day');
        $duration_label = '6 Months';
    } elseif ($duration == 'custom') {
        if (!$custom_end_date || strtotime($custom_end_date) < strtotime($start_date)) {
            return [null, null, 'Custom end date must be after start date'];
        }
        $end = new DateTime($custom_end_date);
        $duration_label = 'Custom';
    } else {
        $end->modify('+3 months -1 day');
    }

    return [$end, $duration_label, ''];
}

function durationValueFromLabel($label) {
    if ($label == '1 Year') {
        return '1_year';
    }
    if ($label == '6 Months') {
        return '6_months';
    }
    if ($label == 'Custom') {
        return 'custom';
    }
    return '3_months';
}

function refreshRentalPaymentStatus($conn, $rental_id) {
    $summary = $conn->query("SELECT COALESCE(SUM(amount_due), 0) AS total_amount,
                                    COALESCE(SUM(amount_paid), 0) AS total_paid
                             FROM rental_payment_records
                             WHERE rental_id = " . intval($rental_id))->fetch_assoc();
    $total_amount = floatval($summary['total_amount']);
    $total_paid = floatval($summary['total_paid']);
    $payment_status = 'pending';
    if ($total_paid >= $total_amount && $total_amount > 0) {
        $payment_status = 'paid';
    } elseif ($total_paid > 0) {
        $payment_status = 'partial';
    }

    $stmt = $conn->prepare("UPDATE rentals SET total_amount = ?, total_paid = ?, payment_status = ? WHERE id = ?");
    $stmt->bind_param("ddsi", $total_amount, $total_paid, $payment_status, $rental_id);
    $stmt->execute();
    $stmt->close();
}

function uploadPaymentReceipt($field_name) {
    if (empty($_FILES[$field_name]['name'])) {
        return '';
    }

    $upload_dir = 'uploads/receipts/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($_FILES[$field_name]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_types)) {
        return '';
    }

    $receipt_photo = 'receipt_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    move_uploaded_file($_FILES[$field_name]['tmp_name'], $upload_dir . $receipt_photo);
    return $receipt_photo;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $rental_action = sanitize($_POST['rental_action'] ?? '');

    if ($rental_action == 'create') {
        $car_id = intval($_POST['car_id'] ?? 0);
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $start_date = sanitize($_POST['start_date'] ?? '');
        $duration = sanitize($_POST['agreement_duration'] ?? '3_months');
        $custom_end_date = sanitize($_POST['custom_end_date'] ?? '');
        $payment_frequency = sanitize($_POST['payment_frequency'] ?? 'monthly');
        $notes = sanitize($_POST['notes'] ?? '');

        $valid_frequencies = ['daily', 'weekly', 'monthly'];
        if (!$car_id || !$customer_id || !$start_date || !in_array($payment_frequency, $valid_frequencies)) {
            $form_error = 'Please fill in all required fields';
            $open_modal = 'addRentalModal';
        } else {
            [$end, $duration_label, $date_error] = agreementEndAndLabel($start_date, $duration, $custom_end_date);
            if ($date_error) {
                $form_error = $date_error;
                $open_modal = 'addRentalModal';
            }

            if (!$form_error) {
                $car_stmt = $conn->prepare("SELECT * FROM cars WHERE id = ? AND status = 'available'");
                $car_stmt->bind_param("i", $car_id);
                $car_stmt->execute();
                $car_result = $car_stmt->get_result();
                $customer_stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
                $customer_stmt->bind_param("i", $customer_id);
                $customer_stmt->execute();
                $customer_result = $customer_stmt->get_result();

                if ($car_result->num_rows == 0) {
                    $form_error = 'Selected car is not available';
                    $open_modal = 'addRentalModal';
                } elseif ($customer_result->num_rows == 0) {
                    $form_error = 'Selected customer not found';
                    $open_modal = 'addRentalModal';
                } else {
                    $car = $car_result->fetch_assoc();
                    $customer = $customer_result->fetch_assoc();
                    $rental_user_id = intval($car['user_id']);
                    if ($rental_user_id !== intval($customer['user_id'])) {
                        $form_error = 'Selected car and customer must belong to the same account';
                        $open_modal = 'addRentalModal';
                    } else {
                        $rate_amount = rentalRateForFrequency($car, $payment_frequency);
                        $end_date = $end->format('Y-m-d');
                        $total_days = $end->diff(new DateTime($start_date))->days + 1;
                        $daily_rate = floatval($car['daily_rate']);
                        $initial_total = 0;
                        $stmt = $conn->prepare("INSERT INTO rentals (user_id, car_id, customer_id, start_date, end_date, total_days, agreement_duration, payment_frequency, daily_rate, rate_amount, total_amount, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("iiississddds", $rental_user_id, $car_id, $customer_id, $start_date, $end_date, $total_days, $duration_label, $payment_frequency, $daily_rate, $rate_amount, $initial_total, $notes);

                        if ($stmt->execute()) {
                            $rental_id = $stmt->insert_id;
                            $total_amount = generatePaymentRecords($conn, $rental_id, $start_date, $end_date, $payment_frequency, $rate_amount);
                            $update_stmt = $conn->prepare("UPDATE rentals SET total_amount = ? WHERE id = ?");
                            $update_stmt->bind_param("di", $total_amount, $rental_id);
                            $update_stmt->execute();
                            $update_stmt->close();
                            $conn->query("UPDATE cars SET status = 'rented' WHERE id = " . intval($car_id));
                            header('Location: rentals.php?view=' . $rental_id);
                            exit();
                        }

                        $form_error = 'Something went wrong. Please try again.';
                        $open_modal = 'addRentalModal';
                        $stmt->close();
                    }
                }

                $car_stmt->close();
                $customer_stmt->close();
            }
        }
    } elseif ($rental_action == 'update') {
        $rental_id = intval($_POST['rental_id'] ?? 0);
        $car_id = intval($_POST['car_id'] ?? 0);
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $start_date = sanitize($_POST['start_date'] ?? '');
        $duration = sanitize($_POST['agreement_duration'] ?? '3_months');
        $custom_end_date = sanitize($_POST['custom_end_date'] ?? '');
        $payment_frequency = sanitize($_POST['payment_frequency'] ?? 'monthly');
        $status = sanitize($_POST['status'] ?? 'active');
        $notes = sanitize($_POST['notes'] ?? '');

        $valid_frequencies = ['daily', 'weekly', 'monthly'];
        if (!$rental_id || !$car_id || !$customer_id || !$start_date || !in_array($payment_frequency, $valid_frequencies)) {
            $form_error = 'Please fill in all required fields';
            $open_modal = 'editRentalModal' . $rental_id;
        } else {
            [$end, $duration_label, $date_error] = agreementEndAndLabel($start_date, $duration, $custom_end_date);
            if ($date_error) {
                $form_error = $date_error;
                $open_modal = 'editRentalModal' . $rental_id;
            }
        }

        if (!$form_error) {
            $rental_stmt = $conn->prepare("SELECT * FROM rentals WHERE id = ?");
            $rental_stmt->bind_param("i", $rental_id);
            $rental_stmt->execute();
            $rental_result = $rental_stmt->get_result();
            $rental_stmt->close();

            if ($rental_result->num_rows == 0) {
                $form_error = 'Selected rental not found';
                $open_modal = 'editRentalModal' . $rental_id;
            } else {
                $current_rental = $rental_result->fetch_assoc();

                $car_stmt = $conn->prepare("SELECT * FROM cars WHERE id = ? AND (status = 'available' OR id = ?)");
                $current_car_id = intval($current_rental['car_id']);
                $car_stmt->bind_param("ii", $car_id, $current_car_id);
                $car_stmt->execute();
                $car_result = $car_stmt->get_result();
                $customer_stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
                $customer_stmt->bind_param("i", $customer_id);
                $customer_stmt->execute();
                $customer_result = $customer_stmt->get_result();

                if ($car_result->num_rows == 0) {
                    $form_error = 'Selected car is not available';
                    $open_modal = 'editRentalModal' . $rental_id;
                } elseif ($customer_result->num_rows == 0) {
                    $form_error = 'Selected customer not found';
                    $open_modal = 'editRentalModal' . $rental_id;
                } else {
                    $car = $car_result->fetch_assoc();
                    $customer = $customer_result->fetch_assoc();
                    $rental_user_id = intval($car['user_id']);

                    if ($rental_user_id !== intval($customer['user_id'])) {
                        $form_error = 'Selected car and customer must belong to the same account';
                        $open_modal = 'editRentalModal' . $rental_id;
                    } else {
                        $end_date = $end->format('Y-m-d');
                        $total_days = $end->diff(new DateTime($start_date))->days + 1;
                        $daily_rate = floatval($car['daily_rate']);
                        $rate_amount = rentalRateForFrequency($car, $payment_frequency);

                        $schedule_changed = (
                            intval($current_rental['car_id']) !== $car_id
                            || intval($current_rental['customer_id']) !== $customer_id
                            || $current_rental['start_date'] !== $start_date
                            || $current_rental['end_date'] !== $end_date
                            || $current_rental['payment_frequency'] !== $payment_frequency
                            || floatval($current_rental['rate_amount']) != $rate_amount
                        );

                        $stmt = $conn->prepare("UPDATE rentals SET user_id = ?, car_id = ?, customer_id = ?, start_date = ?, end_date = ?, total_days = ?, agreement_duration = ?, payment_frequency = ?, daily_rate = ?, rate_amount = ?, status = ?, notes = ? WHERE id = ?");
                        $stmt->bind_param("iiississddssi", $rental_user_id, $car_id, $customer_id, $start_date, $end_date, $total_days, $duration_label, $payment_frequency, $daily_rate, $rate_amount, $status, $notes, $rental_id);

                        if ($stmt->execute()) {
                            if ($schedule_changed) {
                                $paid_periods = [];
                                $paid_result = $conn->query("SELECT period_start, period_end FROM rental_payment_records WHERE rental_id = " . intval($rental_id) . " AND status = 'paid'");
                                while ($paid_record = $paid_result->fetch_assoc()) {
                                    $paid_periods[$paid_record['period_start'] . '|' . $paid_record['period_end']] = true;
                                }

                                $conn->query("DELETE FROM rental_payment_records WHERE rental_id = " . intval($rental_id) . " AND status = 'pending'");
                                generatePaymentRecords($conn, $rental_id, $start_date, $end_date, $payment_frequency, $rate_amount, $paid_periods);
                                refreshRentalPaymentStatus($conn, $rental_id);
                            } else {
                                refreshRentalPaymentStatus($conn, $rental_id);
                            }

                            if ($current_car_id !== $car_id) {
                                $conn->query("UPDATE cars SET status = 'available' WHERE id = " . $current_car_id);
                            }

                            if ($status == 'active') {
                                $conn->query("UPDATE cars SET status = 'rented' WHERE id = " . intval($car_id));
                            } else {
                                $conn->query("UPDATE cars SET status = 'available' WHERE id = " . intval($car_id));
                            }

                            header('Location: rentals.php?view=' . $rental_id);
                            exit();
                        }

                        $form_error = 'Something went wrong. Please try again.';
                        $open_modal = 'editRentalModal' . $rental_id;
                        $stmt->close();
                    }
                }

                $car_stmt->close();
                $customer_stmt->close();
            }
        }
    } elseif ($rental_action == 'delete') {
        $rental_id = intval($_POST['rental_id'] ?? 0);
        $car = $conn->query("SELECT car_id FROM rentals WHERE id = " . intval($rental_id))->fetch_assoc();
        $stmt = $conn->prepare("DELETE FROM rentals WHERE id = ?");
        $stmt->bind_param("i", $rental_id);
        if ($stmt->execute() && $car) {
            $conn->query("UPDATE cars SET status = 'available' WHERE id = " . intval($car['car_id']));
        }
        $stmt->close();
        header('Location: rentals.php');
        exit();
    } elseif ($rental_action == 'mark_paid' || $rental_action == 'mark_pending') {
        $record_id = intval($_POST['record_id'] ?? 0);
        $rental_id = intval($_POST['rental_id'] ?? 0);

        if ($rental_action == 'mark_paid') {
            $paid_date = sanitize($_POST['paid_date'] ?? date('Y-m-d'));
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);
            $record = $conn->query("SELECT amount_due FROM rental_payment_records WHERE id = $record_id")->fetch_assoc();
            if ($amount_paid <= 0 && $record) {
                $amount_paid = floatval($record['amount_due']);
            }
            $stmt = $conn->prepare("UPDATE rental_payment_records SET status = 'paid', paid_date = ?, amount_paid = ? WHERE id = ?");
            $stmt->bind_param("sdi", $paid_date, $amount_paid, $record_id);
        } else {
            $record = $conn->query("SELECT receipt_photo FROM rental_payment_records WHERE id = $record_id")->fetch_assoc();
            if (!empty($record['receipt_photo']) && file_exists('uploads/receipts/' . $record['receipt_photo'])) {
                unlink('uploads/receipts/' . $record['receipt_photo']);
            }
            $stmt = $conn->prepare("UPDATE rental_payment_records SET status = 'pending', paid_date = NULL, amount_paid = 0, receipt_photo = NULL WHERE id = ?");
            $stmt->bind_param("i", $record_id);
        }

        $stmt->execute();
        $stmt->close();
        refreshRentalPaymentStatus($conn, $rental_id);
        header('Location: rentals.php?view=' . $rental_id);
        exit();
    } elseif ($rental_action == 'upload_receipt') {
        $record_id = intval($_POST['record_id'] ?? 0);
        $rental_id = intval($_POST['rental_id'] ?? 0);
        $record = $conn->query("SELECT receipt_photo FROM rental_payment_records WHERE id = $record_id AND rental_id = $rental_id AND status = 'paid'")->fetch_assoc();

        if ($record) {
            $receipt_photo = uploadPaymentReceipt('receipt_photo');
            if ($receipt_photo) {
                if (!empty($record['receipt_photo']) && file_exists('uploads/receipts/' . $record['receipt_photo'])) {
                    unlink('uploads/receipts/' . $record['receipt_photo']);
                }
                $stmt = $conn->prepare("UPDATE rental_payment_records SET receipt_photo = ? WHERE id = ?");
                $stmt->bind_param("si", $receipt_photo, $record_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        header('Location: rentals.php?view=' . $rental_id);
        exit();
    }
}

$cars_for_select_result = $conn->query("SELECT c.*, u.company_name, u.full_name
                                        FROM cars c
                                        JOIN users u ON c.user_id = u.id
                                        ORDER BY c.brand, c.model");
$customers_for_select_result = $conn->query("SELECT c.*, u.company_name, u.full_name AS agent_name
                                             FROM customers c
                                             JOIN users u ON c.user_id = u.id
                                             ORDER BY c.full_name");
$rentals = $conn->query("SELECT r.*, c.brand, c.model, c.plate_number, cu.full_name AS customer_name, cu.phone AS customer_phone, u.company_name, u.full_name AS agent_name
                         FROM rentals r
                         JOIN cars c ON r.car_id = c.id
                         JOIN customers cu ON r.customer_id = cu.id
                         JOIN users u ON r.user_id = u.id
                         ORDER BY r.created_at DESC");

$rental_rows = [];
while ($rental = $rentals->fetch_assoc()) {
    $rental_rows[] = $rental;
}

$cars_for_select = [];
while ($car = $cars_for_select_result->fetch_assoc()) {
    $cars_for_select[] = $car;
}

$customers_for_select = [];
while ($customer = $customers_for_select_result->fetch_assoc()) {
    $customers_for_select[] = $customer;
}

$payment_records = [];
if (!empty($rental_rows)) {
    $ids = implode(',', array_map('intval', array_column($rental_rows, 'id')));
    $records = $conn->query("SELECT * FROM rental_payment_records WHERE rental_id IN ($ids) ORDER BY due_date ASC, id ASC");
    while ($record = $records->fetch_assoc()) {
        $payment_records[intval($record['rental_id'])][] = $record;
    }
}

include 'includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2>Rentals Management</h2>
        <p class="text-muted">Create agreements and track scheduled daily, weekly, or monthly payments</p>
    </div>
    <div class="col-md-6 text-end">
        <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#addRentalModal">
            <i class="bi bi-plus-circle me-2"></i>New Rental
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
        <?php if (count($rental_rows) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Car</th>
                        <th>Customer</th>
                        <th>Agreement</th>
                        <th>Payment</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rental_rows as $rental): ?>
                    <tr>
                        <td><strong><?php echo $rental['brand'] . ' ' . $rental['model']; ?></strong><br><small class="text-muted"><?php echo $rental['plate_number']; ?></small></td>
                        <td><?php echo $rental['customer_name']; ?><br><small class="text-muted"><?php echo $rental['customer_phone']; ?></small></td>
                        <td><?php echo formatDate($rental['start_date']); ?> - <?php echo formatDate($rental['end_date']); ?><br><small class="text-muted"><?php echo $rental['agreement_duration']; ?></small></td>
                        <td><?php echo paymentFrequencyLabel($rental['payment_frequency']); ?><br><small class="text-muted"><?php echo formatCurrency($rental['rate_amount']); ?> each</small></td>
                        <td><?php echo formatCurrency($rental['total_amount']); ?></td>
                        <td><?php echo formatCurrency($rental['total_paid']); ?><br><small class="text-muted"><?php echo ucfirst($rental['payment_status']); ?></small></td>
                        <td><span class="badge <?php echo $rental['status'] == 'active' ? 'bg-primary' : ($rental['status'] == 'completed' ? 'bg-success' : 'bg-danger'); ?>"><?php echo ucfirst($rental['status']); ?></span></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewRentalModal<?php echo $rental['id']; ?>"><i class="bi bi-eye"></i></button>
                            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#editRentalModal<?php echo $rental['id']; ?>"><i class="bi bi-pencil"></i></button>
                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteRentalModal<?php echo $rental['id']; ?>"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5">
            <i class="bi bi-clipboard-check fs-1 text-muted"></i>
            <p class="text-muted mt-3">No rentals found. Create the first agreement to begin tracking payments.</p>
            <button type="button" class="btn btn-dark mt-2" data-bs-toggle="modal" data-bs-target="#addRentalModal">
                <i class="bi bi-plus-circle me-2"></i>New Rental
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addRentalModal" tabindex="-1" aria-labelledby="addRentalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="rentals.php" class="modal-content rental-form">
            <input type="hidden" name="rental_action" value="create">
            <div class="modal-header">
                <h5 class="modal-title" id="addRentalModalLabel">New Rental Agreement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Car <span class="text-danger">*</span></label>
                        <select class="form-select rental-car-select" name="car_id" required>
                            <option value="">Choose a car...</option>
                            <?php foreach ($cars_for_select as $car): ?>
                            <?php if ($car['status'] != 'available') continue; ?>
                            <option value="<?php echo $car['id']; ?>"
                                    data-daily-rate="<?php echo $car['daily_rate']; ?>"
                                    data-weekly-rate="<?php echo $car['weekly_rate']; ?>"
                                    data-monthly-rate="<?php echo $car['monthly_rate']; ?>">
                                <?php echo $car['brand'] . ' ' . $car['model'] . ' - ' . $car['plate_number']; ?> (<?php echo $car['company_name'] ?? $car['full_name']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Customer <span class="text-danger">*</span></label>
                        <select class="form-select" name="customer_id" required>
                            <option value="">Choose a customer...</option>
                            <?php foreach ($customers_for_select as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>"><?php echo $customer['full_name'] . ' - ' . $customer['phone']; ?> (<?php echo $customer['company_name'] ?? $customer['agent_name']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Start Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control rental-start-date" name="start_date" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Agreement Duration</label>
                        <select class="form-select rental-duration" name="agreement_duration">
                            <option value="3_months">3 Months</option>
                            <option value="6_months">6 Months</option>
                            <option value="1_year">1 Year</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Custom End Date</label>
                        <input type="date" class="form-control rental-custom-end-date" name="custom_end_date">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Payment Schedule</label>
                        <select class="form-select rental-frequency" name="payment_frequency">
                            <option value="monthly">Monthly</option>
                            <option value="weekly">Weekly</option>
                            <option value="daily">Daily</option>
                        </select>
                    </div>
                </div>

                <div class="card bg-light mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <p class="mb-1 text-muted">Agreement End</p>
                                <h6 class="rental-end-display">-</h6>
                            </div>
                            <div class="col-md-4">
                                <p class="mb-1 text-muted">Payment Amount</p>
                                <h6 class="rental-rate-display">RM 0.00</h6>
                            </div>
                            <div class="col-md-4">
                                <p class="mb-1 text-muted">Estimated Records</p>
                                <h6 class="rental-records-display">0</h6>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="notes" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark"><i class="bi bi-plus-circle me-2"></i>Create Rental</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($rental_rows as $rental): ?>
<?php $records = $payment_records[intval($rental['id'])] ?? []; ?>
<div class="modal fade" id="viewRentalModal<?php echo $rental['id']; ?>" tabindex="-1" aria-labelledby="viewRentalModalLabel<?php echo $rental['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="viewRentalModalLabel<?php echo $rental['id']; ?>">Rental #<?php echo str_pad($rental['id'], 6, '0', STR_PAD_LEFT); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-3"><p class="text-muted mb-1">Car</p><h6><?php echo $rental['brand'] . ' ' . $rental['model']; ?></h6></div>
                    <div class="col-md-3"><p class="text-muted mb-1">Customer</p><h6><?php echo $rental['customer_name']; ?></h6></div>
                    <div class="col-md-3"><p class="text-muted mb-1">Agreement</p><h6><?php echo formatDate($rental['start_date']); ?> - <?php echo formatDate($rental['end_date']); ?></h6></div>
                    <div class="col-md-3"><p class="text-muted mb-1">Schedule</p><h6><?php echo paymentFrequencyLabel($rental['payment_frequency']); ?> at <?php echo formatCurrency($rental['rate_amount']); ?></h6></div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Due Date</th>
                                <th>Amount Due</th>
                                <th>Paid</th>
                                <th>Receipt</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?php echo formatDate($record['period_start']); ?> - <?php echo formatDate($record['period_end']); ?></td>
                                <td><?php echo formatDate($record['due_date']); ?></td>
                                <td><?php echo formatCurrency($record['amount_due']); ?></td>
                                <td><?php echo $record['status'] == 'paid' ? formatCurrency($record['amount_paid']) . '<br><small class="text-muted">' . formatDate($record['paid_date']) . '</small>' : '-'; ?></td>
                                <td>
                                    <?php if (!empty($record['receipt_photo'])): ?>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#receiptModal<?php echo $record['id']; ?>">
                                        <i class="bi bi-receipt me-1"></i>Receipt
                                    </button>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?php echo $record['status'] == 'paid' ? 'bg-success' : 'bg-danger'; ?>"><?php echo ucfirst($record['status']); ?></span></td>
                                <td>
                                    <?php if ($record['status'] == 'paid'): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php if (empty($record['receipt_photo'])): ?>
                                        <form method="POST" action="rentals.php" enctype="multipart/form-data" class="d-flex flex-wrap gap-2">
                                            <input type="hidden" name="rental_action" value="upload_receipt">
                                            <input type="hidden" name="rental_id" value="<?php echo $rental['id']; ?>">
                                            <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                            <input type="file" name="receipt_photo" accept="image/*,.pdf" class="form-control form-control-sm" style="max-width: 220px;" required>
                                            <button type="submit" class="btn btn-sm btn-info">Upload Receipt</button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" action="rentals.php" class="d-inline">
                                            <input type="hidden" name="rental_action" value="mark_pending">
                                            <input type="hidden" name="rental_id" value="<?php echo $rental['id']; ?>">
                                            <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning">Mark Pending</button>
                                        </form>
                                    </div>
                                    <?php else: ?>
                                    <form method="POST" action="rentals.php" class="d-flex flex-wrap gap-2">
                                        <input type="hidden" name="rental_action" value="mark_paid">
                                        <input type="hidden" name="rental_id" value="<?php echo $rental['id']; ?>">
                                        <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                        <input type="date" name="paid_date" value="<?php echo date('Y-m-d'); ?>" class="form-control form-control-sm" style="max-width: 150px;">
                                        <input type="number" name="amount_paid" value="<?php echo $record['amount_due']; ?>" step="0.01" min="0" class="form-control form-control-sm" style="max-width: 120px;">
                                        <button type="submit" class="btn btn-sm btn-success">Mark Paid</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editRentalModal<?php echo $rental['id']; ?>" tabindex="-1" aria-labelledby="editRentalModalLabel<?php echo $rental['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="rentals.php" class="modal-content rental-form">
            <input type="hidden" name="rental_action" value="update">
            <input type="hidden" name="rental_id" value="<?php echo $rental['id']; ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="editRentalModalLabel<?php echo $rental['id']; ?>">Edit Rental</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Changing the car, customer, dates, duration, or payment schedule will rebuild the pending payment records for this agreement.
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Car <span class="text-danger">*</span></label>
                        <select class="form-select rental-car-select" name="car_id" required>
                            <?php foreach ($cars_for_select as $car): ?>
                            <?php
                            $is_current_car = intval($car['id']) === intval($rental['car_id']);
                            $is_unavailable = $car['status'] != 'available' && !$is_current_car;
                            ?>
                            <option value="<?php echo $car['id']; ?>"
                                    data-daily-rate="<?php echo $car['daily_rate']; ?>"
                                    data-weekly-rate="<?php echo $car['weekly_rate']; ?>"
                                    data-monthly-rate="<?php echo $car['monthly_rate']; ?>"
                                    <?php echo $is_current_car ? 'selected' : ''; ?>
                                    <?php echo $is_unavailable ? 'disabled' : ''; ?>>
                                <?php echo $car['brand'] . ' ' . $car['model'] . ' - ' . $car['plate_number']; ?>
                                (<?php echo $car['company_name'] ?? $car['full_name']; ?>)
                                <?php echo $is_unavailable ? ' - Not available' : ''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Customer <span class="text-danger">*</span></label>
                        <select class="form-select" name="customer_id" required>
                            <?php foreach ($customers_for_select as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>" <?php echo intval($customer['id']) === intval($rental['customer_id']) ? 'selected' : ''; ?>>
                                <?php echo $customer['full_name'] . ' - ' . $customer['phone']; ?> (<?php echo $customer['company_name'] ?? $customer['agent_name']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php
                $duration_value = durationValueFromLabel($rental['agreement_duration']);
                $custom_end_value = $duration_value == 'custom' ? $rental['end_date'] : '';
                ?>
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Start Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control rental-start-date" name="start_date" value="<?php echo $rental['start_date']; ?>" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Agreement Duration</label>
                        <select class="form-select rental-duration" name="agreement_duration">
                            <option value="3_months" <?php echo $duration_value == '3_months' ? 'selected' : ''; ?>>3 Months</option>
                            <option value="6_months" <?php echo $duration_value == '6_months' ? 'selected' : ''; ?>>6 Months</option>
                            <option value="1_year" <?php echo $duration_value == '1_year' ? 'selected' : ''; ?>>1 Year</option>
                            <option value="custom" <?php echo $duration_value == 'custom' ? 'selected' : ''; ?>>Custom</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Custom End Date</label>
                        <input type="date" class="form-control rental-custom-end-date" name="custom_end_date" value="<?php echo $custom_end_value; ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Payment Schedule</label>
                        <select class="form-select rental-frequency" name="payment_frequency">
                            <option value="monthly" <?php echo $rental['payment_frequency'] == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="weekly" <?php echo $rental['payment_frequency'] == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="daily" <?php echo $rental['payment_frequency'] == 'daily' ? 'selected' : ''; ?>>Daily</option>
                        </select>
                    </div>
                </div>

                <div class="card bg-light mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <p class="mb-1 text-muted">Agreement End</p>
                                <h6 class="rental-end-display"><?php echo $rental['end_date']; ?></h6>
                            </div>
                            <div class="col-md-4">
                                <p class="mb-1 text-muted">Payment Amount</p>
                                <h6 class="rental-rate-display"><?php echo formatCurrency($rental['rate_amount']); ?></h6>
                            </div>
                            <div class="col-md-4">
                                <p class="mb-1 text-muted">Estimated Records</p>
                                <h6 class="rental-records-display"><?php echo count($records); ?></h6>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active" <?php echo $rental['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo $rental['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $rental['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="notes" rows="3"><?php echo $rental['notes']; ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark">Update Rental</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="deleteRentalModal<?php echo $rental['id']; ?>" tabindex="-1" aria-labelledby="deleteRentalModalLabel<?php echo $rental['id']; ?>" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="rentals.php" class="modal-content">
            <input type="hidden" name="rental_action" value="delete">
            <input type="hidden" name="rental_id" value="<?php echo $rental['id']; ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteRentalModalLabel<?php echo $rental['id']; ?>">Delete Rental</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Delete rental for <strong><?php echo $rental['customer_name']; ?></strong> using <strong><?php echo $rental['plate_number']; ?></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-2"></i>Delete</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<?php foreach ($payment_records as $rental_record_group): ?>
<?php foreach ($rental_record_group as $record): ?>
<?php if (!empty($record['receipt_photo'])): ?>
<?php
$receipt_path = 'uploads/receipts/' . $record['receipt_photo'];
$receipt_ext = strtolower(pathinfo($record['receipt_photo'], PATHINFO_EXTENSION));
?>
<div class="modal fade" id="receiptModal<?php echo $record['id']; ?>" tabindex="-1" aria-labelledby="receiptModalLabel<?php echo $record['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="receiptModalLabel<?php echo $record['id']; ?>">Payment Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($receipt_ext == 'pdf'): ?>
                <iframe src="<?php echo $receipt_path; ?>" class="w-100 border rounded" style="height: 70vh;"></iframe>
                <?php else: ?>
                <img src="<?php echo $receipt_path; ?>" alt="Payment receipt" class="img-fluid rounded border d-block mx-auto">
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <a href="<?php echo $receipt_path; ?>" target="_blank" class="btn btn-dark">
                    <i class="bi bi-box-arrow-up-right me-2"></i>Open File
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function parseDate(value) {
        const parts = value.split('-').map(Number);
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function formatCurrency(amount) {
        return 'RM ' + Number(amount || 0).toFixed(2);
    }

    function getPlanDays(frequency) {
        if (frequency === 'weekly') return 7;
        if (frequency === 'monthly') return 30;
        return 1;
    }

    function getFrequencyUnit(frequency) {
        if (frequency === 'weekly') return 'week';
        if (frequency === 'monthly') return 'month';
        return 'day';
    }

    function updateRentalPreview(form) {
        const carSelect = form.querySelector('.rental-car-select');
        const startInput = form.querySelector('.rental-start-date');
        const duration = form.querySelector('.rental-duration').value;
        const customEnd = form.querySelector('.rental-custom-end-date');
        const frequency = form.querySelector('.rental-frequency').value;

        if (!startInput.value) return;

        const start = parseDate(startInput.value);
        let end = new Date(start);
        if (duration === '1_year') {
            end.setFullYear(end.getFullYear() + 1);
            end.setDate(end.getDate() - 1);
        } else if (duration === '6_months') {
            end.setMonth(end.getMonth() + 6);
            end.setDate(end.getDate() - 1);
        } else if (duration === 'custom' && customEnd.value) {
            end = parseDate(customEnd.value);
        } else {
            end.setMonth(end.getMonth() + 3);
            end.setDate(end.getDate() - 1);
        }

        const selectedCar = carSelect.options[carSelect.selectedIndex];
        const dailyRate = parseFloat(selectedCar?.dataset.dailyRate) || 0;
        let rate = dailyRate;
        if (frequency === 'weekly') {
            rate = parseFloat(selectedCar?.dataset.weeklyRate) || dailyRate * 7;
        } else if (frequency === 'monthly') {
            rate = parseFloat(selectedCar?.dataset.monthlyRate) || dailyRate * 30;
        }

        const diffDays = Math.max(1, Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1);
        const records = Math.max(1, Math.ceil(diffDays / getPlanDays(frequency)));

        form.querySelector('.rental-end-display').textContent = formatDate(end);
        form.querySelector('.rental-rate-display').textContent = formatCurrency(rate) + ' / ' + getFrequencyUnit(frequency);
        form.querySelector('.rental-records-display').textContent = records;
    }

    document.querySelectorAll('.rental-form').forEach(function(form) {
        form.querySelectorAll('select, input').forEach(function(input) {
            input.addEventListener('change', function() {
                updateRentalPreview(form);
            });
        });
        updateRentalPreview(form);
    });

    const viewRentalId = new URLSearchParams(window.location.search).get('view');
    if (viewRentalId) {
        const modal = document.getElementById(`viewRentalModal${viewRentalId}`);
        if (modal) new bootstrap.Modal(modal).show();
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
