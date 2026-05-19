<?php
session_start();
require_once 'config.php';

// Use config.php functions for authentication
if (!isLoggedIn() || !isAttendant()) {
    header("Location: login.php");
    exit();
}

$today = date('Y-m-d');
$attendant_id = $_SESSION['user_id'];

try {
    
    $entrance_today_query = "SELECT 
        COUNT(*) as today_entrance,
        COALESCE(SUM(amount), 0) as entrance_amount
        FROM payments 
        WHERE DATE(payment_date) = ? 
        AND payment_type = 'entrance'
        AND status = 'completed'";
    
    $entrance_today_stmt = $db->prepare($entrance_today_query);
    $entrance_today_stmt->execute([$today]);
    $entrance_today = $entrance_today_stmt->fetch();

    // Get today's art sales
    $sales_today_query = "SELECT 
        COUNT(*) as today_sales,
        COALESCE(SUM(amount), 0) as sales_amount
        FROM payments 
        WHERE DATE(payment_date) = ? 
        AND payment_type IN ('sale', 'art_sale')
        AND status = 'completed'";
    
    $sales_today_stmt = $db->prepare($sales_today_query);
    $sales_today_stmt->execute([$today]);
    $sales_today = $sales_today_stmt->fetch();

    // Get today's total payments processed by this attendant
    $payments_today_query = "SELECT 
        COUNT(*) as payment_count,
        COALESCE(SUM(amount), 0) as payment_total
        FROM payments 
        WHERE DATE(created_at) = ? 
        AND user_id = ?
        AND status = 'completed'";
    
    $payments_today_stmt = $db->prepare($payments_today_query);
    $payments_today_stmt->execute([$today, $attendant_id]);
    $payments_today = $payments_today_stmt->fetch();

    // Get pending check-ins from payments table
    $pending_checkins_query = "SELECT 
        COUNT(*) as pending_count
        FROM payments 
        WHERE DATE(payment_date) = ?
        AND payment_type = 'entrance'
        AND status = 'completed'
        AND (checked_in IS NULL OR checked_in = 0)";
    
    $pending_checkins_stmt = $db->prepare($pending_checkins_query);
    $pending_checkins_stmt->execute([$today]);
    $pending_checkins = $pending_checkins_stmt->fetchColumn();

    // Get customers who paid entrance today
    $paid_customers_query = "SELECT 
        p.customer_name,
        p.customer_email,
        p.phone_number,
        p.amount,
        p.payment_method,
        p.receipt_number,
        DATE_FORMAT(p.payment_date, '%h:%i %p') as payment_time,
        CASE 
            WHEN p.checked_in = 1 THEN 'checked_in'
            ELSE 'pending'
        END as checkin_status
        FROM payments p
        WHERE DATE(payment_date) = ? 
        AND p.payment_type = 'entrance'
        AND p.status = 'completed'
        ORDER BY p.payment_date DESC
        LIMIT 10";
    
    $paid_customers_stmt = $db->prepare($paid_customers_query);
    $paid_customers_stmt->execute([$today]);
    $paid_customers = $paid_customers_stmt->fetchAll();

    // FIXED: Get upcoming events with REAL booking counts from payments
    $upcoming_events_query = "
        SELECT 
            e.*,
            COUNT(p.id) as total_bookings,
            COALESCE(SUM(p.amount), 0) as total_revenue
        FROM events e
        LEFT JOIN payments p ON e.id = p.event_id 
            AND p.payment_type = 'event_booking' 
            AND p.status = 'completed'
        WHERE e.status IN ('upcoming', 'ongoing')
        AND e.event_date >= CURDATE()
        GROUP BY e.id
        ORDER BY e.event_date ASC
        LIMIT 5
    ";
    
    $upcoming_events_stmt = $db->prepare($upcoming_events_query);
    $upcoming_events_stmt->execute();
    $upcoming_events = $upcoming_events_stmt->fetchAll();

    // If event_id doesn't exist in payments, use fallback query with description matching
    if (empty($upcoming_events)) {
        // Get basic event info first
        $basic_events_query = "SELECT e.* FROM events e
                              WHERE e.status IN ('upcoming', 'ongoing')
                              AND e.event_date >= CURDATE()
                              ORDER BY e.event_date ASC
                              LIMIT 5";
        $basic_events_stmt = $db->prepare($basic_events_query);
        $basic_events_stmt->execute();
        $upcoming_events = $basic_events_stmt->fetchAll();
        
        // For each event, try to get booking counts by matching title in description
        foreach ($upcoming_events as &$event) {
            $booking_query = "
                SELECT 
                    COUNT(*) as total_bookings,
                    COALESCE(SUM(amount), 0) as total_revenue
                FROM payments 
                WHERE payment_type = 'event_booking' 
                AND status = 'completed'
                AND description LIKE ?
            ";
            $booking_stmt = $db->prepare($booking_query);
            $search = '%' . $event['title'] . '%';
            $booking_stmt->execute([$search]);
            $booking_data = $booking_stmt->fetch();
            
            $event['total_bookings'] = $booking_data ? $booking_data['total_bookings'] : 0;
            $event['total_revenue'] = $booking_data ? $booking_data['total_revenue'] : 0;
        }
    }

    // FIXED: Get recent art sales - handle case where tables don't exist
    $recent_sales = [];
    try {
        $recent_sales_query = "SELECT 
            p.customer_name,
            p.amount,
            p.payment_method,
            p.receipt_number,
            DATE_FORMAT(p.payment_date, '%h:%i %p') as payment_time,
            p.description
            FROM payments p
            WHERE p.payment_type IN ('sale', 'art_sale')
            AND p.status = 'completed'
            ORDER BY p.payment_date DESC
            LIMIT 5";
        
        $recent_sales_stmt = $db->prepare($recent_sales_query);
        $recent_sales_stmt->execute();
        $recent_sales = $recent_sales_stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Recent sales query error: " . $e->getMessage());
        $recent_sales = [];
    }

    // FIXED: Get active artworks count - handle case where table doesn't exist
    $active_artworks_count = 0;
    try {
        $active_artworks_count = $db->query("SELECT COUNT(*) FROM artworks WHERE status = 'available' OR status IS NULL")->fetchColumn();
    } catch (Exception $e) {
        error_log("Artworks count error: " . $e->getMessage());
        $active_artworks_count = 0;
    }

    // Calculate totals
    $today_total_revenue = ($entrance_today['entrance_amount'] ?? 0) + ($sales_today['sales_amount'] ?? 0);
    $pending_checkins_count = 0;
    if (!empty($paid_customers)) {
        foreach ($paid_customers as $c) {
            if ($c['checkin_status'] == 'pending') {
                $pending_checkins_count++;
            }
        }
    }

} catch (PDOException $e) {
    error_log("Attendant dashboard error: " . $e->getMessage());
    $entrance_today = ['today_entrance' => 0, 'entrance_amount' => 0];
    $sales_today = ['today_sales' => 0, 'sales_amount' => 0];
    $payments_today = ['payment_count' => 0, 'payment_total' => 0];
    $paid_customers = [];
    $upcoming_events = [];
    $recent_sales = [];
    $pending_checkins = 0;
    $pending_checkins_count = 0;
    $active_artworks_count = 0;
    $today_total_revenue = 0;
}

// Handle check-in action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_in_email'])) {
    $email = $_POST['check_in_email'];
    
    try {
        // Check if payment exists and not checked in
        $check_query = "SELECT p.* FROM payments p 
                        WHERE p.customer_email = ? 
                        AND DATE(p.payment_date) = ? 
                        AND p.payment_type = 'entrance'
                        AND p.status = 'completed'";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$email, $today]);
        $payment = $check_stmt->fetch();
        
        if ($payment) {
            // Check if already checked in
            if ($payment['checked_in'] == 1) {
                $_SESSION['error'] = "Customer already checked in today.";
            } else {
                // Update payment record to mark as checked in
                $update_query = "UPDATE payments 
                                SET check_in_time = NOW(), 
                                    checked_in = 1 
                                WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([$payment['id']]);
                
                $_SESSION['success'] = "Customer checked in successfully!";
            }
        } else {
            $_SESSION['error'] = "No entrance payment found for this customer today.";
        }
        
        header("Location: attendant_dashboard.php");
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
        header("Location: attendant_dashboard.php");
        exit();
    }
}

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery Operations - Ardhi Gallery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
        }
        
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #452c50, #5e3451);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
            height: 100%;
            border-left: 4px solid var(--primary);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-card.entrance { border-left-color: #ff6b35; }
        .stat-card.sales { border-left-color: #28a745; }
        .stat-card.pending { border-left-color: #ffc107; }
        .stat-card.payments { border-left-color: #9b59b6; }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .action-card {
            background: white;
            border-radius: 10px;
            padding: 25px 15px;
            text-align: center;
            transition: all 0.3s;
            border: 2px solid #e9ecef;
            cursor: pointer;
            text-decoration: none;
            color: #333;
            display: block;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .action-card i {
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: block;
        }
        
        .action-card.entrance i { color: #ff6b35; }
        .action-card.checkin i { color: #28a745; }
        .action-card.sales i { color: #3498db; }
        .action-card.art i { color: #9b59b6; }
        .action-card.events i { color: #f39c12; }
        .action-card.reports i { color: #1abc9c; }
        .action-card.logout i { color: #dc3545; }
        
        .entrance-feature {
            background: linear-gradient(135deg, #ff6b35, #f7931e);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(255,107,53,0.3);
        }
        
        .customer-row {
            border-left: 4px solid #28a745;
            margin-bottom: 10px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .customer-row:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .customer-row.checked-in {
            border-left-color: #17a2b8;
            opacity: 0.8;
        }
        
        .method-badge {
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.7rem;
        }
        .method-cash { background: #28a745; color: white; }
        .method-mpesa { background: #17a2b8; color: white; }
        .method-card { background: #6f42c1; color: white; }
        
        .section-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .section-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-weight: 600;
        }
        
        .section-body {
            padding: 20px;
        }
        
        .btn-view-all {
            background: #e9ecef;
            color: #495057;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 0.8rem;
            text-decoration: none;
        }
        .btn-view-all:hover {
            background: #dee2e6;
            color: #212529;
        }

        /* Logout button styling */
        .logout-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 600;
            box-shadow: 0 5px 15px rgba(220,53,69,0.3);
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .logout-btn:hover {
            background: #c82333;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(220,53,69,0.4);
            color: white;
        }
        
        .booking-badge {
            background: #667eea;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <!-- Floating Logout Button -->
    <div class="logout-container">
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt me-2"></i>LOGOUT
        </a>
    </div>

    <div class="container-fluid py-4">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="fw-bold mb-2"><i class="fas fa-user-shield me-2"></i>ATTENDANT DASHBOARD</h1>
                    <p class="mb-0 opacity-75">Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="bg-dark bg-opacity-25 p-3 rounded">
                        <div class="h4 mb-1"><?php echo date('h:i A'); ?></div>
                        <small><?php echo date('l, F j, Y'); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
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

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-number">KSh <?php echo number_format($today_total_revenue, 0); ?></div>
                    <div class="stat-label">Today's Total Revenue</div>
                    <small class="text-success"><?php echo ($entrance_today['today_entrance'] ?? 0) + ($sales_today['today_sales'] ?? 0); ?> transactions</small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card entrance">
                    <div class="stat-number"><?php echo $entrance_today['today_entrance'] ?? 0; ?></div>
                    <div class="stat-label">Entrance Tickets</div>
                    <small class="text-warning">KSh <?php echo number_format($entrance_today['entrance_amount'] ?? 0, 0); ?></small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card sales">
                    <div class="stat-number"><?php echo $sales_today['today_sales'] ?? 0; ?></div>
                    <div class="stat-label">Art Sales</div>
                    <small class="text-success">KSh <?php echo number_format($sales_today['sales_amount'] ?? 0, 0); ?></small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card payments">
                    <div class="stat-number"><?php echo $payments_today['payment_count'] ?? 0; ?></div>
                    <div class="stat-label">My Payments</div>
                    <small class="text-primary">KSh <?php echo number_format($payments_today['payment_total'] ?? 0, 0); ?></small>
                </div>
            </div>
        </div>

        <!-- MAIN ENTRANCE FEATURE - SINGLE BIG BUTTON -->
        <div class="entrance-feature">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="fw-bold"><i class="fas fa-ticket-alt me-2"></i>ENTRANCE MANAGEMENT</h2>
                    <p class="lead mb-0">Process entrance payments and check in customers</p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="sell_entrance.php" class="btn btn-light btn-lg px-5 py-3 fw-bold">
                        <i class="fas fa-plus-circle me-2"></i>SELL TICKETS
                    </a>
                </div>
            </div>
        </div>

        <!-- Quick Actions Grid - ALL OTHER FUNCTIONS -->
        <h4 class="mb-3"><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</h4>
        <div class="action-grid">
            <a href="sell_entrance.php" class="action-card entrance">
                <i class="fas fa-ticket-alt"></i>
                <strong>Sell Tickets</strong>
                <small class="text-muted d-block">Process entrance</small>
            </a>
            <a href="attendant_checkin.php" class="action-card checkin">
                <i class="fas fa-qrcode"></i>
                <strong>Check In</strong>
                <small class="text-muted d-block">Verify tickets</small>
            </a>
            <a href="attendant_art_sales.php" class="action-card sales">
                <i class="fas fa-shopping-cart"></i>
                <strong>Art Sales</strong>
                <small class="text-muted d-block">View sales</small>
            </a>
            <a href="attendant_view_art.php" class="action-card art">
                <i class="fas fa-palette"></i>
                <strong>View Art</strong>
                <small class="text-muted d-block">Browse gallery</small>
            </a>
            <a href="attendant_events.php" class="action-card events">
                <i class="fas fa-calendar-alt"></i>
                <strong>Events</strong>
                <small class="text-muted d-block">View events</small>
            </a>
            <a href="attendant_event_bookings.php" class="action-card events">
                <i class="fas fa-ticket-alt"></i>
                <strong>Bookings</strong>
                <small class="text-muted d-block">Event bookings</small>
            </a>
            <a href="attendant_payments.php" class="action-card payments">
                <i class="fas fa-credit-card"></i>
                <strong>My Payments</strong>
                <small class="text-muted d-block">Payment history</small>
            </a>
            <a href="reconciliation.php" class="action-card reports">
                <i class="fas fa-balance-scale"></i>
                <strong>Reconcile</strong>
                <small class="text-muted d-block">Daily cash</small>
            </a>
            <a href="attendant_reports.php" class="action-card reports">
                <i class="fas fa-chart-bar"></i>
                <strong>Reports</strong>
                <small class="text-muted d-block">View reports</small>
            </a>
            <!-- LOGOUT BUTTON IN GRID -->
            <a href="logout.php" class="action-card logout">
                <i class="fas fa-sign-out-alt"></i>
                <strong>Logout</strong>
                <small class="text-muted d-block">End session</small>
            </a>
        </div>

        <div class="row">
            <!-- Left Column: Entrance Customers -->
            <div class="col-lg-6 mb-4">
                <div class="section-card">
                    <div class="section-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Today's Entrance Payments</h5>
                        <span class="badge bg-light text-dark"><?php echo count($paid_customers); ?> customers</span>
                    </div>
                    <div class="section-body">
                        <!-- Quick Check-in Form -->
                        <div class="bg-light p-3 rounded mb-3">
                            <form method="POST" class="row g-2">
                                <div class="col-md-8">
                                    <input type="email" class="form-control" name="check_in_email" 
                                           placeholder="Enter customer email to check in" required>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-check-circle me-1"></i>Check In
                                    </button>
                                </div>
                            </form>
                        </div>

                        <?php if (empty($paid_customers)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-ticket-alt fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No entrance payments today</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Customer</th>
                                            <th>Amount</th>
                                            <th>Payment</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($paid_customers as $customer): ?>
                                        <tr class="<?php echo $customer['checkin_status'] == 'checked_in' ? 'table-success' : ''; ?>">
                                            <td><?php echo $customer['payment_time']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($customer['customer_name'] ?? 'Guest'); ?></strong>
                                                <br><small><?php echo htmlspecialchars($customer['customer_email']); ?></small>
                                            </td>
                                            <td class="text-success fw-bold">KSh <?php echo number_format($customer['amount'], 0); ?></td>
                                            <td>
                                                <span class="method-badge method-<?php echo $customer['payment_method']; ?>">
                                                    <?php echo strtoupper($customer['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($customer['checkin_status'] == 'checked_in'): ?>
                                                    <span class="badge bg-success">Checked In</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($customer['checkin_status'] == 'pending'): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="check_in_email" 
                                                               value="<?php echo htmlspecialchars($customer['customer_email']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-success">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-end mt-2">
                                <a href="attendant_payments.php" class="btn-view-all">
                                    View All <i class="fas fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Recent Art Sales & Events -->
            <div class="col-lg-6 mb-4">
                <!-- Recent Art Sales -->
                <div class="section-card mb-4">
                    <div class="section-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-shopping-cart me-2"></i>Recent Art Sales</h5>
                        <a href="attendant_art_sales.php" class="btn-view-all">View All</a>
                    </div>
                    <div class="section-body">
                        <?php if (empty($recent_sales)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-palette fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No art sales yet</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($recent_sales as $sale): ?>
                                <div class="list-group-item border-0 mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($sale['customer_name'] ?? 'Guest'); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo $sale['payment_time'] ?? ''; ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="text-success fw-bold">KSh <?php echo number_format($sale['amount'] ?? 0, 0); ?></span>
                                            <br>
                                            <span class="method-badge method-<?php echo $sale['payment_method'] ?? 'cash'; ?>">
                                                <?php echo strtoupper($sale['payment_method'] ?? 'CASH'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Events with Bookings -->
                <div class="section-card">
                    <div class="section-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Upcoming Events</h5>
                        <a href="attendant_events.php" class="btn-view-all">View All</a>
                    </div>
                    <div class="section-body">
                        <?php if (empty($upcoming_events)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No upcoming events</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $event): 
                                $bookings = isset($event['total_bookings']) ? (int)$event['total_bookings'] : 0;
                                $capacity = isset($event['capacity']) ? (int)$event['capacity'] : 0;
                                $fill_percentage = $capacity > 0 ? min(round(($bookings / $capacity) * 100, 1), 100) : 0;
                            ?>
                            <div class="border-bottom pb-3 mb-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?php echo htmlspecialchars($event['title'] ?? 'Untitled'); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar-alt me-1"></i><?php echo isset($event['event_date']) ? date('M d, Y', strtotime($event['event_date'])) : 'TBD'; ?>
                                            <br>
                                            <i class="fas fa-clock me-1"></i><?php echo isset($event['event_time']) ? date('g:i A', strtotime($event['event_time'])) : 'TBD'; ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="booking-badge">
                                            <i class="fas fa-ticket-alt me-1"></i><?php echo $bookings; ?> bookings
                                        </span>
                                        <?php if (($event['total_revenue'] ?? 0) > 0): ?>
                                            <br>
                                            <small class="text-success">KSh <?php echo number_format($event['total_revenue'], 0); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($bookings > 0): ?>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div class="progress-bar bg-primary" style="width: <?php echo $fill_percentage; ?>%"></div>
                                </div>
                                <small class="text-muted"><?php echo $bookings; ?>/<?php echo $capacity; ?> seats booked</small>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats Row -->
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="section-card">
                    <div class="section-header">
                        <h5 class="mb-0"><i class="fas fa-palette me-2"></i>Available Artworks</h5>
                    </div>
                    <div class="section-body text-center">
                        <h2 class="display-4"><?php echo $active_artworks_count; ?></h2>
                        <p class="text-muted">Artworks currently available</p>
                        <a href="attendant_view_art.php" class="btn btn-outline-primary">
                            <i class="fas fa-eye me-2"></i>Browse Gallery
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-4">
                <div class="section-card">
                    <div class="section-header">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Pending Check-ins</h5>
                    </div>
                    <div class="section-body text-center">
                        <h2 class="display-4"><?php echo $pending_checkins_count; ?></h2>
                        <p class="text-muted">Customers waiting to check in</p>
                        <a href="attendant_checkin.php" class="btn btn-outline-warning">
                            <i class="fas fa-qrcode me-2"></i>Process Check-ins
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-4">
                <div class="section-card">
                    <div class="section-header">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Today's Summary</h5>
                    </div>
                    <div class="section-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Entrance Tickets:</span>
                            <span class="fw-bold"><?php echo $entrance_today['today_entrance'] ?? 0; ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Art Sales:</span>
                            <span class="fw-bold"><?php echo $sales_today['today_sales'] ?? 0; ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Checked In:</span>
                            <span class="fw-bold text-success">
                                <?php echo ($entrance_today['today_entrance'] ?? 0) - $pending_checkins_count; ?>
                            </span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold">Total Revenue:</span>
                            <span class="fw-bold text-success">KSh <?php echo number_format($today_total_revenue, 0); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 2 minutes for real-time updates
        setInterval(function() {
            location.reload();
        }, 120000);
    </script>
</body>
</html>