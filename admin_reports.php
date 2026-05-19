<?php
session_start();
require_once 'config.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header('Location: login.php');
    exit();
}

// Set default date range (current month)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'sales';
$today = date('Y-m-d');

// ===========================================
// TODAY'S QUICK STATS
// ===========================================
$today_stats = [
    'entrance_tickets' => 0,
    'entrance_revenue' => 0,
    'event_bookings' => 0,
    'event_revenue' => 0,
    'art_sales' => 0,
    'art_revenue' => 0
];

try {
    // Today's entrance sales
    $today_entrance = $db->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as revenue
        FROM payments 
        WHERE DATE(payment_date) = ? 
        AND payment_type = 'entrance' 
        AND status = 'completed'
    ");
    $today_entrance->execute([$today]);
    $entrance = $today_entrance->fetch();
    $today_stats['entrance_tickets'] = $entrance['count'];
    $today_stats['entrance_revenue'] = $entrance['revenue'];
    
    // Today's event bookings
    $today_events = $db->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as revenue
        FROM payments 
        WHERE DATE(payment_date) = ? 
        AND payment_type = 'event_booking' 
        AND status = 'completed'
    ");
    $today_events->execute([$today]);
    $events = $today_events->fetch();
    $today_stats['event_bookings'] = $events['count'];
    $today_stats['event_revenue'] = $events['revenue'];
    
    // Today's art sales
    $today_art = $db->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as revenue
        FROM payments 
        WHERE DATE(payment_date) = ? 
        AND payment_type IN ('sale', 'art_sale', 'art_purchase') 
        AND status = 'completed'
    ");
    $today_art->execute([$today]);
    $art = $today_art->fetch();
    $today_stats['art_sales'] = $art['count'];
    $today_stats['art_revenue'] = $art['revenue'];
    
} catch (PDOException $e) {
    error_log("Today stats error: " . $e->getMessage());
}

// ===========================================
// SALES REPORT (from commissions table)
// ===========================================
$sales_data = [];
$sales_summary = [
    'total_sales' => 0,
    'total_commission' => 0,
    'total_artist_payout' => 0,
    'total_transactions' => 0
];

try {
    $sales_query = "
        SELECT 
            c.id as commission_id,
            c.sale_date,
            c.sale_price,
            c.commission_rate,
            c.commission_amount,
            c.artist_payout,
            c.payment_method,
            c.invoice_number,
            c.buyer_name,
            a.title as artwork_title,
            CONCAT(ar.first_name, ' ', ar.last_name) as artist_name
        FROM commissions c
        LEFT JOIN artworks a ON c.artwork_id = a.id
        LEFT JOIN artists ar ON c.artist_id = ar.id
        WHERE DATE(c.sale_date) BETWEEN ? AND ?
        ORDER BY c.sale_date DESC
    ";
    
    $sales_stmt = $db->prepare($sales_query);
    $sales_stmt->execute([$start_date, $end_date]);
    $sales_data = $sales_stmt->fetchAll();
    
    // Calculate summaries
    foreach ($sales_data as $sale) {
        $sales_summary['total_sales'] += $sale['sale_price'];
        $sales_summary['total_commission'] += $sale['commission_amount'];
        $sales_summary['total_artist_payout'] += $sale['artist_payout'];
        $sales_summary['total_transactions']++;
    }
    
} catch (PDOException $e) {
    error_log("Sales report error: " . $e->getMessage());
    $sales_data = [];
}

// ===========================================
// EVENTS REPORT (from payments table)
// ===========================================
$events_data = [];
$events_summary = [
    'total_events' => 0,
    'total_bookings' => 0,
    'total_revenue' => 0,
    'checked_in' => 0,
    'upcoming' => 0,
    'completed' => 0,
    'today_bookings' => 0,
    'today_revenue' => 0
];

try {
    // Get all events
    $events_query = "
        SELECT 
            e.id,
            e.title,
            e.event_date,
            e.event_time,
            e.venue,
            e.ticket_price,
            e.capacity,
            e.status
        FROM events e
        ORDER BY e.event_date DESC
    ";
    
    $events_stmt = $db->query($events_query);
    $events = $events_stmt->fetchAll();
    
    // For each event, get bookings from payments
    foreach ($events as $event) {
        // Get total bookings for this event (all time)
        $total_bookings_query = "
            SELECT 
                COUNT(*) as booking_count,
                COALESCE(SUM(amount), 0) as revenue,
                SUM(CASE WHEN checked_in = 1 THEN 1 ELSE 0 END) as checked_in_count
            FROM payments 
            WHERE payment_type = 'event_booking' 
            AND status = 'completed'
            AND description LIKE ?
        ";
        
        // Get today's bookings for this event
        $today_bookings_query = "
            SELECT 
                COUNT(*) as booking_count,
                COALESCE(SUM(amount), 0) as revenue
            FROM payments 
            WHERE payment_type = 'event_booking' 
            AND status = 'completed'
            AND DATE(payment_date) = ?
            AND description LIKE ?
        ";
        
        $search_term = '%' . $event['title'] . '%';
        
        // Get total bookings
        $total_stmt = $db->prepare($total_bookings_query);
        $total_stmt->execute([$search_term]);
        $total = $total_stmt->fetch();
        
        // Get today's bookings
        $today_stmt = $db->prepare($today_bookings_query);
        $today_stmt->execute([$today, $search_term]);
        $today_bookings = $today_stmt->fetch();
        
        $events_data[] = [
            'id' => $event['id'],
            'title' => $event['title'],
            'event_date' => $event['event_date'],
            'event_time' => $event['event_time'],
            'venue' => $event['venue'],
            'ticket_price' => $event['ticket_price'],
            'capacity' => $event['capacity'],
            'status' => $event['status'],
            'total_bookings' => $total['booking_count'],
            'total_revenue' => $total['revenue'],
            'checked_in' => $total['checked_in_count'],
            'today_bookings' => $today_bookings['booking_count'],
            'today_revenue' => $today_bookings['revenue']
        ];
        
        // Update summaries
        $events_summary['total_events']++;
        $events_summary['total_bookings'] += $total['booking_count'];
        $events_summary['total_revenue'] += $total['revenue'];
        $events_summary['checked_in'] += $total['checked_in_count'];
        $events_summary['today_bookings'] += $today_bookings['booking_count'];
        $events_summary['today_revenue'] += $today_bookings['revenue'];
        
        if (strtotime($event['event_date']) > time()) {
            $events_summary['upcoming']++;
        } else {
            $events_summary['completed']++;
        }
    }
    
} catch (PDOException $e) {
    error_log("Events report error: " . $e->getMessage());
    $events_data = [];
}

// ===========================================
// ENTRANCE PAYMENTS REPORT
// ===========================================
$entrance_data = [];
$entrance_summary = [
    'total_payments' => 0,
    'total_revenue' => 0,
    'cash' => 0,
    'mpesa' => 0,
    'card' => 0,
    'avg_ticket' => 0,
    'unique_visitors' => 0,
    'today_payments' => 0,
    'today_revenue' => 0
];

try {
    $entrance_query = "
        SELECT 
            DATE(payment_date) as payment_day,
            COUNT(*) as daily_count,
            SUM(amount) as daily_amount,
            SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash_amount,
            SUM(CASE WHEN payment_method = 'mpesa' THEN amount ELSE 0 END) as mpesa_amount,
            SUM(CASE WHEN payment_method = 'card' THEN amount ELSE 0 END) as card_amount,
            COUNT(DISTINCT customer_email) as unique_customers
        FROM payments 
        WHERE payment_type = 'entrance'
        AND status = 'completed'
        AND DATE(payment_date) BETWEEN ? AND ?
        GROUP BY DATE(payment_date)
        ORDER BY payment_day DESC
    ";
    
    $entrance_stmt = $db->prepare($entrance_query);
    $entrance_stmt->execute([$start_date, $end_date]);
    $entrance_data = $entrance_stmt->fetchAll();
    
    // Calculate summaries
    $total_amount = 0;
    $total_count = 0;
    
    foreach ($entrance_data as $day) {
        $entrance_summary['total_payments'] += $day['daily_count'];
        $entrance_summary['total_revenue'] += $day['daily_amount'];
        $entrance_summary['cash'] += $day['cash_amount'];
        $entrance_summary['mpesa'] += $day['mpesa_amount'];
        $entrance_summary['card'] += $day['card_amount'];
        
        $total_amount += $day['daily_amount'];
        $total_count += $day['daily_count'];
        
        // Check if this is today
        if ($day['payment_day'] == $today) {
            $entrance_summary['today_payments'] = $day['daily_count'];
            $entrance_summary['today_revenue'] = $day['daily_amount'];
        }
    }
    
    // Get unique visitors count
    $visitors_stmt = $db->prepare("
        SELECT COUNT(DISTINCT customer_email) as unique_count
        FROM payments 
        WHERE payment_type = 'entrance'
        AND status = 'completed'
        AND DATE(payment_date) BETWEEN ? AND ?
    ");
    $visitors_stmt->execute([$start_date, $end_date]);
    $entrance_summary['unique_visitors'] = $visitors_stmt->fetchColumn();
    
    $entrance_summary['avg_ticket'] = $total_count > 0 ? $total_amount / $total_count : 0;
    
} catch (PDOException $e) {
    error_log("Entrance report error: " . $e->getMessage());
    $entrance_data = [];
}

// ===========================================
// ARTWORK SUBMISSIONS REPORT
// ===========================================
$submissions_data = [];
$submissions_summary = [
    'total_submissions' => 0,
    'approved' => 0,
    'rejected' => 0,
    'pending' => 0,
    'today_submissions' => 0
];

try {
    $submissions_query = "
        SELECT 
            a.id as artwork_id,
            a.title,
            a.created_at as submitted_at,
            a.image_approved,
            a.status,
            a.rejection_reason,
            a.admin_notes,
            CONCAT(ar.first_name, ' ', ar.last_name) as artist_name,
            ar.email as artist_email,
            ar.phone as artist_phone
        FROM artworks a
        JOIN artists ar ON a.artist_id = ar.id
        WHERE (a.created_by_artist = 1 OR a.submitted_by_artist = 1)
        AND DATE(a.created_at) BETWEEN ? AND ?
        ORDER BY a.created_at DESC
    ";
    
    $submissions_stmt = $db->prepare($submissions_query);
    $submissions_stmt->execute([$start_date, $end_date]);
    $submissions_data = $submissions_stmt->fetchAll();
    
    // Calculate summaries
    foreach ($submissions_data as $sub) {
        $submissions_summary['total_submissions']++;
        if (date('Y-m-d', strtotime($sub['submitted_at'])) == $today) {
            $submissions_summary['today_submissions']++;
        }
        if ($sub['image_approved'] == 1) {
            $submissions_summary['approved']++;
        } elseif ($sub['status'] == 'rejected') {
            $submissions_summary['rejected']++;
        } else {
            $submissions_summary['pending']++;
        }
    }
    
} catch (PDOException $e) {
    error_log("Submissions report error: " . $e->getMessage());
    $submissions_data = [];
}

// ===========================================
// ARTISTS REPORT - FIXED to show ALL artists with artwork counts
// ===========================================
$artists_data = [];
$artists_summary = [
    'total_artists' => 0,
    'active_agreements' => 0,
    'with_artworks' => 0,
    'total_artworks' => 0,
    'total_sold' => 0,
    'new_this_period' => 0,
    'today_artists' => 0
];

try {
    // Get ALL artists with their artwork statistics - NOT filtered by date
    $artists_query = "
        SELECT 
            a.id,
            a.first_name,
            a.last_name,
            a.email,
            a.phone,
            a.created_at,
            COUNT(DISTINCT aw.id) as artwork_count,
            COUNT(DISTINCT ca.id) as agreement_count,
            SUM(CASE WHEN aw.status = 'sold' THEN 1 ELSE 0 END) as sold_count,
            SUM(CASE WHEN aw.status = 'available' OR aw.status IS NULL THEN 1 ELSE 0 END) as available_count
        FROM artists a
        LEFT JOIN artworks aw ON a.id = aw.artist_id
        LEFT JOIN consignment_agreements ca ON a.id = ca.artist_id AND ca.status = 'active'
        GROUP BY a.id
        ORDER BY a.created_at DESC
    ";
    
    $artists_stmt = $db->query($artists_query);
    $artists_data = $artists_stmt->fetchAll();
    
    // Calculate summaries for ALL artists
    $artists_summary['total_artists'] = count($artists_data);
    
    foreach ($artists_data as $artist) {
        $artists_summary['total_artworks'] += $artist['artwork_count'];
        $artists_summary['total_sold'] += $artist['sold_count'];
        
        if ($artist['artwork_count'] > 0) {
            $artists_summary['with_artworks']++;
        }
        
        // Count new artists in the selected period
        if (date('Y-m-d', strtotime($artist['created_at'])) >= $start_date && 
            date('Y-m-d', strtotime($artist['created_at'])) <= $end_date) {
            $artists_summary['new_this_period']++;
        }
        
        // Count artists registered today
        if (date('Y-m-d', strtotime($artist['created_at'])) == $today) {
            $artists_summary['today_artists']++;
        }
    }
    
    // Get active agreements count (all time)
    $agreements_stmt = $db->query("
        SELECT COUNT(*) as count FROM consignment_agreements 
        WHERE status = 'active'
    ");
    $artists_summary['active_agreements'] = $agreements_stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Artists report error: " . $e->getMessage());
    $artists_data = [];
}

// ===========================================
// SUMMARY TOTALS FOR ALL REPORTS
// ===========================================
$grand_total = [
    'sales_revenue' => $sales_summary['total_sales'],
    'event_revenue' => $events_summary['total_revenue'],
    'entrance_revenue' => $entrance_summary['total_revenue'],
    'total_revenue' => $sales_summary['total_sales'] + $events_summary['total_revenue'] + $entrance_summary['total_revenue'],
    'today_revenue' => $today_stats['entrance_revenue'] + $today_stats['event_revenue'] + $today_stats['art_revenue']
];

// Rejection reasons for display
$rejection_reasons_display = [
    'image_quality' => 'Image Quality Issues',
    'image_format' => 'Wrong Image Format',
    'image_count' => 'Insufficient Images',
    'missing_info' => 'Missing Artwork Information',
    'copyright' => 'Copyright Concerns',
    'gallery_fit' => 'Does Not Match Gallery Theme',
    'pricing' => 'Pricing Issues',
    'inappropriate' => 'Inappropriate Content',
    'duplicate' => 'Duplicate Submission',
    'other' => 'Other Reason'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Reports - Ardhi Gallery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 0;
            margin-bottom: 30px;
            border-radius: 0 0 30px 30px;
        }
        
        .today-stats {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(40,167,69,0.3);
        }
        
        .report-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .report-header {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            padding: 15px 20px;
            border-bottom: 2px solid #667eea;
            font-weight: 600;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(102,126,234,0.3);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            height: 100%;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #28a745;
            line-height: 1.2;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
            padding: 12px 20px;
            border: none;
            border-bottom: 3px solid transparent;
        }
        
        .nav-tabs .nav-link:hover {
            border-color: transparent;
            color: #667eea;
        }
        
        .nav-tabs .nav-link.active {
            color: #667eea;
            border-bottom: 3px solid #667eea;
            background: transparent;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .badge-pending { background: #fff3cd; color: #856404; }
        
        .export-btn {
            border-radius: 50px;
            padding: 10px 25px;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }
        
        .method-breakdown {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .today-badge {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .artist-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 10px;
            text-align: center;
            flex: 1;
            min-width: 120px;
        }
        
        .stat-item .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
        }
        
        .stat-item .label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
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
                        <i class="fas fa-chart-pie me-3"></i>Admin Reports
                    </h1>
                    <p class="lead mb-0">Comprehensive gallery performance and analytics</p>
                </div>
                <div>
                    <button class="btn btn-light export-btn me-2" onclick="exportAllReports()">
                        <i class="fas fa-download me-2"></i>Export
                    </button>
                    <button class="btn btn-outline-light export-btn" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container-fluid px-4 mb-5">
        <!-- Today's Stats -->
        <div class="today-stats">
            <h5 class="mb-3"><i class="fas fa-sun me-2"></i>Today's Activity (<?php echo date('F j, Y'); ?>)</h5>
            <div class="row">
                <div class="col-md-2 col-6 mb-2">
                    <div class="text-center">
                        <small>Entrance</small>
                        <h4 class="mb-0"><?php echo $today_stats['entrance_tickets']; ?></h4>
                        <small>KSh <?php echo number_format($today_stats['entrance_revenue'], 0); ?></small>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="text-center">
                        <small>Events</small>
                        <h4 class="mb-0"><?php echo $events_summary['today_bookings']; ?></h4>
                        <small>KSh <?php echo number_format($events_summary['today_revenue'], 0); ?></small>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="text-center">
                        <small>Art Sales</small>
                        <h4 class="mb-0"><?php echo $today_stats['art_sales']; ?></h4>
                        <small>KSh <?php echo number_format($today_stats['art_revenue'], 0); ?></small>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="text-center">
                        <small>Submissions</small>
                        <h4 class="mb-0"><?php echo $submissions_summary['today_submissions']; ?></h4>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="text-center">
                        <small>New Artists</small>
                        <h4 class="mb-0"><?php echo $artists_summary['today_artists']; ?></h4>
                    </div>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <div class="text-center">
                        <small>Total Revenue</small>
                        <h4 class="mb-0">KSh <?php echo number_format($grand_total['today_revenue'], 0); ?></h4>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Date Filter -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Start Date</label>
                    <input type="date" name="start_date" class="form-control" 
                           value="<?php echo $start_date; ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">End Date</label>
                    <input type="date" name="end_date" class="form-control" 
                           value="<?php echo $end_date; ?>" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100 py-2">
                        <i class="fas fa-filter me-2"></i>Apply Filter
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Grand Total Revenue Card -->
        <div class="summary-card">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <h5 class="mb-2">Total Revenue</h5>
                    <h1 class="display-3 mb-0">KSh <?php echo number_format($grand_total['total_revenue'], 0); ?></h1>
                    <p class="mb-0 opacity-75"><?php echo date('M d, Y', strtotime($start_date)); ?> - <?php echo date('M d, Y', strtotime($end_date)); ?></p>
                </div>
                <div class="col-md-8">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <div class="bg-white bg-opacity-25 p-3 rounded">
                                <h6>Art Sales</h6>
                                <h3>KSh <?php echo number_format($grand_total['sales_revenue'], 0); ?></h3>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="bg-white bg-opacity-25 p-3 rounded">
                                <h6>Event Bookings</h6>
                                <h3>KSh <?php echo number_format($grand_total['event_revenue'], 0); ?></h3>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="bg-white bg-opacity-25 p-3 rounded">
                                <h6>Entrance Fees</h6>
                                <h3>KSh <?php echo number_format($grand_total['entrance_revenue'], 0); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Report Tabs -->
        <ul class="nav nav-tabs mb-4" id="reportTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $report_type == 'sales' ? 'active' : ''; ?>" 
                        id="sales-tab" data-bs-toggle="tab" data-bs-target="#sales" type="button" role="tab">
                    <i class="fas fa-shopping-cart me-2"></i>Art Sales
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab">
                    <i class="fas fa-calendar-alt me-2"></i>Events
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="entrance-tab" data-bs-toggle="tab" data-bs-target="#entrance" type="button" role="tab">
                    <i class="fas fa-ticket-alt me-2"></i>Entrance
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="submissions-tab" data-bs-toggle="tab" data-bs-target="#submissions" type="button" role="tab">
                    <i class="fas fa-cloud-upload-alt me-2"></i>Submissions
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="artists-tab" data-bs-toggle="tab" data-bs-target="#artists" type="button" role="tab">
                    <i class="fas fa-users me-2"></i>Artists
                </button>
            </li>
        </ul>
        
        <!-- Tab Content -->
        <div class="tab-content" id="reportTabsContent">
            
            <!-- 1. SALES REPORT TAB -->
            <div class="tab-pane fade <?php echo $report_type == 'sales' ? 'show active' : ''; ?>" id="sales" role="tabpanel">
                <!-- Sales Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number">KSh <?php echo number_format($sales_summary['total_sales'], 0); ?></div>
                            <div class="stat-label">Total Sales</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number">KSh <?php echo number_format($sales_summary['total_commission'], 0); ?></div>
                            <div class="stat-label">Gallery Commission</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number">KSh <?php echo number_format($sales_summary['total_artist_payout'], 0); ?></div>
                            <div class="stat-label">Artist Payout</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $sales_summary['total_transactions']; ?></div>
                            <div class="stat-label">Transactions</div>
                        </div>
                    </div>
                </div>
                
                <!-- Sales Details Table -->
                <div class="card report-card">
                    <div class="report-header">
                        <i class="fas fa-list me-2"></i>Art Sales Details
                    </div>
                    <div class="card-body">
                        <?php if (!empty($sales_data)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Artwork</th>
                                            <th>Artist</th>
                                            <th>Buyer</th>
                                            <th class="text-end">Price</th>
                                            <th class="text-end">Commission</th>
                                            <th class="text-end">Artist Payout</th>
                                            <th>Payment</th>
                                            <th>Invoice</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sales_data as $sale): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($sale['artwork_title'] ?? 'Unknown'); ?></td>
                                            <td><?php echo htmlspecialchars($sale['artist_name'] ?? 'Unknown'); ?></td>
                                            <td><?php echo htmlspecialchars($sale['buyer_name'] ?? 'Anonymous'); ?></td>
                                            <td class="text-end text-success fw-bold">KSh <?php echo number_format($sale['sale_price'], 0); ?></td>
                                            <td class="text-end text-primary">KSh <?php echo number_format($sale['commission_amount'], 0); ?></td>
                                            <td class="text-end text-info">KSh <?php echo number_format($sale['artist_payout'], 0); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo ucfirst($sale['payment_method']); ?></span></td>
                                            <td><small><?php echo $sale['invoice_number']; ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-shopping-cart"></i>
                                <h5>No Sales Data</h5>
                                <p class="text-muted">No art sales recorded for this period</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- 2. EVENTS REPORT TAB -->
            <div class="tab-pane fade" id="events" role="tabpanel">
                <!-- Events Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $events_summary['total_events']; ?></div>
                            <div class="stat-label">Total Events</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $events_summary['total_bookings']; ?></div>
                            <div class="stat-label">Total Bookings</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $events_summary['today_bookings']; ?></div>
                            <div class="stat-label">Today's Bookings</div>
                            <small class="text-success">KSh <?php echo number_format($events_summary['today_revenue'], 0); ?></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number">KSh <?php echo number_format($events_summary['total_revenue'], 0); ?></div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>
                
                <!-- Events Details Table -->
                <div class="card report-card">
                    <div class="report-header">
                        <i class="fas fa-calendar-alt me-2"></i>Event Bookings & Payments
                    </div>
                    <div class="card-body">
                        <?php if (!empty($events_data)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Event</th>
                                            <th>Date</th>
                                            <th>Venue</th>
                                            <th class="text-end">Total Bookings</th>
                                            <th class="text-end">Today's Bookings</th>
                                            <th class="text-end">Checked In</th>
                                            <th class="text-end">Revenue</th>
                                            <th>Fill Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($events_data as $event): 
                                            $fill_rate = $event['capacity'] > 0 ? round(($event['total_bookings'] / $event['capacity']) * 100, 1) : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                                                <br><small class="text-muted"><?php echo ucfirst($event['status']); ?></small>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($event['event_date'])); ?><br><small><?php echo date('g:i A', strtotime($event['event_time'])); ?></small></td>
                                            <td><?php echo htmlspecialchars($event['venue']); ?></td>
                                            <td class="text-end"><?php echo $event['total_bookings']; ?> / <?php echo $event['capacity']; ?></td>
                                            <td class="text-end">
                                                <?php echo $event['today_bookings']; ?>
                                                <?php if ($event['today_bookings'] > 0): ?>
                                                    <span class="today-badge">today</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?php echo $event['checked_in']; ?></td>
                                            <td class="text-end text-success fw-bold">KSh <?php echo number_format($event['total_revenue'], 0); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="height: 5px;">
                                                        <div class="progress-bar bg-success" style="width: <?php echo $fill_rate; ?>%"></div>
                                                    </div>
                                                    <small><?php echo $fill_rate; ?>%</small>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-alt"></i>
                                <h5>No Events Data</h5>
                                <p class="text-muted">No events found for this period</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- 3. ENTRANCE PAYMENTS REPORT TAB -->
            <div class="tab-pane fade" id="entrance" role="tabpanel">
                <!-- Entrance Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number">KSh <?php echo number_format($entrance_summary['total_revenue'], 0); ?></div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $entrance_summary['total_payments']; ?></div>
                            <div class="stat-label">Total Tickets</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $entrance_summary['today_payments']; ?></div>
                            <div class="stat-label">Today's Tickets</div>
                            <small class="text-success">KSh <?php echo number_format($entrance_summary['today_revenue'], 0); ?></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $entrance_summary['unique_visitors']; ?></div>
                            <div class="stat-label">Unique Visitors</div>
                        </div>
                    </div>
                </div>
                
                <!-- Payment Method Breakdown -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="method-breakdown">
                            <h5 class="text-success">Cash</h5>
                            <h3>KSh <?php echo number_format($entrance_summary['cash'], 0); ?></h3>
                            <small class="text-muted">
                                <?php echo $entrance_summary['total_revenue'] > 0 ? round(($entrance_summary['cash'] / $entrance_summary['total_revenue']) * 100, 1) : 0; ?>%
                            </small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="method-breakdown">
                            <h5 class="text-primary">M-Pesa</h5>
                            <h3>KSh <?php echo number_format($entrance_summary['mpesa'], 0); ?></h3>
                            <small class="text-muted">
                                <?php echo $entrance_summary['total_revenue'] > 0 ? round(($entrance_summary['mpesa'] / $entrance_summary['total_revenue']) * 100, 1) : 0; ?>%
                            </small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="method-breakdown">
                            <h5 class="text-info">Card</h5>
                            <h3>KSh <?php echo number_format($entrance_summary['card'], 0); ?></h3>
                            <small class="text-muted">
                                <?php echo $entrance_summary['total_revenue'] > 0 ? round(($entrance_summary['card'] / $entrance_summary['total_revenue']) * 100, 1) : 0; ?>%
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Daily Entrance Details -->
                <div class="card report-card">
                    <div class="report-header">
                        <i class="fas fa-ticket-alt me-2"></i>Daily Entrance Payments
                    </div>
                    <div class="card-body">
                        <?php if (!empty($entrance_data)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th class="text-end">Tickets</th>
                                            <th class="text-end">Cash</th>
                                            <th class="text-end">M-Pesa</th>
                                            <th class="text-end">Card</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-end">Unique Visitors</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($entrance_data as $day): ?>
                                        <tr <?php echo ($day['payment_day'] == $today) ? 'class="table-success"' : ''; ?>>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($day['payment_day'])); ?>
                                                <?php if ($day['payment_day'] == $today): ?>
                                                    <span class="today-badge">today</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?php echo $day['daily_count']; ?></td>
                                            <td class="text-end text-success">KSh <?php echo number_format($day['cash_amount'], 0); ?></td>
                                            <td class="text-end text-primary">KSh <?php echo number_format($day['mpesa_amount'], 0); ?></td>
                                            <td class="text-end text-info">KSh <?php echo number_format($day['card_amount'], 0); ?></td>
                                            <td class="text-end fw-bold">KSh <?php echo number_format($day['daily_amount'], 0); ?></td>
                                            <td class="text-end"><?php echo $day['unique_customers']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-ticket-alt"></i>
                                <h5>No Entrance Data</h5>
                                <p class="text-muted">No entrance payments recorded for this period</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- 4. ARTWORK SUBMISSIONS REPORT TAB -->
            <div class="tab-pane fade" id="submissions" role="tabpanel">
                <!-- Submissions Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $submissions_summary['total_submissions']; ?></div>
                            <div class="stat-label">Total Submissions</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number text-success"><?php echo $submissions_summary['approved']; ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number text-danger"><?php echo $submissions_summary['rejected']; ?></div>
                            <div class="stat-label">Rejected</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number text-warning"><?php echo $submissions_summary['pending']; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>
                
                <!-- Submissions Details Table -->
                <div class="card report-card">
                    <div class="report-header">
                        <i class="fas fa-cloud-upload-alt me-2"></i>Artwork Submissions Details
                    </div>
                    <div class="card-body">
                        <?php if (!empty($submissions_data)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Artist</th>
                                            <th>Artwork</th>
                                            <th>Status</th>
                                            <th>Rejection Reason</th>
                                            <th>Admin Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($submissions_data as $sub): ?>
                                        <tr>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($sub['submitted_at'])); ?>
                                                <?php if (date('Y-m-d', strtotime($sub['submitted_at'])) == $today): ?>
                                                    <span class="today-badge">today</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($sub['artist_name']); ?>
                                                <br><small><?php echo htmlspecialchars($sub['artist_email']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($sub['title']); ?></td>
                                            <td>
                                                <?php if ($sub['image_approved'] == 1): ?>
                                                    <span class="badge badge-approved">Approved</span>
                                                <?php elseif ($sub['status'] == 'rejected'): ?>
                                                    <span class="badge badge-rejected">Rejected</span>
                                                <?php else: ?>
                                                    <span class="badge badge-pending">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($sub['rejection_reason']): ?>
                                                    <span class="badge bg-warning">
                                                        <?php echo $rejection_reasons_display[$sub['rejection_reason']] ?? $sub['rejection_reason']; ?>
                                                    </span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($sub['admin_notes'] ?? '-'); ?></small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <h5>No Submissions Data</h5>
                                <p class="text-muted">No artwork submissions for this period</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- 5. ARTISTS REPORT TAB - FIXED to show ALL artists -->
            <div class="tab-pane fade" id="artists" role="tabpanel">
                <!-- Artists Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $artists_summary['total_artists']; ?></div>
                            <div class="stat-label">Total Artists</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $artists_summary['total_artworks']; ?></div>
                            <div class="stat-label">Total Artworks</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $artists_summary['with_artworks']; ?></div>
                            <div class="stat-label">Artists with Artworks</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $artists_summary['active_agreements']; ?></div>
                            <div class="stat-label">Active Agreements</div>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Artist Stats -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="stat-card">
                            <div class="stat-number text-info"><?php echo $artists_summary['total_sold']; ?></div>
                            <div class="stat-label">Total Artworks Sold</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="stat-card">
                            <div class="stat-number text-primary"><?php echo $artists_summary['new_this_period']; ?></div>
                            <div class="stat-label">New Artists (Selected Period)</div>
                        </div>
                    </div>
                </div>
                
                <!-- Artists Details Table -->
                <div class="card report-card">
                    <div class="report-header">
                        <i class="fas fa-users me-2"></i>All Artists Directory
                    </div>
                    <div class="card-body">
                        <?php if (!empty($artists_data)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Artist</th>
                                            <th>Contact</th>
                                            <th>Registered</th>
                                            <th class="text-end">Total Artworks</th>
                                            <th class="text-end">Available</th>
                                            <th class="text-end">Sold</th>
                                            <th class="text-end">Agreements</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($artists_data as $artist): 
                                            $is_new = (date('Y-m-d', strtotime($artist['created_at'])) >= $start_date && 
                                                      date('Y-m-d', strtotime($artist['created_at'])) <= $end_date);
                                        ?>
                                        <tr class="<?php echo $is_new ? 'table-primary' : ''; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($artist['first_name'] . ' ' . $artist['last_name']); ?></strong>
                                                <?php if (date('Y-m-d', strtotime($artist['created_at'])) == $today): ?>
                                                    <span class="today-badge">new today</span>
                                                <?php elseif ($is_new): ?>
                                                    <span class="badge bg-info">new this period</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($artist['email']); ?></small>
                                                <br><small><?php echo htmlspecialchars($artist['phone'] ?? 'No phone'); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo date('M d, Y', strtotime($artist['created_at'])); ?></small>
                                            </td>
                                            <td class="text-end fw-bold"><?php echo $artist['artwork_count']; ?></td>
                                            <td class="text-end text-success"><?php echo $artist['available_count'] ?? 0; ?></td>
                                            <td class="text-end text-primary"><?php echo $artist['sold_count']; ?></td>
                                            <td class="text-end text-info"><?php echo $artist['agreement_count']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Summary Legend -->
                            <div class="mt-3 d-flex gap-3">
                                <div><span class="badge bg-primary">Blue rows</span> = New artists in selected period</div>
                                <div><span class="badge bg-success">Today badge</span> = Registered today</div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <h5>No Artists Found</h5>
                                <p class="text-muted">No artists have been registered yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportAllReports() {
            // Create CSV content
            let csv = [];
            
            // Header
            csv.push(['ARDHI GALLERY - COMPREHENSIVE REPORT']);
            csv.push(['Generated:', new Date().toLocaleString()]);
            csv.push(['Period:', '<?php echo $start_date; ?> to <?php echo $end_date; ?>']);
            csv.push(['Today:', '<?php echo $today; ?>']);
            csv.push([]);
            
            // Today's Stats
            csv.push(['TODAY\'S ACTIVITY']);
            csv.push(['Entrance Tickets:', '<?php echo $today_stats['entrance_tickets']; ?>', 'Revenue:', 'KSh <?php echo number_format($today_stats['entrance_revenue'], 0); ?>']);
            csv.push(['Event Bookings:', '<?php echo $events_summary['today_bookings']; ?>', 'Revenue:', 'KSh <?php echo number_format($events_summary['today_revenue'], 0); ?>']);
            csv.push(['Art Sales:', '<?php echo $today_stats['art_sales']; ?>', 'Revenue:', 'KSh <?php echo number_format($today_stats['art_revenue'], 0); ?>']);
            csv.push(['Submissions:', '<?php echo $submissions_summary['today_submissions']; ?>']);
            csv.push(['New Artists:', '<?php echo $artists_summary['today_artists']; ?>']);
            csv.push(['Total Today Revenue:', 'KSh <?php echo number_format($grand_total['today_revenue'], 0); ?>']);
            csv.push([]);
            
            // Grand Total
            csv.push(['GRAND TOTAL REVENUE']);
            csv.push(['Total Revenue:', 'KSh <?php echo number_format($grand_total['total_revenue'], 0); ?>']);
            csv.push(['Art Sales:', 'KSh <?php echo number_format($grand_total['sales_revenue'], 0); ?>']);
            csv.push(['Event Revenue:', 'KSh <?php echo number_format($grand_total['event_revenue'], 0); ?>']);
            csv.push(['Entrance Revenue:', 'KSh <?php echo number_format($grand_total['entrance_revenue'], 0); ?>']);
            csv.push([]);
            
            // Sales Summary
            csv.push(['ART SALES SUMMARY']);
            csv.push(['Total Sales:', 'KSh <?php echo number_format($sales_summary['total_sales'], 0); ?>']);
            csv.push(['Commission:', 'KSh <?php echo number_format($sales_summary['total_commission'], 0); ?>']);
            csv.push(['Artist Payout:', 'KSh <?php echo number_format($sales_summary['total_artist_payout'], 0); ?>']);
            csv.push(['Transactions:', '<?php echo $sales_summary['total_transactions']; ?>']);
            csv.push([]);
            
            // Events Summary
            csv.push(['EVENTS SUMMARY']);
            csv.push(['Total Events:', '<?php echo $events_summary['total_events']; ?>']);
            csv.push(['Total Bookings:', '<?php echo $events_summary['total_bookings']; ?>']);
            csv.push(['Today\'s Bookings:', '<?php echo $events_summary['today_bookings']; ?>']);
            csv.push(['Event Revenue:', 'KSh <?php echo number_format($events_summary['total_revenue'], 0); ?>']);
            csv.push(['Checked In:', '<?php echo $events_summary['checked_in']; ?>']);
            csv.push([]);
            
            // Entrance Summary
            csv.push(['ENTRANCE PAYMENTS']);
            csv.push(['Tickets Sold:', '<?php echo $entrance_summary['total_payments']; ?>']);
            csv.push(['Entrance Revenue:', 'KSh <?php echo number_format($entrance_summary['total_revenue'], 0); ?>']);
            csv.push(['Today\'s Tickets:', '<?php echo $entrance_summary['today_payments']; ?>']);
            csv.push(['Today\'s Revenue:', 'KSh <?php echo number_format($entrance_summary['today_revenue'], 0); ?>']);
            csv.push(['Cash:', 'KSh <?php echo number_format($entrance_summary['cash'], 0); ?>']);
            csv.push(['M-Pesa:', 'KSh <?php echo number_format($entrance_summary['mpesa'], 0); ?>']);
            csv.push(['Card:', 'KSh <?php echo number_format($entrance_summary['card'], 0); ?>']);
            csv.push(['Unique Visitors:', '<?php echo $entrance_summary['unique_visitors']; ?>']);
            csv.push([]);
            
            // Submissions Summary
            csv.push(['ARTWORK SUBMISSIONS']);
            csv.push(['Total Submissions:', '<?php echo $submissions_summary['total_submissions']; ?>']);
            csv.push(['Today\'s Submissions:', '<?php echo $submissions_summary['today_submissions']; ?>']);
            csv.push(['Approved:', '<?php echo $submissions_summary['approved']; ?>']);
            csv.push(['Rejected:', '<?php echo $submissions_summary['rejected']; ?>']);
            csv.push(['Pending:', '<?php echo $submissions_summary['pending']; ?>']);
            csv.push([]);
            
            // Artists Summary
            csv.push(['ARTISTS DIRECTORY']);
            csv.push(['Total Artists:', '<?php echo $artists_summary['total_artists']; ?>']);
            csv.push(['Total Artworks:', '<?php echo $artists_summary['total_artworks']; ?>']);
            csv.push(['Artists with Artworks:', '<?php echo $artists_summary['with_artworks']; ?>']);
            csv.push(['Total Sold:', '<?php echo $artists_summary['total_sold']; ?>']);
            csv.push(['Active Agreements:', '<?php echo $artists_summary['active_agreements']; ?>']);
            csv.push(['New Artists (Period):', '<?php echo $artists_summary['new_this_period']; ?>']);
            csv.push(['New Artists (Today):', '<?php echo $artists_summary['today_artists']; ?>']);
            
            // Download CSV
            const csvString = csv.map(row => row.join(',')).join('\n');
            const blob = new Blob([csvString], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'gallery_report_<?php echo date('Y-m-d'); ?>.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            alert('Reports exported successfully!');
        }
    </script>
</body>
</html>