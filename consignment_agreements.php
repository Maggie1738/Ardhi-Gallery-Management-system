<?php
session_start();
require_once 'config.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';
$agreements = [];
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Function to generate agreement number
function generateAgreementNumber($db) {
    $year = date('Y');
    $prefix = "CONS-{$year}-";
    
    $query = "SELECT MAX(CAST(SUBSTRING(agreement_number, LENGTH(?) + 1) AS UNSIGNED)) as max_seq 
              FROM consignment_agreements 
              WHERE agreement_number LIKE ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$prefix, $prefix . '%']);
    $result = $stmt->fetch();
    
    $sequence = ($result['max_seq'] ?? 0) + 1;
    return $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);
}

// Handle Create New Agreement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $artist_id = $_POST['artist_id'] ?? 0;
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $commission_rate = $_POST['commission_rate'] ?? 30;
    $notes = $_POST['notes'] ?? '';
    
    if (!$artist_id || !$start_date || !$end_date) {
        $error = "Please fill in all required fields";
    } else {
        try {
            $db->beginTransaction();
            
            // Generate unique agreement number
            $agreement_number = generateAgreementNumber($db);
            
            // Insert agreement
            $insert_query = "INSERT INTO consignment_agreements 
                            (artist_id, agreement_number, start_date, end_date, 
                             commission_rate, status, notes, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())";
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([
                $artist_id,
                $agreement_number,
                $start_date,
                $end_date,
                $commission_rate,
                $notes
            ]);
            
            $agreement_id = $db->lastInsertId();
            
            $db->commit();
            
            $_SESSION['success'] = "Consignment agreement created successfully! Agreement #: $agreement_number";
            header("Location: consignments.php");
            exit();
            
        } catch (PDOException $e) {
            $db->rollBack();
            $error = "Error creating agreement: " . $e->getMessage();
            error_log("Consignment create error: " . $e->getMessage());
        }
    }
}

// Handle Update Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $agreement_id = $_POST['agreement_id'] ?? 0;
    $new_status = $_POST['status'] ?? '';
    
    if ($agreement_id && in_array($new_status, ['active', 'expired', 'terminated', 'completed'])) {
        try {
            $update_stmt = $db->prepare("UPDATE consignment_agreements SET status = ?, updated_at = NOW() WHERE id = ?");
            $update_stmt->execute([$new_status, $agreement_id]);
            
            $_SESSION['success'] = "Agreement status updated successfully!";
            header("Location: consignments.php");
            exit();
            
        } catch (PDOException $e) {
            $error = "Failed to update status: " . $e->getMessage();
        }
    }
}

// Handle Edit Agreement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $agreement_id = $_POST['agreement_id'] ?? 0;
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $commission_rate = $_POST['commission_rate'] ?? 30;
    $notes = $_POST['notes'] ?? '';
    
    if ($agreement_id && $start_date && $end_date) {
        try {
            $update_query = "UPDATE consignment_agreements 
                            SET start_date = ?, end_date = ?, commission_rate = ?, 
                                notes = ?, updated_at = NOW() 
                            WHERE id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([$start_date, $end_date, $commission_rate, $notes, $agreement_id]);
            
            $_SESSION['success'] = "Agreement updated successfully!";
            header("Location: consignments.php");
            exit();
            
        } catch (PDOException $e) {
            $error = "Failed to update agreement: " . $e->getMessage();
        }
    }
}

// Get artists for dropdown
$artists = $db->query("SELECT id, first_name, last_name, email FROM artists ORDER BY first_name")->fetchAll();

// Get agreements with filters
try {
    $query = "
        SELECT 
            ca.*,
            CONCAT(a.first_name, ' ', a.last_name) as artist_name,
            a.email as artist_email,
            a.phone as artist_phone,
            COUNT(DISTINCT cart.id) as artwork_count,
            COALESCE(SUM(cart.consignment_price), 0) as total_value,
            COALESCE(AVG(cart.commission_rate), ca.commission_rate) as avg_commission_rate
        FROM consignment_agreements ca
        JOIN artists a ON ca.artist_id = a.id
        LEFT JOIN consignment_artworks cart ON ca.id = cart.agreement_id AND cart.status != 'removed'
    ";
    
    $where_clauses = [];
    $params = [];
    
    // Apply status filter
    if ($status_filter !== 'all') {
        $where_clauses[] = "ca.status = ?";
        $params[] = $status_filter;
    }
    
    // Apply search filter
    if (!empty($search)) {
        $where_clauses[] = "(ca.agreement_number LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ? OR a.email LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // Add WHERE clause if needed
    if (!empty($where_clauses)) {
        $query .= " WHERE " . implode(" AND ", $where_clauses);
    }
    
    $query .= " GROUP BY ca.id, a.first_name, a.last_name, a.email, a.phone
                ORDER BY 
                    CASE ca.status 
                        WHEN 'active' THEN 1 
                        WHEN 'expired' THEN 2 
                        WHEN 'completed' THEN 3 
                        ELSE 4 
                    END,
                    ca.end_date ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $agreements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    error_log("Consignments query error: " . $e->getMessage());
}

// Get session messages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consignment Agreements - Ardhi Gallery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 0;
            margin-bottom: 30px;
            border-radius: 0 0 30px 30px;
        }
        
        .agreement-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            overflow: hidden;
            position: relative;
        }
        
        .agreement-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(102,126,234,0.2);
        }
        
        .agreement-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        
        .status-badge {
            padding: 6px 15px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        
        .status-active {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            box-shadow: 0 5px 15px rgba(40,167,69,0.2);
        }
        
        .status-expired {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
        }
        
        .status-terminated {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }
        
        .status-completed {
            background: linear-gradient(135deg, #007bff, #6610f2);
            color: white;
        }
        
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .agreement-header {
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        
        .value-badge {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 10px 20px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1.1rem;
            display: inline-block;
            box-shadow: 0 5px 15px rgba(40,167,69,0.3);
        }
        
        .days-count {
            font-size: 0.8rem;
            padding: 4px 12px;
            border-radius: 50px;
            margin-left: 10px;
            font-weight: 600;
        }
        
        .days-remaining {
            background: #d4edda;
            color: #155724;
        }
        
        .days-expired {
            background: #f8d7da;
            color: #721c24;
        }
        
        .artist-avatar {
            width: 55px;
            height: 55px;
            border-radius: 15px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.3rem;
            box-shadow: 0 5px 15px rgba(102,126,234,0.3);
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            height: 100%;
            border: 1px solid #f0f0f0;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: #667eea;
        }
        
        .modal-content {
            border-radius: 20px;
            border: none;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 20px 30px;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .btn-create {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(40,167,69,0.3);
        }
        
        .btn-export {
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 500;
        }
        
        .info-row {
            padding: 10px;
            border-bottom: 1px dashed #e9ecef;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #6c757d;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-weight: 600;
            color: #333;
        }
        
        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 2rem;
            }
        }

        /* Form Styles */
        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102,126,234,0.1);
        }
        
        .input-group-text {
            border-radius: 12px;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include 'navigation.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="display-5 fw-bold">
                        <i class="fas fa-file-contract me-3"></i>Consignment Agreements
                    </h1>
                    <p class="lead mb-0">Manage artist consignment contracts and artwork</p>
                </div>
                <div>
                    <button class="btn btn-light me-2" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createModal">
                        <i class="fas fa-plus-circle me-2"></i>New Agreement
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container-fluid px-4 mb-5">
        <!-- Notifications -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filter Card -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Status</label>
                    <select class="form-control" name="status">
                        <option value="all" <?php echo ($status_filter == 'all') ? 'selected' : ''; ?>>All Agreements</option>
                        <option value="active" <?php echo ($status_filter == 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="expired" <?php echo ($status_filter == 'expired') ? 'selected' : ''; ?>>Expired</option>
                        <option value="terminated" <?php echo ($status_filter == 'terminated') ? 'selected' : ''; ?>>Terminated</option>
                        <option value="completed" <?php echo ($status_filter == 'completed') ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" class="form-control" name="search" 
                           placeholder="Agreement number, artist name, or email..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 py-3">
                        <i class="fas fa-search me-2"></i>Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Summary Stats -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="stat-number"><?php echo count($agreements); ?></span>
                            <div class="text-muted">Total Agreements</div>
                        </div>
                        <div class="bg-primary bg-opacity-10 p-3 rounded-3">
                            <i class="fas fa-file-contract fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="stat-number" style="color: #28a745;">
                                <?php 
                                    $active_count = array_filter($agreements, function($a) {
                                        return $a['status'] == 'active';
                                    });
                                    echo count($active_count);
                                ?>
                            </span>
                            <div class="text-muted">Active</div>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded-3">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="stat-number" style="color: #28a745;">
                                KSh <?php 
                                    $total_value = array_sum(array_column($agreements, 'total_value'));
                                    echo number_format($total_value, 0);
                                ?>
                            </span>
                            <div class="text-muted">Total Value</div>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded-3">
                            <i class="fas fa-coins fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="stat-number" style="color: #17a2b8;">
                                <?php 
                                    $total_artworks = array_sum(array_column($agreements, 'artwork_count'));
                                    echo $total_artworks;
                                ?>
                            </span>
                            <div class="text-muted">Artworks</div>
                        </div>
                        <div class="bg-info bg-opacity-10 p-3 rounded-3">
                            <i class="fas fa-palette fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Agreements List -->
        <?php if (!empty($agreements)): ?>
            <div class="row">
                <?php foreach ($agreements as $agreement): 
                    $days_remaining = floor((strtotime($agreement['end_date']) - time()) / (60 * 60 * 24));
                    $is_expired = $days_remaining < 0;
                    
                    // Get artist initials for avatar
                    $name_parts = explode(' ', $agreement['artist_name']);
                    $initials = '';
                    foreach ($name_parts as $part) {
                        if (!empty($part)) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }
                    }
                    $initials = substr($initials, 0, 2);
                ?>
                <div class="col-xl-6">
                    <div class="agreement-card">
                        <div class="card-body p-4">
                            <!-- Header -->
                            <div class="d-flex justify-content-between align-items-start mb-4">
                                <div class="d-flex align-items-center">
                                    <div class="artist-avatar me-3">
                                        <?php echo $initials ?: 'A'; ?>
                                    </div>
                                    <div>
                                        <h5 class="mb-1 fw-bold"><?php echo htmlspecialchars($agreement['agreement_number']); ?></h5>
                                        <p class="text-muted mb-0">
                                            <i class="fas fa-calendar-alt me-1"></i>
                                            Created: <?php echo date('M d, Y', strtotime($agreement['created_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <span class="status-badge status-<?php echo $agreement['status']; ?>">
                                        <?php echo strtoupper($agreement['status']); ?>
                                    </span>
                                    <?php if ($agreement['status'] == 'active'): ?>
                                        <span class="days-count <?php echo $is_expired ? 'days-expired' : 'days-remaining'; ?>">
                                            <i class="fas fa-clock me-1"></i>
                                            <?php echo $is_expired ? 'Expired' : $days_remaining . ' days left'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Artist Info -->
                            <div class="bg-light p-3 rounded-3 mb-3">
                                <div class="row">
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Artist</small>
                                        <strong class="fs-5"><?php echo htmlspecialchars($agreement['artist_name']); ?></strong>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Contact</small>
                                        <div><?php echo htmlspecialchars($agreement['artist_email']); ?></div>
                                        <?php if (!empty($agreement['artist_phone'])): ?>
                                            <small><?php echo htmlspecialchars($agreement['artist_phone']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Agreement Details Grid -->
                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <div class="info-row">
                                        <div class="info-label">Start Date</div>
                                        <div class="info-value">
                                            <i class="fas fa-calendar-plus me-2 text-primary"></i>
                                            <?php echo date('M d, Y', strtotime($agreement['start_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-row">
                                        <div class="info-label">End Date</div>
                                        <div class="info-value">
                                            <i class="fas fa-calendar-times me-2 text-danger"></i>
                                            <?php echo date('M d, Y', strtotime($agreement['end_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-row">
                                        <div class="info-label">Commission Rate</div>
                                        <div class="info-value">
                                            <i class="fas fa-percentage me-2 text-success"></i>
                                            <?php echo number_format($agreement['commission_rate'], 1); ?>%
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-row">
                                        <div class="info-label">Artworks</div>
                                        <div class="info-value">
                                            <i class="fas fa-palette me-2 text-info"></i>
                                            <?php echo $agreement['artwork_count']; ?> consigned
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Total Value -->
                            <div class="text-center mb-4">
                                <span class="value-badge">
                                    <i class="fas fa-tag me-2"></i>
                                    KSh <?php echo number_format($agreement['total_value'], 0); ?>
                                </span>
                            </div>
                            
                            <!-- Notes if any -->
                            <?php if (!empty($agreement['notes'])): ?>
                                <div class="alert alert-secondary py-2 mb-3">
                                    <small><i class="fas fa-sticky-note me-2"></i><?php echo htmlspecialchars($agreement['notes']); ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Actions -->
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <a href="view_agreement.php?id=<?php echo $agreement['id']; ?>" 
                                       class="btn btn-outline-primary btn-sm me-2">
                                        <i class="fas fa-eye me-1"></i>View Details
                                    </a>
                                    <a href="consigned_artworks.php?agreement_id=<?php echo $agreement['id']; ?>" 
                                       class="btn btn-outline-success btn-sm">
                                        <i class="fas fa-palette me-1"></i>Artworks
                                    </a>
                                    <button class="btn btn-outline-secondary btn-sm" 
                                            onclick="editAgreement(<?php echo htmlspecialchars(json_encode($agreement)); ?>)">
                                        <i class="fas fa-edit me-1"></i>Edit
                                    </button>
                                </div>
                                <div>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="agreement_id" value="<?php echo $agreement['id']; ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <select name="status" class="form-select form-select-sm" 
                                                onchange="if(confirm('Change agreement status to ' + this.value + '?')) this.form.submit()">
                                            <option value="active" <?php echo ($agreement['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="expired" <?php echo ($agreement['status'] == 'expired') ? 'selected' : ''; ?>>Expired</option>
                                            <option value="terminated" <?php echo ($agreement['status'] == 'terminated') ? 'selected' : ''; ?>>Terminated</option>
                                            <option value="completed" <?php echo ($agreement['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                        </select>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state">
                <i class="fas fa-file-contract"></i>
                <h3 class="h4 mb-3">No Agreements Found</h3>
                <p class="text-muted mb-4">
                    <?php if ($status_filter !== 'all' || !empty($search)): ?>
                        No agreements match your search criteria.<br>
                        Try adjusting your filters or clear the search.
                    <?php else: ?>
                        Get started by creating your first consignment agreement.
                    <?php endif; ?>
                </p>
                <?php if ($status_filter !== 'all' || !empty($search)): ?>
                    <a href="consignments.php" class="btn btn-primary">
                        <i class="fas fa-times me-2"></i>Clear Filters
                    </a>
                <?php else: ?>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createModal">
                        <i class="fas fa-plus-circle me-2"></i>Create Agreement
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create Agreement Modal -->
    <div class="modal fade" id="createModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>Create Consignment Agreement
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Artist *</label>
                            <select name="artist_id" class="form-control" required>
                                <option value="">Select Artist</option>
                                <?php foreach ($artists as $artist): ?>
                                    <option value="<?php echo $artist['id']; ?>">
                                        <?php echo htmlspecialchars($artist['first_name'] . ' ' . $artist['last_name'] . ' (' . $artist['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Start Date *</label>
                                <input type="date" name="start_date" class="form-control" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">End Date *</label>
                                <input type="date" name="end_date" class="form-control" 
                                       value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Commission Rate (%) *</label>
                            <div class="input-group">
                                <input type="number" name="commission_rate" class="form-control" 
                                       value="30" min="1" max="100" step="0.1" required>
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-muted">Standard gallery commission is 30%</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Notes / Special Terms</label>
                            <textarea name="notes" class="form-control" rows="3" 
                                      placeholder="Any special conditions or notes about this agreement..."></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Auto-generated Agreement Number:</strong> CONS-<?php echo date('Y'); ?>-XXXX
                            <br>
                            <small>The agreement number will be automatically generated when you create the agreement.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Create Agreement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Agreement Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Agreement
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="agreement_id" id="edit_id">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Agreement Number</label>
                            <input type="text" class="form-control" id="edit_agreement_number" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Artist</label>
                            <input type="text" class="form-control" id="edit_artist" readonly>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Start Date *</label>
                                <input type="date" name="start_date" id="edit_start_date" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">End Date *</label>
                                <input type="date" name="end_date" id="edit_end_date" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Commission Rate (%) *</label>
                            <div class="input-group">
                                <input type="number" name="commission_rate" id="edit_commission" 
                                       class="form-control" min="1" max="100" step="0.1" required>
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Agreement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editAgreement(agreement) {
            document.getElementById('edit_id').value = agreement.id;
            document.getElementById('edit_agreement_number').value = agreement.agreement_number;
            document.getElementById('edit_artist').value = agreement.artist_name;
            document.getElementById('edit_start_date').value = agreement.start_date.split(' ')[0];
            document.getElementById('edit_end_date').value = agreement.end_date.split(' ')[0];
            document.getElementById('edit_commission').value = agreement.commission_rate;
            document.getElementById('edit_notes').value = agreement.notes || '';
            
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        function exportToCSV() {
            let csv = [];
            
            // Headers
            csv.push([
                'Agreement Number',
                'Artist',
                'Status',
                'Start Date',
                'End Date',
                'Commission Rate',
                'Artworks',
                'Total Value',
                'Created'
            ].join(','));
            
            // Get data from page
            <?php foreach ($agreements as $agreement): ?>
            csv.push([
                '<?php echo $agreement['agreement_number']; ?>',
                '<?php echo addslashes($agreement['artist_name']); ?>',
                '<?php echo $agreement['status']; ?>',
                '<?php echo $agreement['start_date']; ?>',
                '<?php echo $agreement['end_date']; ?>',
                '<?php echo $agreement['commission_rate']; ?>%',
                '<?php echo $agreement['artwork_count']; ?>',
                '<?php echo $agreement['total_value']; ?>',
                '<?php echo $agreement['created_at']; ?>'
            ].join(','));
            <?php endforeach; ?>
            
            // Download CSV
            const csvString = csv.join('\n');
            const blob = new Blob([csvString], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'agreements_<?php echo date('Y-m-d'); ?>.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        // Auto-dismiss alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                const bsAlert = bootstrap.Alert.getInstance(alert);
                if (bsAlert) {
                    bsAlert.close();
                }
            });
        }, 5000);
    </script>
</body>
</html>