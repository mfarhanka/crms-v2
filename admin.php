<?php
require_once 'config/config.php';
requireAdmin();

$page_title = 'Admin Panel';
$conn = getDBConnection();
ensureRentalSchema($conn);
ensureMaintenanceSchema($conn);
$error = '';
$success = '';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ensureUserEmailCanBeEmpty($conn) {
    try {
        $conn->query("ALTER TABLE users MODIFY email VARCHAR(100) NULL");
        $conn->query("UPDATE users SET email = NULL WHERE email = ''");
    } catch (mysqli_sql_exception $exception) {
        // The admin form still validates normally; this prevents a migration issue from hiding the page.
    }
}

function optionalEmail($email) {
    $email = sanitize($email ?? '');
    return $email === '' ? null : $email;
}

ensureUserEmailCanBeEmpty($conn);

// Handle superadmin admin management actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    if (!isSuperAdmin()) {
        header('Location: admin.php');
        exit();
    }

    $admin_action = $_POST['admin_action'];

    if ($admin_action === 'add_admin') {
        $username = sanitize($_POST['username'] ?? '');
        $email = optionalEmail($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $full_name = sanitize($_POST['full_name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if (empty($username) || empty($password) || empty($full_name)) {
            $error = 'Please fill in all required admin fields.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } else {
            if ($email === null) {
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->bind_param("s", $username);
            } else {
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt->bind_param("ss", $username, $email);
            }
            $stmt->execute();
            $existing = $stmt->get_result();

            if ($existing->num_rows > 0) {
                $error = $email === null ? 'Username already exists.' : 'Username or email already exists.';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, phone, role, status) VALUES (?, ?, ?, ?, ?, 'admin', ?)");
                $stmt->bind_param("ssssss", $username, $email, $hashed_password, $full_name, $phone, $status);

                if ($stmt->execute()) {
                    $success = 'Admin account created successfully.';
                } else {
                    $error = 'Unable to create admin account. Please try again.';
                }
            }

            $stmt->close();
        }
    }

    if ($admin_action === 'edit_admin') {
        $admin_id = intval($_POST['admin_id'] ?? 0);
        $username = sanitize($_POST['username'] ?? '');
        $email = optionalEmail($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $full_name = sanitize($_POST['full_name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($admin_id <= 0 || empty($username) || empty($full_name)) {
            $error = 'Please fill in all required admin fields.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } else {
            if ($email === null) {
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->bind_param("si", $username, $admin_id);
            } else {
                $stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                $stmt->bind_param("ssi", $username, $email, $admin_id);
            }
            $stmt->execute();
            $existing = $stmt->get_result();

            if ($existing->num_rows > 0) {
                $error = $email === null ? 'Username already exists.' : 'Username or email already exists.';
            } elseif (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ?, full_name = ?, phone = ?, status = ? WHERE id = ? AND role = 'admin'");
                $stmt->bind_param("ssssssi", $username, $email, $hashed_password, $full_name, $phone, $status, $admin_id);

                if ($stmt->execute()) {
                    $success = 'Admin account updated successfully.';
                } else {
                    $error = 'Unable to update admin account. Please try again.';
                }
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, phone = ?, status = ? WHERE id = ? AND role = 'admin'");
                $stmt->bind_param("sssssi", $username, $email, $full_name, $phone, $status, $admin_id);

                if ($stmt->execute()) {
                    $success = 'Admin account updated successfully.';
                } else {
                    $error = 'Unable to update admin account. Please try again.';
                }
            }

            $stmt->close();
        }
    }

    if ($admin_action === 'toggle_admin_status') {
        $admin_id = intval($_POST['admin_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ? AND role = 'admin'");
        $stmt->bind_param("i", $admin_id);

        if ($stmt->execute()) {
            $success = 'Admin status updated successfully.';
        } else {
            $error = 'Unable to update admin status. Please try again.';
        }

        $stmt->close();
    }

    if ($admin_action === 'delete_admin') {
        $admin_id = intval($_POST['admin_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
        $stmt->bind_param("i", $admin_id);

        if ($stmt->execute()) {
            $success = 'Admin account removed successfully.';
        } else {
            $error = 'Unable to remove admin account. Please try again.';
        }

        $stmt->close();
    }
}

// Handle agent status update
if (isset($_GET['toggle_status'])) {
    $user_id = intval($_GET['toggle_status']);
    $conn->query("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = $user_id AND role = 'agent'");
    header('Location: admin.php');
    exit();
}

// Handle agent deletion
if (isset($_GET['delete_user'])) {
    $user_id = intval($_GET['delete_user']);
    $conn->query("DELETE FROM users WHERE id = $user_id AND role = 'agent'");
    header('Location: admin.php');
    exit();
}

// Get all agents
$users = $conn->query("SELECT u.*, 
                       (SELECT COUNT(*) FROM cars WHERE user_id = u.id) as total_cars,
                       (SELECT COUNT(*) FROM rentals WHERE user_id = u.id) as total_rentals,
                       (SELECT SUM(total_paid) FROM rentals WHERE user_id = u.id) as total_revenue
                       FROM users u 
                       WHERE u.role = 'agent' 
                       ORDER BY u.created_at DESC");

// Get all admins for superadmin
$admins = false;
$admin_accounts = [];
if (isSuperAdmin()) {
    $admins = $conn->query("SELECT id, username, email, full_name, phone, status, created_at
                            FROM users
                            WHERE role = 'admin'
                            ORDER BY created_at DESC");

    if ($admins) {
        while ($admin = $admins->fetch_assoc()) {
            $admin_accounts[] = $admin;
        }
    }
}

// Get system statistics
$total_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'agent'")->fetch_assoc()['count'];
$total_admins = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetch_assoc()['count'];
$total_cars = $conn->query("SELECT COUNT(*) as count FROM cars")->fetch_assoc()['count'];
$total_rentals = $conn->query("SELECT COUNT(*) as count FROM rentals")->fetch_assoc()['count'];
$total_revenue = $conn->query("SELECT SUM(total_paid) as total FROM rentals")->fetch_assoc()['total'] ?? 0;
$total_maintenance_spent = $conn->query("SELECT COALESCE(SUM(cost), 0) as total FROM maintenance_records")->fetch_assoc()['total'] ?? 0;

include 'includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2>Admin Panel</h2>
        <p class="text-muted">System overview and user management</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Total Agents</p>
                        <h3 class="mb-0"><?php echo e($total_users); ?></h3>
                    </div>
                    <div class="bg-primary bg-opacity-10 p-3 rounded">
                        <i class="bi bi-people fs-2 text-primary"></i>
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
                        <p class="text-muted mb-1">Total Admins</p>
                        <h3 class="mb-0"><?php echo e($total_admins); ?></h3>
                    </div>
                    <div class="bg-info bg-opacity-10 p-3 rounded">
                        <i class="bi bi-person-gear fs-2 text-info"></i>
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
                        <p class="text-muted mb-1">Total Rentals</p>
                        <h3 class="mb-0"><?php echo e($total_rentals); ?></h3>
                    </div>
                    <div class="bg-warning bg-opacity-10 p-3 rounded">
                        <i class="bi bi-clipboard-check fs-2 text-warning"></i>
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
                        <p class="text-muted mb-1">Total Revenue</p>
                        <h3 class="mb-0"><?php echo e(formatCurrency($total_revenue)); ?></h3>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded">
                        <i class="bi bi-currency-dollar fs-2 text-success"></i>
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
                        <p class="text-muted mb-1">Maintenance Spent</p>
                        <h3 class="mb-0"><?php echo e(formatCurrency($total_maintenance_spent)); ?></h3>
                    </div>
                    <div class="bg-secondary bg-opacity-10 p-3 rounded">
                        <i class="bi bi-cash-coin fs-2 text-secondary"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (isSuperAdmin()): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Admin Accounts</h5>
        <button type="button" class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#addAdminModal">
            <i class="bi bi-person-plus me-1"></i>Add Admin
        </button>
    </div>
    <div class="card-body">
        <?php if (count($admin_accounts) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admin_accounts as $admin): ?>
                    <tr>
                        <td><strong><?php echo e($admin['username']); ?></strong></td>
                        <td><?php echo e($admin['full_name']); ?></td>
                        <td><?php echo e($admin['email'] ?: 'N/A'); ?></td>
                        <td><?php echo e($admin['phone'] ?: 'N/A'); ?></td>
                        <td>
                            <?php if ($admin['status'] == 'active'): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Suspended</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(formatDate($admin['created_at'])); ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editAdminModal<?php echo e($admin['id']); ?>" title="Edit admin">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="" class="d-inline">
                                <input type="hidden" name="admin_action" value="toggle_admin_status">
                                <input type="hidden" name="admin_id" value="<?php echo e($admin['id']); ?>">
                                <button type="submit" class="btn btn-sm btn-warning" title="<?php echo $admin['status'] == 'active' ? 'Suspend admin' : 'Activate admin'; ?>" onclick="return confirm('Are you sure you want to change this admin status?');">
                                    <i class="bi <?php echo $admin['status'] == 'active' ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                                </button>
                            </form>
                            <form method="POST" action="" class="d-inline">
                                <input type="hidden" name="admin_action" value="delete_admin">
                                <input type="hidden" name="admin_id" value="<?php echo e($admin['id']); ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Remove admin" onclick="return confirm('Are you sure you want to remove this admin account?');">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-4">
            <p class="text-muted mb-3">No admin accounts found.</p>
            <button type="button" class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                <i class="bi bi-person-plus me-1"></i>Add Admin
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($admin_accounts as $admin): ?>
<div class="modal fade" id="editAdminModal<?php echo e($admin['id']); ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="admin_action" value="edit_admin">
                <input type="hidden" name="admin_id" value="<?php echo e($admin['id']); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" value="<?php echo e($admin['username']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?php echo e($admin['email']); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" value="<?php echo e($admin['full_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone" value="<?php echo e($admin['phone']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="active" <?php echo $admin['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $admin['status'] == 'inactive' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="password">
                            <small class="text-muted">Leave blank to keep current password.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" name="confirm_password">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="modal fade" id="addAdminModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="admin_action" value="add_admin">
                <div class="modal-header">
                    <h5 class="modal-title">Add Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Suspended</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="confirm_password" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark">Create Admin</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h5 class="mb-0">Registered Agents/Companies</h5>
    </div>
    <div class="card-body">
        <?php if ($users->num_rows > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Company</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Cars</th>
                        <th>Rentals</th>
                        <th>Revenue</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($user = $users->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo e($user['username']); ?></strong></td>
                        <td><?php echo e($user['full_name']); ?></td>
                        <td><?php echo e($user['company_name'] ?? 'Individual'); ?></td>
                        <td><?php echo e($user['email']); ?></td>
                        <td><?php echo e($user['phone'] ?? 'N/A'); ?></td>
                        <td><?php echo e($user['total_cars']); ?></td>
                        <td><?php echo e($user['total_rentals']); ?></td>
                        <td><?php echo e(formatCurrency($user['total_revenue'] ?? 0)); ?></td>
                        <td>
                            <?php if ($user['status'] == 'active'): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(formatDate($user['created_at'])); ?></td>
                        <td>
                            <a href="admin.php?toggle_status=<?php echo e($user['id']); ?>" class="btn btn-sm btn-warning"
                               onclick="return confirm('Are you sure you want to toggle this user status?');">
                                <i class="bi bi-toggle-on"></i>
                            </a>
                            <a href="admin.php?delete_user=<?php echo e($user['id']); ?>" class="btn btn-sm btn-danger"
                               onclick="return confirm('Are you sure you want to delete this user? All their data will be removed.');">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted text-center py-4">No agents registered yet</p>
        <?php endif; ?>
    </div>
</div>

<?php
closeDBConnection($conn);
include 'includes/footer.php';
?>
