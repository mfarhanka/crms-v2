<?php
require_once 'config/config.php';
requireLogin();

$page_title = 'Customers Management';
$conn = getDBConnection();
ensureCustomerPortalSchema($conn);
$user_id = intval($_SESSION['user_id']);
$form_error = '';
$open_modal = '';

function uploadCustomerDocument($field_name, $current_file = '') {
    $upload_dir = 'uploads/customers/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    if (empty($_FILES[$field_name]['name'])) {
        return $current_file;
    }

    $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($_FILES[$field_name]['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_types)) {
        return $current_file;
    }

    if ($current_file && file_exists($upload_dir . $current_file)) {
        unlink($upload_dir . $current_file);
    }

    $new_file = $field_name . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    move_uploaded_file($_FILES[$field_name]['tmp_name'], $upload_dir . $new_file);
    return $new_file;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_action = sanitize($_POST['customer_action'] ?? '');

    if ($customer_action == 'create' || $customer_action == 'update') {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $full_name = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $id_number = sanitize($_POST['id_number'] ?? '');
        $license_number = sanitize($_POST['license_number'] ?? '');
        $license_expiry_date = !empty($_POST['license_expiry_date']) ? sanitize($_POST['license_expiry_date']) : null;
        $psv_expiry_date = !empty($_POST['psv_expiry_date']) ? sanitize($_POST['psv_expiry_date']) : null;

        if (empty($full_name) || empty($phone)) {
            $form_error = 'Please fill in all required fields';
            $open_modal = $customer_action == 'create' ? 'addCustomerModal' : 'editCustomerModal' . $customer_id;
        } else {
            if ($customer_action == 'create') {
                $owner_id = $user_id;
                if (isSuperAdmin() && $owner_id === 0) {
                    $owner_result = $conn->query("SELECT id FROM users WHERE status = 'active' ORDER BY role = 'agent' DESC, full_name LIMIT 1");
                    if ($owner_result && $owner_result->num_rows === 1) {
                        $owner_id = intval($owner_result->fetch_assoc()['id']);
                    }
                }

                if ($owner_id <= 0) {
                    $form_error = 'No active user is available to add this customer.';
                    $open_modal = 'addCustomerModal';
                } else {
                    $ic_front_photo = uploadCustomerDocument('ic_front_photo');
                    $ic_back_photo = uploadCustomerDocument('ic_back_photo');
                    $license_front_photo = uploadCustomerDocument('license_front_photo');
                    $license_back_photo = uploadCustomerDocument('license_back_photo');
                    $psv_front_photo = uploadCustomerDocument('psv_front_photo');
                    $psv_back_photo = uploadCustomerDocument('psv_back_photo');

                    $stmt = $conn->prepare("INSERT INTO customers (user_id, full_name, email, phone, address, id_number, license_number, license_expiry_date, psv_expiry_date, ic_front_photo, ic_back_photo, license_front_photo, license_back_photo, psv_front_photo, psv_back_photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("issssssssssssss", $owner_id, $full_name, $email, $phone, $address, $id_number, $license_number, $license_expiry_date, $psv_expiry_date, $ic_front_photo, $ic_back_photo, $license_front_photo, $license_back_photo, $psv_front_photo, $psv_back_photo);

                    if ($stmt->execute()) {
                        header('Location: customers.php');
                        exit();
                    }

                    $form_error = 'Something went wrong. Please try again.';
                    $open_modal = 'addCustomerModal';
                    $stmt->close();
                }
            } else {
                $customer_stmt = isAdmin()
                    ? $conn->prepare("SELECT * FROM customers WHERE id = ?")
                    : $conn->prepare("SELECT * FROM customers WHERE id = ? AND user_id = ?");

                if (isAdmin()) {
                    $customer_stmt->bind_param("i", $customer_id);
                } else {
                    $customer_stmt->bind_param("ii", $customer_id, $user_id);
                }

                $customer_stmt->execute();
                $customer_result = $customer_stmt->get_result();
                $customer_stmt->close();

                if ($customer_result->num_rows == 0) {
                    $form_error = 'Selected customer not found';
                    $open_modal = 'editCustomerModal' . $customer_id;
                } else {
                    $customer = $customer_result->fetch_assoc();
                    $ic_front_photo = uploadCustomerDocument('ic_front_photo', $customer['ic_front_photo']);
                    $ic_back_photo = uploadCustomerDocument('ic_back_photo', $customer['ic_back_photo']);
                    $license_front_photo = uploadCustomerDocument('license_front_photo', $customer['license_front_photo']);
                    $license_back_photo = uploadCustomerDocument('license_back_photo', $customer['license_back_photo']);
                    $psv_front_photo = uploadCustomerDocument('psv_front_photo', $customer['psv_front_photo']);
                    $psv_back_photo = uploadCustomerDocument('psv_back_photo', $customer['psv_back_photo']);

                    $update_stmt = $conn->prepare("UPDATE customers SET full_name = ?, email = ?, phone = ?, address = ?, id_number = ?, license_number = ?, license_expiry_date = ?, psv_expiry_date = ?, ic_front_photo = ?, ic_back_photo = ?, license_front_photo = ?, license_back_photo = ?, psv_front_photo = ?, psv_back_photo = ? WHERE id = ?");
                    $update_stmt->bind_param("ssssssssssssssi", $full_name, $email, $phone, $address, $id_number, $license_number, $license_expiry_date, $psv_expiry_date, $ic_front_photo, $ic_back_photo, $license_front_photo, $license_back_photo, $psv_front_photo, $psv_back_photo, $customer_id);

                    if ($update_stmt->execute()) {
                        header('Location: customers.php');
                        exit();
                    }

                    $form_error = 'Something went wrong. Please try again.';
                    $open_modal = 'editCustomerModal' . $customer_id;
                    $update_stmt->close();
                }
            }
        }
    } elseif ($customer_action == 'delete') {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $delete_stmt = isAdmin()
            ? $conn->prepare("DELETE FROM customers WHERE id = ?")
            : $conn->prepare("DELETE FROM customers WHERE id = ? AND user_id = ?");

        if (isAdmin()) {
            $delete_stmt->bind_param("i", $customer_id);
        } else {
            $delete_stmt->bind_param("ii", $customer_id, $user_id);
        }

        $delete_stmt->execute();
        $delete_stmt->close();
        header('Location: customers.php');
        exit();
    }
}

if (isAdmin()) {
    $customers = $conn->query("SELECT c.*, u.company_name, u.full_name as agent_name
                               FROM customers c
                               JOIN users u ON c.user_id = u.id
                               ORDER BY c.created_at DESC");
} else {
    $customers = $conn->query("SELECT * FROM customers WHERE user_id = $user_id ORDER BY created_at DESC");
}

$customer_rows = [];
while ($customer = $customers->fetch_assoc()) {
    $customer_rows[] = $customer;
}

include 'includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2>Customers Management</h2>
        <p class="text-muted">Manage your customer database</p>
    </div>
    <div class="col-md-6 text-end">
        <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
            <i class="bi bi-plus-circle me-2"></i>Add New Customer
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
        <?php if (count($customer_rows) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>License Number</th>
                        <th>Portal</th>
                        <?php if (isAdmin()): ?>
                        <th>Added By</th>
                        <?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customer_rows as $customer): ?>
                    <tr>
                        <td><strong><?php echo $customer['full_name']; ?></strong></td>
                        <td><?php echo $customer['email'] ?: 'N/A'; ?></td>
                        <td><?php echo $customer['phone']; ?></td>
                        <td><?php echo $customer['license_number'] ?: 'N/A'; ?></td>
                        <td>
                            <?php $portal_url = SITE_URL . 'customer_portal.php?token=' . urlencode($customer['access_token'] ?? ''); ?>
                            <a href="<?php echo htmlspecialchars($portal_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-sm btn-info">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Open
                            </a>
                        </td>
                        <?php if (isAdmin()): ?>
                        <td><?php echo $customer['company_name'] ?? $customer['agent_name']; ?></td>
                        <?php endif; ?>
                        <td>
                            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#editCustomerModal<?php echo $customer['id']; ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteCustomerModal<?php echo $customer['id']; ?>">
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
            <i class="bi bi-people fs-1 text-muted"></i>
            <p class="text-muted mt-3">No customers found. Add your first customer to get started!</p>
            <button type="button" class="btn btn-dark mt-2" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                <i class="bi bi-plus-circle me-2"></i>Add New Customer
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="customers.php" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="customer_action" value="create">
            <div class="modal-header">
                <h5 class="modal-title" id="addCustomerModalLabel">Add New Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php $customer_form = ['full_name' => '', 'email' => '', 'phone' => '', 'address' => '', 'id_number' => '', 'license_number' => '', 'license_expiry_date' => '', 'psv_expiry_date' => '', 'ic_front_photo' => '', 'ic_back_photo' => '', 'license_front_photo' => '', 'license_back_photo' => '', 'psv_front_photo' => '', 'psv_back_photo' => '']; ?>
                <?php include __DIR__ . '/includes/customer_form_fields.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark">
                    <i class="bi bi-plus-circle me-2"></i>Add Customer
                </button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($customer_rows as $customer): ?>
<div class="modal fade" id="editCustomerModal<?php echo $customer['id']; ?>" tabindex="-1" aria-labelledby="editCustomerModalLabel<?php echo $customer['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="customers.php" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="customer_action" value="update">
            <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="editCustomerModalLabel<?php echo $customer['id']; ?>">Edit Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php $customer_form = $customer; ?>
                <?php include __DIR__ . '/includes/customer_form_fields.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark">
                    <i class="bi bi-check-circle me-2"></i>Update Customer
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="deleteCustomerModal<?php echo $customer['id']; ?>" tabindex="-1" aria-labelledby="deleteCustomerModalLabel<?php echo $customer['id']; ?>" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="customers.php" class="modal-content">
            <input type="hidden" name="customer_action" value="delete">
            <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteCustomerModalLabel<?php echo $customer['id']; ?>">Delete Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Delete <strong><?php echo $customer['full_name']; ?></strong>?</p>
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
    const addCustomer = new URLSearchParams(window.location.search).get('add');
    if (addCustomer) {
        new bootstrap.Modal(document.getElementById('addCustomerModal')).show();
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
