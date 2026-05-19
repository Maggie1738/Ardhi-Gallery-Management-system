<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header("Location: login.php");
    exit();
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $commission_rate = floatval($_POST['commission_rate'] ?? 70.00);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $payment_status = $_POST['payment_status'] ?? 'pending';
    
    // Validate
    if (empty($first_name) || empty($last_name)) {
        $errors[] = "First name and last name are required";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email is required";
    }
    if ($commission_rate < 0 || $commission_rate > 100) {
        $errors[] = "Commission rate must be between 0 and 100";
    }
    
    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM artists WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        $errors[] = "Email already registered";
    }
    
    if (empty($errors)) {
        try {
            $query = "INSERT INTO artists (first_name, last_name, email, phone, address, 
                     bio, website, commission_rate, is_active, payment_status, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $db->prepare($query);
            $stmt->execute([
                $first_name,
                $last_name,
                $email,
                $phone,
                $address,
                $bio,
                $website,
                $commission_rate,
                $is_active,
                $payment_status
            ]);
            
            $_SESSION['success'] = "Artist added successfully!";
            header("Location: artists.php");
            exit();
            
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Artist - Ardhi Gallery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php 
    if (file_exists('navigation.php')) {
        require_once 'navigation.php';
        echo renderNavigation('admin');
    }
    ?>
    
    <div class="container mt-4">
        <h1>Add New Artist</h1>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">First Name *</label>
                <input type="text" class="form-control" name="first_name" required>
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Last Name *</label>
                <input type="text" class="form-control" name="last_name" required>
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Email *</label>
                <input type="email" class="form-control" name="email" required>
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" name="phone">
            </div>
            
            <div class="col-12">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="2"></textarea>
            </div>
            
            <div class="col-12">
                <label class="form-label">Bio</label>
                <textarea class="form-control" name="bio" rows="4"></textarea>
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Website</label>
                <input type="url" class="form-control" name="website">
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Commission Rate (%)</label>
                <input type="number" class="form-control" name="commission_rate" 
                       min="0" max="100" step="0.01" value="70.00">
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Payment Status</label>
                <select class="form-select" name="payment_status">
                    <option value="pending">Pending</option>
                    <option value="paid">Paid</option>
                    <option value="overdue">Overdue</option>
                </select>
            </div>
            
            <div class="col-md-6">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                    <label class="form-check-label">Active Artist</label>
                </div>
            </div>
            
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Add Artist</button>
                <a href="artists.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>