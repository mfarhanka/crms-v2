<?php
require_once 'config/config.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $quick_login = sanitize($_POST['quick_login'] ?? '');

    if ($quick_login === 'admin') {
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'admin';
        $_SESSION['full_name'] = 'System Administrator';
        $_SESSION['role'] = 'admin';

        header('Location: dashboard.php');
        exit();
    }

    if ($quick_login === 'customer') {
        $conn = getDBConnection();
        ensureCustomerPortalSchema($conn);
        $customer = $conn->query("SELECT access_token FROM customers ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
        closeDBConnection($conn);

        if ($customer && !empty($customer['access_token'])) {
            header('Location: customer_portal.php?token=' . urlencode($customer['access_token']));
            exit();
        }

        $error = 'No customer found for quick customer portal testing.';
    }

    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!$error && (empty($username) || empty($password))) {
        $error = 'Please fill in all fields';
    } elseif (!$error) {
        // Hardcoded superadmin credentials
        if ($username === 'superadmin' && $password === 'superadmin') {
            $_SESSION['user_id'] = 0; // Special ID for superadmin
            $_SESSION['username'] = 'superadmin';
            $_SESSION['full_name'] = 'Super Administrator';
            $_SESSION['role'] = 'superadmin';
            
            header('Location: dashboard.php');
            exit();
        }
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT id, username, password, full_name, role, status FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            if ($user['status'] == 'inactive') {
                $error = 'Your account has been deactivated. Please contact administrator.';
            } elseif (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                
                header('Location: dashboard.php');
                exit();
            } else {
                $error = 'Invalid username or password';
            }
        } else {
            $error = 'Invalid username or password';
        }
        
        $stmt->close();
        closeDBConnection($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-5">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <i class="bi bi-car-front-fill fs-1 text-dark"></i>
                            <h3 class="mt-2">Car Rental System</h3>
                            <p class="text-muted">Sign in to your account</p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username or Email</label>
                                <input type="text" class="form-control" id="username" name="username" required autofocus>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            
                            <button type="submit" class="btn btn-dark w-100 mb-3">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                            </button>
                            
                            <div class="text-center">
                                <p class="text-muted">Don't have an account? <a href="signup.php" class="text-dark fw-bold">Sign Up</a></p>
                            </div>
                        </form>

                        <div class="mt-4 pt-3 border-top">
                            <div class="d-grid gap-2">
                                <form method="POST" action="">
                                    <input type="hidden" name="quick_login" value="admin">
                                    <button type="submit" class="btn btn-outline-dark w-100">
                                        <i class="bi bi-lightning-charge me-2"></i>Speed Login Admin
                                    </button>
                                </form>
                                <form method="POST" action="">
                                    <input type="hidden" name="quick_login" value="customer">
                                    <button type="submit" class="btn btn-outline-info w-100">
                                        <i class="bi bi-person-badge me-2"></i>Speed Login Customer
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-3 border-top">
                            <small class="text-muted d-block text-center">
                                Demo: username: <strong>admin</strong> / password: <strong>admin123</strong>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
