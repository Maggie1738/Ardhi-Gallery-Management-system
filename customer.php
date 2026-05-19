<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isCustomer()) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'] ?? '';

// Initialize variables
$stats = [
    'art_purchases' => 0,
    'event_bookings' => 0,
    'gallery_visits' => 0,
    'total_spent' => 0,
    'total_entrance' => 0
];

$recent_payments = [];
$invoices = [];
$purchase_history = [];

try {
    // Check if payments table exists
    $table_check = $db->query("SHOW TABLES LIKE 'payments'");
    if ($table_check->rowCount() == 0) {
        throw new Exception("Payments system not set up.");
    }

    // Get customer stats from payments table
    $stats_query = "
        SELECT 
            SUM(CASE WHEN payment_type IN ('art_purchase', 'artwork') THEN 1 ELSE 0 END) as art_purchases,
            SUM(CASE WHEN payment_type IN ('event_booking', 'event') THEN 1 ELSE 0 END) as event_bookings,
            SUM(CASE WHEN payment_type IN ('entrance_fee', 'entrance') THEN 1 ELSE 0 END) as gallery_visits,
            COALESCE(SUM(amount), 0) as total_spent,
            COALESCE(SUM(CASE WHEN payment_type IN ('entrance_fee', 'entrance') THEN amount ELSE 0 END), 0) as total_entrance
        FROM payments 
        WHERE user_id = ?
    ";
    
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->execute([$user_id]);
    $stats = $stats_stmt->fetch();

    // Get recent payments
    $recent_payments_query = "
        SELECT p.*, a.title as artwork_title, a.artist_name 
        FROM payments p
        LEFT JOIN artworks a ON p.artwork_id = a.id
        WHERE p.user_id = ? 
        ORDER BY p.created_at DESC 
        LIMIT 5
    ";
    
    $recent_payments_stmt = $db->prepare($recent_payments_query);
    $recent_payments_stmt->execute([$user_id]);
    $recent_payments = $recent_payments_stmt->fetchAll();

    // Get invoices
    $invoices_query = "
        SELECT i.*, p.receipt_number, p.payment_method
        FROM invoices i
        LEFT JOIN payments p ON i.payment_id = p.id
        WHERE i.customer_id = ? 
        ORDER BY i.created_at DESC 
        LIMIT 3
    ";
    
    $invoices_stmt = $db->prepare($invoices_query);
    $invoices_stmt->execute([$user_id]);
    $invoices = $invoices_stmt->fetchAll();

    // Get purchase history (detailed)
    $purchase_history_query = "
        SELECT 
            p.*,
            a.title as artwork_title,
            a.artist_name,
            a.image_url,
            i.invoice_number,
            r.receipt_number as receipt_no
        FROM payments p
        LEFT JOIN artworks a ON p.artwork_id = a.id
        LEFT JOIN invoices i ON i.payment_id = p.id
        LEFT JOIN receipts r ON r.payment_id = p.id
        WHERE p.user_id = ? 
        AND p.payment_type IN ('art_purchase', 'artwork')
        ORDER BY p.created_at DESC 
        LIMIT 3
    ";
    
    $purchase_history_stmt = $db->prepare($purchase_history_query);
    $purchase_history_stmt->execute([$user_id]);
    $purchase_history = $purchase_history_stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Customer dashboard error: " . $e->getMessage());
    $error = "Unable to load dashboard data. Please try again later.";
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard - Ardhi Gallery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card { 
            border: none; 
            border-radius: 15px; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
            transition: transform 0.2s; 
            height: 100%;
        }
        .stat-card:hover { 
            transform: translateY(-5px); 
        }
        .dashboard-header { 
            background: linear-gradient(135deg, #ea6683 0%, #a24b52 100%); 
            color: white; 
            padding: 2rem; 
            border-radius: 15px; 
            margin-bottom: 2rem; 
        }
        .action-card { 
            height: 100%; 
            border: none; 
            border-radius: 10px; 
            transition: all 0.3s ease; 
            border: 1px solid #dee2e6;
        }
        .action-card:hover { 
            transform: translateY(-3px); 
            box-shadow: 0 5px 15px rgba(0,0,0,0.1); 
        }
        .artwork-thumb {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }
        .badge-payment {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
        .invoice-row:hover {
            background-color: #231547;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="customer.php">
                <i class="fas fa-palette"></i> Ardhi Gallery
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($user_name); ?>
                </span>
                <a class="nav-link" href="art_gallery.php">
                    <i class="fas fa-paint-brush me-1"></i> Buy Art
                </a>
                <a class="nav-link" href="events.php">
                    <i class="fas fa-calendar-alt me-1"></i> Book Events
                </a>
                <!-- FIXED: Changed from entrance.php to customer_entrance.php -->
                <a class="nav-link" href="customer_entrance.php">
                    <i class="fas fa-ticket-alt me-1"></i> Pay Entrance
                </a>
                <a class="nav-link active" href="customer.php">
                    <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Error Message -->
        <?php if (isset($error)): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Note:</strong> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Welcome Header -->
        <div class="dashboard-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 fw-bold">My Art Collection</h1>
                    <p class="lead mb-0">Welcome back, <?php echo htmlspecialchars($user_name); ?>! Manage your purchases and gallery visits.</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="bg-white bg-opacity-25 p-3 rounded">
                        <div class="h4 mb-1"><?php echo date('H:i'); ?></div>
                        <small><?php echo date('l, F j, Y'); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card text-white bg-primary">
                    <div class="card-body text-center py-4">
                        <i class="fas fa-palette fa-2x mb-2"></i>
                        <h5 class="card-title">Art Purchases</h5>
                        <h2 class="display-6 fw-bold"><?php echo $stats['art_purchases'] ?? 0; ?></h2>
                        <small>Artworks owned</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card text-white bg-success">
                    <div class="card-body text-center py-4">
                        <i class="fas fa-calendar-check fa-2x mb-2"></i>
                        <h5 class="card-title">Events Attended</h5>
                        <h2 class="display-6 fw-bold"><?php echo $stats['event_bookings'] ?? 0; ?></h2>
                        <small>Gallery events</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card text-white bg-warning">
                    <div class="card-body text-center py-4">
                        <i class="fas fa-door-open fa-2x mb-2"></i>
                        <h5 class="card-title">Gallery Visits</h5>
                        <h2 class="display-6 fw-bold"><?php echo $stats['gallery_visits'] ?? 0; ?></h2>
                        <small>Total: KSh <?php echo number_format($stats['total_entrance'] ?? 0, 0); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card text-white bg-info">
                    <div class="card-body text-center py-4">
                        <i class="fas fa-money-bill-wave fa-2x mb-2"></i>
                        <h5 class="card-title">Total Spent</h5>
                        <h2 class="display-6 fw-bold">KSh <?php echo number_format($stats['total_spent'] ?? 0, 0); ?></h2>
                        <small>All purchases</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Purchase History -->
            <div class="col-md-8">
                <!-- Purchase History -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Purchases</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($purchase_history)): ?>
                            <div class="list-group">
                                <?php foreach ($purchase_history as $purchase): ?>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <?php if (!empty($purchase['image_url'])): ?>
                                                <img src="<?php echo htmlspecialchars($purchase['image_url']); ?>" 
                                                     alt="<?php echo htmlspecialchars($purchase['artwork_title']); ?>" 
                                                     class="artwork-thumb">
                                            <?php else: ?>
                                                <div class="artwork-thumb bg-light d-flex align-items-center justify-content-center">
                                                    <i class="fas fa-palette text-muted"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($purchase['artwork_title'] ?? 'Purchase'); ?></h6>
                                            <small class="text-muted">
                                                <?php if ($purchase['artist_name']): ?>
                                                    by <?php echo htmlspecialchars($purchase['artist_name']); ?> • 
                                                <?php endif; ?>
                                                <?php echo date('M j, Y', strtotime($purchase['created_at'])); ?>
                                            </small>
                                        </div>
                                        <div class="col-auto text-end">
                                            <div class="fw-bold text-success">KSh <?php echo number_format($purchase['amount'], 2); ?></div>
                                            <small class="text-muted">
                                                <?php if ($purchase['receipt_no']): ?>
                                                    Receipt: <?php echo $purchase['receipt_no']; ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-center mt-3">
                                <a href="customer_purchases.php" class="btn btn-outline-primary btn-sm">
                                    View All Purchases
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-shopping-bag fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Purchases Yet</h5>
                                <p class="text-muted">Start building your art collection today!</p>
                                <a href="art_gallery.php" class="btn btn-primary">
                                    <i class="fas fa-paint-brush me-2"></i>Browse Artworks
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-receipt me-2"></i>Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_payments)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Details</th>
                                            <th>Amount</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_payments as $payment): ?>
                                        <tr>
                                            <td>
                                                <span class="badge badge-payment bg-<?php 
                                                    echo $payment['payment_type'] == 'entrance_fee' ? 'warning' : 
                                                         ($payment['payment_type'] == 'art_purchase' ? 'primary' : 
                                                         ($payment['payment_type'] == 'event_booking' ? 'success' : 'secondary')); 
                                                ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $payment['payment_type'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php if ($payment['artwork_title']): ?>
                                                        <?php echo htmlspecialchars($payment['artwork_title']); ?>
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars($payment['description'] ?? 'Payment'); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td class="fw-bold text-success">KSh <?php echo number_format($payment['amount'], 2); ?></td>
                                            <td><small><?php echo date('M j', strtotime($payment['created_at'])); ?></small></td>
                                            <td>
                                                <span class="badge bg-success">Paid</span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-receipt fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No recent activity</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Invoices & Quick Actions -->
            <div class="col-md-4">
                <!-- Invoices -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-file-invoice me-2"></i>Recent Invoices</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($invoices)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($invoices as $invoice): ?>
                                <div class="list-group-item px-0 invoice-row">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo $invoice['invoice_number']; ?></strong><br>
                                            <small class="text-muted">Due: <?php echo date('M j, Y', strtotime($invoice['due_date'])); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold text-success">KSh <?php echo number_format($invoice['total_amount'], 2); ?></div>
                                            <span class="badge bg-<?php echo $invoice['status'] == 'paid' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($invoice['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="fas fa-eye me-1"></i>View
                                        </a>
                                        <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" 
                                           class="btn btn-sm btn-outline-secondary" target="_blank">
                                            <i class="fas fa-print me-1"></i>Print
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-center mt-3">
                                <a href="customer_invoices.php" class="btn btn-outline-info btn-sm">
                                    View All Invoices
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-file-invoice-dollar fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No invoices yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="art_gallery.php" class="btn btn-primary action-card">
                                <i class="fas fa-paint-brush me-2"></i> Browse Artworks
                            </a>
                            <!-- FIXED: Changed from entrance.php to customer_entrance.php -->
                            <a href="customer_entrance.php" class="btn btn-warning action-card">
                                <i class="fas fa-ticket-alt me-2"></i> Pay Entrance Fee
                            </a>
                            <a href="customer_purchases.php" class="btn btn-success action-card">
                                <i class="fas fa-history me-2"></i> View Purchase History
                            </a>
                            <a href="customer_invoices.php" class="btn btn-info action-card">
                                <i class="fas fa-file-invoice me-2"></i> My Invoices
                            </a>
                            <a href="events.php" class="btn btn-secondary action-card">
                                <i class="fas fa-calendar-alt me-2"></i> View Events
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
</body>
</html>