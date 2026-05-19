<?php
session_start();
require_once 'config.php';

// Use the isAdmin() function from config.php
if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header("Location: login.php");
    exit();
}

$today = date('Y-m-d');
$current_month = date('Y-m');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$first_day_month = date('Y-m-01');

// Helper function for time ago
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff/60) . ' min ago';
        if ($diff < 86400) return floor($diff/3600) . ' hours ago';
        if ($diff < 2592000) return floor($diff/86400) . ' days ago';
        return date('M j', $time);
    }
}

try {
    
    // Get pending uploads count for the badge
    $pending_uploads = 0;
    try {
        $upload_stmt = $db->query("SELECT COUNT(*) FROM upload_requests WHERE status = 'pending'");
        $pending_uploads = $upload_stmt->fetchColumn();
    } catch (Exception $e) {
        $pending_uploads = 0;
    }
    
    // Get rejection statistics
    $rejection_stats = [];
    try {
        $rejection_stmt = $db->query("
            SELECT 
                rejection_reason,
                COUNT(*) as count
            FROM upload_requests
            WHERE status = 'rejected'
            GROUP BY rejection_reason
            ORDER BY count DESC
        ");
        $rejection_stats = $rejection_stmt->fetchAll();
        
        $total_rejections = array_sum(array_column($rejection_stats, 'count'));
    } catch (Exception $e) {
        $rejection_stats = [];
        $total_rejections = 0;
    }
    
    // 1. OVERALL GALLERY STATISTICS - FIXED: Removed event_attendees
    $gallery_stats_query = "SELECT 
        (SELECT COUNT(*) FROM users WHERE role != 'admin') as total_customers,
        (SELECT COUNT(*) FROM artworks) as total_artworks,
        (SELECT COUNT(*) FROM artworks WHERE status = 'available' OR status IS NULL) as available_artworks,
        (SELECT COUNT(*) FROM artists) as total_artists,
        (SELECT COUNT(*) FROM consignment_agreements WHERE status = 'active') as active_consignments,
        (SELECT COUNT(*) FROM events WHERE is_active = 1 AND event_date >= CURDATE()) as upcoming_events,
        (SELECT COUNT(*) FROM payments WHERE DATE(payment_date) = ? AND payment_type = 'entrance' AND status = 'completed') as today_visitors,
        (SELECT COUNT(*) FROM payments WHERE DATE(payment_date) = ? AND payment_type = 'entrance' AND status = 'completed' AND checked_in = 1) as today_checked_in";
    
    $gallery_stats_stmt = $db->prepare($gallery_stats_query);
    $gallery_stats_stmt->execute([$today, $today]);
    $gallery_stats = $gallery_stats_stmt->fetch();

    // 2. TODAY'S REVENUE BREAKDOWN FROM PAYMENTS TABLE
    $today_revenue_query = "SELECT 
        -- Total Revenue
        COALESCE(SUM(amount), 0) as total_revenue,
        COUNT(*) as total_transactions,
        
        -- Entrance Revenue
        COALESCE(SUM(CASE WHEN payment_type = 'entrance' THEN amount ELSE 0 END), 0) as entrance_revenue,
        COUNT(CASE WHEN payment_type = 'entrance' THEN 1 END) as entrance_transactions,
        
        -- Art Sales Revenue
        COALESCE(SUM(CASE WHEN payment_type IN ('sale', 'art_sale', 'art_purchase') THEN amount ELSE 0 END), 0) as art_sales_revenue,
        COUNT(CASE WHEN payment_type IN ('sale', 'art_sale', 'art_purchase') THEN 1 END) as art_sales_transactions,
        
        -- Event Revenue
        COALESCE(SUM(CASE WHEN payment_type = 'event_booking' THEN amount ELSE 0 END), 0) as event_revenue,
        COUNT(CASE WHEN payment_type = 'event_booking' THEN 1 END) as event_transactions,
        
        -- Pending Revenue
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_revenue
        FROM payments 
        WHERE DATE(payment_date) = ? 
        AND status = 'completed'";
    
    $today_revenue_stmt = $db->prepare($today_revenue_query);
    $today_revenue_stmt->execute([$today]);
    $today_revenue = $today_revenue_stmt->fetch();

    // 3. PAYMENT METHOD BREAKDOWN FOR TODAY
    $payment_methods_query = "SELECT 
        payment_method,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as amount,
        ROUND((COUNT(*) * 100.0 / NULLIF((SELECT COUNT(*) FROM payments WHERE DATE(payment_date) = ? AND status = 'completed'), 0)), 1) as percentage
        FROM payments 
        WHERE DATE(payment_date) = ?
        AND status = 'completed'
        GROUP BY payment_method
        ORDER BY amount DESC";
    
    $payment_methods_stmt = $db->prepare($payment_methods_query);
    $payment_methods_stmt->execute([$today, $today]);
    $payment_methods = $payment_methods_stmt->fetchAll();

    // 4. MONTHLY REVENUE BREAKDOWN
    $monthly_revenue_query = "SELECT 
        payment_type,
        COUNT(*) as transaction_count,
        COALESCE(SUM(amount), 0) as total_amount,
        ROUND((COUNT(*) * 100.0 / NULLIF((SELECT COUNT(*) FROM payments WHERE DATE(payment_date) >= ? AND status = 'completed'), 0)), 1) as percentage
        FROM payments 
        WHERE DATE(payment_date) >= ?
        AND status = 'completed'
        GROUP BY payment_type
        ORDER BY total_amount DESC";
    
    $monthly_revenue_stmt = $db->prepare($monthly_revenue_query);
    $monthly_revenue_stmt->execute([$first_day_month, $first_day_month]);
    $monthly_revenue = $monthly_revenue_stmt->fetchAll();

    // 5. YESTERDAY REVENUE FOR GROWTH CALCULATION
    $yesterday_revenue_query = "SELECT COALESCE(SUM(amount), 0) as revenue_yesterday 
                                FROM payments 
                                WHERE DATE(payment_date) = ? 
                                AND status = 'completed'";
    $yesterday_revenue_stmt = $db->prepare($yesterday_revenue_query);
    $yesterday_revenue_stmt->execute([$yesterday]);
    $yesterday_revenue = $yesterday_revenue_stmt->fetchColumn();

    // 6. RECENT PAYMENTS (ALL TYPES)
    $recent_payments_query = "SELECT 
        p.*, 
        p.customer_name,
        p.customer_email,
        p.payment_method,
        p.amount,
        p.payment_type,
        p.created_at,
        CASE 
            WHEN p.payment_type = 'entrance' THEN 'Entrance Fee'
            WHEN p.payment_type IN ('sale', 'art_sale', 'art_purchase') THEN 'Art Sale'
            WHEN p.payment_type = 'event_booking' THEN 'Event Booking'
            WHEN p.payment_type = 'artist' THEN 'Artist Payment'
            ELSE p.payment_type
        END as display_type
        FROM payments p
        WHERE p.status = 'completed'
        ORDER BY p.created_at DESC
        LIMIT 10";
    
    $recent_payments_stmt = $db->prepare($recent_payments_query);
    $recent_payments_stmt->execute();
    $recent_payments = $recent_payments_stmt->fetchAll();

    // 7. ENTRANCE SPECIFIC STATISTICS FROM PAYMENTS
    $entrance_stats_query = "SELECT 
        'General Admission' as ticket_type,
        COUNT(*) as count,
        COUNT(*) as total_tickets,
        COALESCE(SUM(amount), 0) as revenue,
        AVG(amount) as avg_price,
        (SELECT COUNT(*) FROM payments WHERE DATE(payment_date) = ? AND payment_type = 'entrance' AND checked_in = 1) as checked_in
        FROM payments 
        WHERE DATE(payment_date) = ? 
        AND payment_type = 'entrance'
        AND status = 'completed'";
    
    $entrance_stats_stmt = $db->prepare($entrance_stats_query);
    $entrance_stats_stmt->execute([$today, $today]);
    $entrance_stats = $entrance_stats_stmt->fetchAll();

    // 8. ART STATISTICS
    $art_stats_query = "SELECT 
        COUNT(DISTINCT a.id) as total_artworks,
        COUNT(DISTINCT CASE WHEN a.status = 'available' OR a.status IS NULL THEN a.id END) as available_artworks,
        COUNT(DISTINCT art.id) as total_artists,
        COUNT(DISTINCT ca.id) as active_consignments
        FROM artworks a
        LEFT JOIN artists art ON a.artist_id = art.id
        LEFT JOIN consignment_agreements ca ON ca.artist_id = art.id AND ca.status = 'active'";
    
    $art_stats_stmt = $db->prepare($art_stats_query);
    $art_stats_stmt->execute();
    $art_stats = $art_stats_stmt->fetch();

    // 9. PENDING ARTIST PAYMENTS (from payments table)
    $pending_artist_payments_query = "SELECT 
        COUNT(*) as count, 
        COALESCE(SUM(amount), 0) as total_amount
        FROM payments 
        WHERE payment_type = 'artist' 
        AND status = 'pending'";
    
    $pending_artist_payments_stmt = $db->prepare($pending_artist_payments_query);
    $pending_artist_payments_stmt->execute();
    $pending_artist_payments = $pending_artist_payments_stmt->fetch();

    // 10. EVENT STATISTICS - FIXED: Removed event_attendees
    $event_stats_query = "SELECT 
        (SELECT COUNT(*) FROM events WHERE status = 'upcoming' AND is_active = 1) as upcoming_events,
        (SELECT COUNT(*) FROM events WHERE status = 'ongoing' AND is_active = 1) as ongoing_events,
        (SELECT COUNT(*) FROM payments WHERE DATE(payment_date) = ? AND payment_type = 'event_booking' AND status = 'completed') as today_bookings,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(payment_date) = ? AND payment_type = 'event_booking' AND status = 'completed') as today_event_revenue,
        (SELECT COUNT(*) FROM payments WHERE payment_type = 'event_booking' AND status = 'completed' AND (checked_in IS NULL OR checked_in = 0)) as pending_bookings";
    
    $event_stats_stmt = $db->prepare($event_stats_query);
    $event_stats_stmt->execute([$today, $today]);
    $event_stats = $event_stats_stmt->fetch();

    // 11. DAILY REVENUE TREND (LAST 7 DAYS)
    $revenue_trend_query = "SELECT 
        DATE(payment_date) as date,
        COUNT(*) as transactions,
        COALESCE(SUM(amount), 0) as revenue,
        COALESCE(SUM(CASE WHEN payment_type = 'entrance' THEN amount ELSE 0 END), 0) as entrance_revenue,
        COALESCE(SUM(CASE WHEN payment_type IN ('sale', 'art_sale', 'art_purchase') THEN amount ELSE 0 END), 0) as art_revenue,
        COALESCE(SUM(CASE WHEN payment_type = 'event_booking' THEN amount ELSE 0 END), 0) as event_revenue
        FROM payments 
        WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND status = 'completed'
        GROUP BY DATE(payment_date)
        ORDER BY date";
    
    $revenue_trend_stmt = $db->prepare($revenue_trend_query);
    $revenue_trend_stmt->execute();
    $revenue_trend = $revenue_trend_stmt->fetchAll();

    // 12. RECENT ATTENDEES - FIXED: Using payments instead of event_attendees
    $recent_attendees_query = "SELECT 
        p.customer_name,
        p.customer_email,
        p.phone_number,
        p.amount as amount_paid,
        p.payment_date as created_at,
        p.checked_in,
        p.check_in_time,
        e.title as event_title,
        CASE 
            WHEN p.checked_in = 1 THEN 'checked_in'
            ELSE 'booked'
        END as status
        FROM payments p
        LEFT JOIN events e ON p.description LIKE CONCAT('%', e.title, '%')
        WHERE p.payment_type = 'event_booking'
        AND p.status = 'completed'
        ORDER BY p.payment_date DESC
        LIMIT 5";
    
    $recent_attendees_stmt = $db->prepare($recent_attendees_query);
    $recent_attendees_stmt->execute();
    $recent_attendees = $recent_attendees_stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    // Set default values
    $gallery_stats = [
        'total_customers' => 0,
        'total_artworks' => 0,
        'available_artworks' => 0,
        'total_artists' => 0,
        'active_consignments' => 0,
        'upcoming_events' => 0,
        'today_visitors' => 0,
        'today_checked_in' => 0
    ];
    $today_revenue = [
        'total_revenue' => 0,
        'total_transactions' => 0,
        'entrance_revenue' => 0,
        'entrance_transactions' => 0,
        'art_sales_revenue' => 0,
        'art_sales_transactions' => 0,
        'event_revenue' => 0,
        'event_transactions' => 0,
        'pending_revenue' => 0
    ];
    $payment_methods = [];
    $monthly_revenue = [];
    $yesterday_revenue = 0;
    $recent_payments = [];
    $entrance_stats = [];
    $art_stats = [
        'total_artworks' => 0,
        'available_artworks' => 0,
        'total_artists' => 0,
        'active_consignments' => 0
    ];
    $pending_artist_payments = ['count' => 0, 'total_amount' => 0];
    $event_stats = [
        'upcoming_events' => 0,
        'ongoing_events' => 0,
        'today_bookings' => 0,
        'today_event_revenue' => 0,
        'pending_bookings' => 0
    ];
    $revenue_trend = [];
    $recent_attendees = [];
}

// Calculate growth percentage
$today_total_revenue = $today_revenue['total_revenue'] ?? 0;
$revenue_growth = $yesterday_revenue > 0 ? (($today_total_revenue - $yesterday_revenue) / $yesterday_revenue) * 100 : 0;

// Calculate check-in rate
$today_visitors = $gallery_stats['today_visitors'] ?? 0;
$today_checked_in = $gallery_stats['today_checked_in'] ?? 0;
$checkin_rate = $today_visitors > 0 ? ($today_checked_in / $today_visitors) * 100 : 0;

// Calculate total tickets sold
$tickets_sold = 0;
foreach ($entrance_stats as $stat) {
    $tickets_sold += $stat['total_tickets'] ?? 0;
}

// Calculate monthly total
$monthly_total = 0;
foreach ($monthly_revenue as $revenue) {
    $monthly_total += $revenue['total_amount'];
}

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
    <title>Admin Dashboard - Ardhi Gallery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --warning-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --dark-gradient: linear-gradient(135deg, #30cfd0 0%, #330867 100%);
            
            --bg-primary: #f8f9fd;
            --card-shadow: 0 2px 12px rgba(0,0,0,0.08);
            --card-hover-shadow: 0 8px 30px rgba(0,0,0,0.12);
            --border-radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background: var(--bg-primary);
            color: #1a1a2e;
            overflow-x: hidden;
        }
        
        /* Sidebar Navigation */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: #ffffff;
            box-shadow: 2px 0 20px rgba(0,0,0,0.05);
            z-index: 1000;
            overflow-y: auto;
            transition: var(--transition);
        }
        
        .sidebar-header {
            padding: 30px 25px;
            background: var(--primary-gradient);
            color: white;
        }
        
        .sidebar-header h4 {
            margin: 0;
            font-weight: 700;
            font-size: 1.3rem;
            letter-spacing: -0.5px;
        }
        
        .sidebar-header .user-info {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.2);
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        .sidebar-menu {
            padding: 20px 0;
        }
        
        .sidebar-menu-item {
            margin: 5px 15px;
            border-radius: 12px;
            transition: var(--transition);
        }
        
        .sidebar-menu-item a {
            display: flex;
            align-items: center;
            padding: 14px 18px;
            color: #5a5a72;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: var(--transition);
            border-radius: 12px;
        }
        
        .sidebar-menu-item a i {
            width: 22px;
            margin-right: 12px;
            font-size: 1.1rem;
        }
        
        .sidebar-menu-item:hover a,
        .sidebar-menu-item.active a {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transform: translateX(4px);
        }
        
        .sidebar-section-title {
            padding: 25px 25px 10px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #a0a0b8;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }
        
        /* Top Header */
        .top-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }
        
        .top-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 400px;
            height: 100%;
            background: var(--primary-gradient);
            opacity: 0.05;
            border-radius: 50% 0 0 50%;
            transform: translateX(50%);
        }
        
        .header-title {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 8px;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header-subtitle {
            color: #7a7a92;
            font-size: 0.95rem;
        }
        
        /* Stat Cards */
        .stat-card-modern {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            height: 100%;
        }
        
        .stat-card-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-gradient);
        }
        
        .stat-card-modern:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-hover-shadow);
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 5px;
            line-height: 1;
        }
        
        .stat-label {
            color: #7a7a92;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 10px;
        }
        
        .stat-change {
            display: inline-flex;
            align-items: center;
            font-size: 0.8rem;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .stat-change.positive {
            background: #d4edda;
            color: #28a745;
        }
        
        .stat-change.negative {
            background: #f8d7da;
            color: #dc3545;
        }
        
        /* Revenue Card */
        .revenue-hero {
            background: var(--primary-gradient);
            border-radius: var(--border-radius);
            padding: 40px;
            color: white;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .revenue-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        
        .revenue-amount {
            font-size: 3.5rem;
            font-weight: 900;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .revenue-label {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 500;
        }
        
        /* Chart Cards */
        .chart-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f1f5;
        }
        
        .chart-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0;
        }
        
        /* Quick Actions */
        .quick-action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .quick-action-btn-modern {
            background: white;
            border: 2px solid #e8e8f0;
            border-radius: 14px;
            padding: 20px 15px;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            color: #1a1a2e;
        }
        
        .quick-action-btn-modern:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-hover-shadow);
            border-color: #667eea;
            color: #667eea;
        }
        
        .quick-action-btn-modern i {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .quick-action-btn-modern span {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        /* Activity Feed */
        .activity-feed {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            padding: 15px 0;
            border-bottom: 1px solid #f1f1f5;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex-grow: 1;
        }
        
        .activity-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }
        
        .activity-meta {
            font-size: 0.8rem;
            color: #7a7a92;
        }
        
        .activity-amount {
            font-weight: 700;
            color: #28a745;
            font-size: 1rem;
        }
        
        /* Alerts */
        .alert-modern {
            border-radius: 12px;
            border: none;
            padding: 18px 20px;
            margin-bottom: 15px;
            border-left: 4px solid;
        }
        
        .alert-modern .alert-icon {
            font-size: 1.3rem;
            margin-right: 12px;
        }
        
        .alert-modern .alert-content {
            flex-grow: 1;
        }
        
        .alert-modern .alert-title {
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .alert-modern .alert-text {
            font-size: 0.85rem;
            margin-bottom: 10px;
        }
        
        /* Badges */
        .badge-modern {
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.75rem;
            letter-spacing: 0.3px;
        }
        
        /* Table Styles */
        .table-modern {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .table-modern thead {
            background: #f8f9fd;
        }
        
        .table-modern th {
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #5a5a72;
            border: none;
            padding: 18px 20px;
        }
        
        .table-modern td {
            padding: 18px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f1f5;
        }
        
        .table-modern tr:last-child td {
            border-bottom: none;
        }
        
        /* Progress Bars */
        .progress-modern {
            height: 8px;
            border-radius: 10px;
            background: #f1f1f5;
            overflow: hidden;
        }
        
        .progress-modern .progress-bar {
            border-radius: 10px;
        }
        
        /* Responsive */
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .revenue-amount {
                font-size: 2.5rem;
            }
        }
        
        /* Mobile Toggle */
        .mobile-toggle {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            border: none;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.4);
            z-index: 999;
            font-size: 1.5rem;
        }
        
        @media (max-width: 991px) {
            .mobile-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f5;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #c0c0d0;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #a0a0b8;
        }

        /* Rejection Stats */
        .rejection-stat {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .rejection-reason {
            font-size: 0.9rem;
            color: #856404;
        }
        .rejection-count {
            font-weight: bold;
            color: #856404;
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle -->
    <button class="mobile-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar - WITH ALL LINKS INCLUDING REPORTS AND COMMISSIONS -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4><i class="fas fa-palette me-2"></i>Ardhi Gallery</h4>
            <div class="user-info">
                <div><i class="fas fa-user-shield me-2"></i><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></div>
                <small><?php echo date('l, M j, Y'); ?></small>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <!-- Main Menu Section -->
            <div class="sidebar-section-title">Main Menu</div>
            
            <div class="sidebar-menu-item active">
                <a href="admin.php">
                    <i class="fas fa-chart-line"></i>
                    Dashboard
                </a>
            </div>
            
            <!-- Art Management Section -->
            <div class="sidebar-section-title">Art Management</div>
            
            <div class="sidebar-menu-item">
                <a href="artists.php">
                    <i class="fas fa-users"></i>
                    Artists
                </a>
            </div>
            
            <div class="sidebar-menu-item">
                <a href="artworks.php">
                    <i class="fas fa-palette"></i>
                    Artworks
                </a>
            </div>
            
            <!-- Artist Uploads Link with Badge -->
            <div class="sidebar-menu-item">
                <a href="admin_manage_uploads.php">
                    <i class="fas fa-cloud-upload-alt"></i>
                    Artist Uploads
                    <?php if ($pending_uploads > 0): ?>
                        <span class="badge bg-danger ms-2"><?php echo $pending_uploads; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            
            <div class="sidebar-menu-item">
                <a href="art_sales.php">
                    <i class="fas fa-shopping-cart"></i>
                    Art Sales
                </a>
            </div>
            
            <div class="sidebar-menu-item">
                <a href="consignments.php">
                    <i class="fas fa-file-signature"></i>
                    Consignments
                </a>
            </div>
            
            <!-- Events & Visitors Section -->
            <div class="sidebar-section-title">Events & Visitors</div>
            
            <div class="sidebar-menu-item">
                <a href="events_management.php">
                    <i class="fas fa-calendar-alt"></i>
                    Events
                </a>
            </div>
            
            <div class="sidebar-menu-item">
                <a href="admin_attendees.php">
                    <i class="fas fa-users"></i>
                    Attendees
                </a>
            </div>
            
            <!-- Finance Section - WITH REPORTS AND COMMISSIONS ADDED -->
            <div class="sidebar-section-title">Finance</div>
            
            <div class="sidebar-menu-item">
                <a href="admin_payments.php">
                    <i class="fas fa-credit-card"></i>
                    Payments
                </a>
            </div>
            
            <div class="sidebar-menu-item">
                <a href="reconciliation.php">
                    <i class="fas fa-balance-scale"></i>
                    Reconciliation
                </a>
            </div>
            
            <!-- REPORTS LINK -->
            <div class="sidebar-menu-item">
                <a href="admin_reports.php">
                    <i class="fas fa-chart-pie"></i>
                    Reports
                </a>
            </div>
            
            <!-- COMMISSIONS DROPDOWN - WITH ARTIST PAYMENTS ADDED -->
            <div class="sidebar-menu-item dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-hand-holding-usd"></i>
                    Commissions
                </a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="admin_record_sale.php">
                        <i class="fas fa-plus-circle me-2"></i>Record Sale
                    </a></li>
                    <li><a class="dropdown-item" href="commissions_list.php">
                        <i class="fas fa-list me-2"></i>View Commissions
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="commission_reports.php">
                        <i class="fas fa-chart-bar me-2"></i>Commission Reports
                    </a></li>
                    <!-- ARTIST PAYMENTS LINK - NEW -->
                    <li><a class="dropdown-item" href="pay_artist.php">
                        <i class="fas fa-money-bill-wave me-2" style="color: #28a745;"></i>Artist Payments
                    </a></li>
                </ul>
            </div>
            
            <!-- Account Section -->
            <div class="sidebar-section-title">Account</div>
            
            <div class="sidebar-menu-item">
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Header -->
        <div class="top-header">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="header-title">Dashboard Overview</h1>
                    <p class="header-subtitle">Welcome back! Here's what's happening with your gallery today.</p>
                </div>
                <div class="col-lg-4">
                    <div class="revenue-hero text-center">
                        <div class="revenue-label">Today's Revenue</div>
                        <div class="revenue-amount">KSh <?php echo number_format($today_total_revenue, 0); ?></div>
                        <?php if ($revenue_growth > 0): ?>
                            <span class="stat-change positive">
                                <i class="fas fa-arrow-up me-1"></i> <?php echo number_format($revenue_growth, 1); ?>% vs yesterday
                            </span>
                        <?php elseif ($revenue_growth < 0): ?>
                            <span class="stat-change negative">
                                <i class="fas fa-arrow-down me-1"></i> <?php echo number_format(abs($revenue_growth), 1); ?>% vs yesterday
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="row g-3 mb-4">
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stat-card-modern">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo $today_revenue['entrance_transactions'] ?? 0; ?></div>
                    <div class="stat-label">Entrance Payments</div>
                    <small class="text-success fw-bold">KSh <?php echo number_format($today_revenue['entrance_revenue'] ?? 0, 0); ?></small>
                </div>
            </div>
            
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stat-card-modern">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                        <i class="fas fa-palette"></i>
                    </div>
                    <div class="stat-value"><?php echo $today_revenue['art_sales_transactions'] ?? 0; ?></div>
                    <div class="stat-label">Art Sales</div>
                    <small class="text-success fw-bold">KSh <?php echo number_format($today_revenue['art_sales_revenue'] ?? 0, 0); ?></small>
                </div>
            </div>
            
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stat-card-modern">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo $today_revenue['event_transactions'] ?? 0; ?></div>
                    <div class="stat-label">Event Bookings</div>
                    <small class="text-success fw-bold">KSh <?php echo number_format($today_revenue['event_revenue'] ?? 0, 0); ?></small>
                </div>
            </div>
            
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stat-card-modern">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $gallery_stats['today_visitors'] ?? 0; ?></div>
                    <div class="stat-label">Today's Visitors</div>
                    <small class="text-info fw-bold"><?php echo number_format($checkin_rate, 0); ?>% checked in</small>
                </div>
            </div>
            
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stat-card-modern">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333;">
                        <i class="fas fa-images"></i>
                    </div>
                    <div class="stat-value"><?php echo $art_stats['available_artworks'] ?? 0; ?></div>
                    <div class="stat-label">Available Artworks</div>
                    <small class="text-warning fw-bold"><?php echo $art_stats['active_consignments'] ?? 0; ?> consignments</small>
                </div>
            </div>
            
            <!-- Pending Uploads Card -->
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stat-card-modern">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%); color: white;">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo $pending_uploads; ?></div>
                    <div class="stat-label">Pending Uploads</div>
                    <small class="text-warning fw-bold">Awaiting review</small>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="chart-card">
            <div class="chart-header">
                <h3 class="chart-title"><i class="fas fa-rocket me-2"></i>Quick Actions</h3>
            </div>
            <div class="quick-action-grid">
                <!-- Art Management Quick Actions -->
                <a href="artists.php" class="quick-action-btn-modern">
                    <i class="fas fa-users"></i>
                    <span>Manage Artists</span>
                </a>
                <a href="artworks.php" class="quick-action-btn-modern">
                    <i class="fas fa-palette"></i>
                    <span>Manage Artworks</span>
                </a>
                
                <!-- Review Uploads Button with Badge -->
                <a href="admin_manage_uploads.php" class="quick-action-btn-modern">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span>Review Uploads</span>
                    <?php if ($pending_uploads > 0): ?>
                        <span class="badge bg-danger mt-1"><?php echo $pending_uploads; ?> pending</span>
                    <?php endif; ?>
                </a>
                
                <a href="art_sales.php" class="quick-action-btn-modern">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Record Sale</span>
                </a>
                
                <!-- Events & Visitors Quick Actions -->
                <a href="events_management.php" class="quick-action-btn-modern">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Manage Events</span>
                </a>
                <a href="admin_attendees.php" class="quick-action-btn-modern">
                    <i class="fas fa-users"></i>
                    <span>View Attendees</span>
                </a>
                
                <!-- Finance Quick Actions -->
                <a href="admin_payments.php" class="quick-action-btn-modern">
                    <i class="fas fa-credit-card"></i>
                    <span>Payments</span>
                </a>
                <a href="reconciliation.php" class="quick-action-btn-modern">
                    <i class="fas fa-balance-scale"></i>
                    <span>Reconciliation</span>
                </a>
                <a href="admin_reports.php" class="quick-action-btn-modern">
                    <i class="fas fa-chart-pie"></i>
                    <span>Reports</span>
                </a>
                <a href="pay_artist.php" class="quick-action-btn-modern">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Artist Payments</span>
                </a>
            </div>
        </div>

        <!-- Rejection Statistics Section -->
        <?php if (!empty($rejection_stats) && $total_rejections > 0): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">
                            <i class="fas fa-times-circle text-danger me-2"></i>
                            Rejection Analysis
                        </h3>
                        <span class="badge bg-danger"><?php echo $total_rejections; ?> total rejections</span>
                    </div>
                    <div class="row">
                        <?php foreach ($rejection_stats as $stat): 
                            $percentage = $total_rejections > 0 ? round(($stat['count'] / $total_rejections) * 100, 1) : 0;
                        ?>
                        <div class="col-md-4 mb-3">
                            <div class="rejection-stat">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong><?php echo $rejection_reasons_display[$stat['rejection_reason']] ?? $stat['rejection_reason']; ?></strong>
                                    <span class="rejection-count"><?php echo $stat['count']; ?></span>
                                </div>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div class="progress-bar bg-warning" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                                <small class="rejection-reason"><?php echo $percentage; ?>% of rejections</small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-center mt-3">
                        <a href="admin_manage_uploads.php?filter=rejected" class="btn btn-sm btn-outline-warning">
                            <i class="fas fa-search me-1"></i>View Rejected Submissions
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row mt-4">
            <!-- Revenue Chart -->
            <div class="col-lg-8 mb-4">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-chart-area me-2"></i>Revenue Trend (7 Days)</h3>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary active" onclick="updateChart('all')">All</button>
                            <button type="button" class="btn btn-outline-primary" onclick="updateChart('entrance')">Entrance</button>
                            <button type="button" class="btn btn-outline-primary" onclick="updateChart('art')">Art Sales</button>
                            <button type="button" class="btn btn-outline-primary" onclick="updateChart('events')">Events</button>
                        </div>
                    </div>
                    <div style="height: 300px;">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <!-- Monthly Revenue Table -->
                <div class="table-modern mt-4">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th colspan="5">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-chart-pie me-2"></i>
                                        Monthly Revenue Breakdown (<?php echo date('F Y'); ?>)
                                    </div>
                                </th>
                            </tr>
                            <tr>
                                <th>Payment Type</th>
                                <th class="text-center">Transactions</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-center">Share</th>
                                <th class="text-end">Avg. Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($monthly_revenue)): 
                                foreach ($monthly_revenue as $revenue): 
                                    $percentage = $monthly_total > 0 ? ($revenue['total_amount'] / $monthly_total) * 100 : 0;
                                    $avg_transaction = $revenue['transaction_count'] > 0 ? $revenue['total_amount'] / $revenue['transaction_count'] : 0;
                            ?>
                            <tr>
                                <td>
                                    <span class="badge-modern bg-primary">
                                        <?php echo ucfirst(str_replace('_', ' ', $revenue['payment_type'])); ?>
                                    </span>
                                </td>
                                <td class="text-center fw-bold"><?php echo $revenue['transaction_count']; ?></td>
                                <td class="text-end fw-bold text-success">KSh <?php echo number_format($revenue['total_amount'], 0); ?></td>
                                <td>
                                    <div class="d-flex align-items-center justify-content-center">
                                        <div class="progress-modern" style="width: 80px; margin-right: 10px;">
                                            <div class="progress-bar bg-primary" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                        <span class="text-muted small"><?php echo number_format($percentage, 1); ?>%</span>
                                    </div>
                                </td>
                                <td class="text-end text-muted">KSh <?php echo number_format($avg_transaction, 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background: #f8f9fd;">
                                <td class="fw-bold">TOTAL</td>
                                <td class="text-center fw-bold"><?php echo array_sum(array_column($monthly_revenue, 'transaction_count')); ?></td>
                                <td class="text-end fw-bold text-success">KSh <?php echo number_format($monthly_total, 0); ?></td>
                                <td colspan="2" class="text-center fw-bold">100%</td>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="fas fa-chart-pie fa-3x mb-3 d-block"></i>
                                    No revenue data for this month
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Recent Payments -->
                <div class="activity-feed mb-4">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-history me-2"></i>Recent Payments</h3>
                        <a href="admin_payments.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <?php if (!empty($recent_payments)): 
                        foreach ($recent_payments as $payment): 
                            $type_colors = [
                                'entrance' => 'background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);',
                                'sale' => 'background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);',
                                'art_sale' => 'background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);',
                                'art_purchase' => 'background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);',
                                'event_booking' => 'background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);'
                            ];
                            $color = $type_colors[$payment['payment_type']] ?? 'background: #e9ecef;';
                    ?>
                    <div class="activity-item">
                        <div class="activity-icon" style="<?php echo $color; ?> color: white;">
                            <i class="fas fa-<?php 
                                echo $payment['payment_type'] == 'entrance' ? 'ticket-alt' : 
                                    (in_array($payment['payment_type'], ['sale', 'art_sale', 'art_purchase']) ? 'palette' : 'calendar-alt'); 
                            ?>"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title"><?php echo htmlspecialchars($payment['customer_name'] ?? 'Guest'); ?></div>
                            <div class="activity-meta">
                                <?php echo $payment['display_type']; ?>
                                <span class="badge-modern bg-<?php echo $payment['payment_method']; ?> ms-2">
                                    <?php echo strtoupper($payment['payment_method']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="activity-amount">KSh <?php echo number_format($payment['amount'], 0); ?></div>
                            <small class="text-muted"><?php echo timeAgo($payment['created_at']); ?></small>
                        </div>
                    </div>
                    <?php endforeach; 
                    else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-receipt fa-3x mb-3 d-block"></i>
                        No recent payments
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Attendees -->
                <div class="activity-feed mb-4">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-users me-2"></i>Recent Attendees</h3>
                        <a href="admin_attendees.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <?php if (!empty($recent_attendees)): 
                        foreach ($recent_attendees as $attendee): 
                    ?>
                    <div class="activity-item">
                        <div class="activity-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white;">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title"><?php echo htmlspecialchars($attendee['customer_name'] ?? 'Guest'); ?></div>
                            <div class="activity-meta">
                                <?php echo htmlspecialchars($attendee['event_title'] ?? 'Event Booking'); ?>
                                <?php if ($attendee['checked_in'] == 1): ?>
                                    <span class="badge bg-success ms-2">Checked In</span>
                                <?php else: ?>
                                    <span class="badge bg-warning ms-2">Booked</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="activity-amount">KSh <?php echo number_format($attendee['amount_paid'] ?? 0, 0); ?></div>
                            <small class="text-muted"><?php echo timeAgo($attendee['created_at']); ?></small>
                        </div>
                    </div>
                    <?php endforeach; 
                    else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-users fa-3x mb-3 d-block"></i>
                        No recent attendees
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Alerts -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-bell me-2"></i>Alerts</h3>
                    </div>
                    
                    <!-- Pending Uploads Alert -->
                    <?php if ($pending_uploads > 0): ?>
                    <div class="alert-modern alert-warning border-warning d-flex align-items-start">
                        <div class="alert-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div class="alert-content">
                            <div class="alert-title"><?php echo $pending_uploads; ?> Pending Artist Uploads</div>
                            <div class="alert-text">New artwork submissions awaiting your review</div>
                            <a href="admin_manage_uploads.php" class="btn btn-sm btn-warning">Review Now</a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($pending_artist_payments['count'] > 0): ?>
                    <div class="alert-modern alert-warning border-warning d-flex align-items-start">
                        <div class="alert-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="alert-content">
                            <div class="alert-title"><?php echo $pending_artist_payments['count']; ?> Pending Artist Payments</div>
                            <div class="alert-text">KSh <?php echo number_format($pending_artist_payments['total_amount'], 0); ?> requires processing</div>
                            <a href="pay_artist.php" class="btn btn-sm btn-success">Pay Artists</a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (($today_visitors - $today_checked_in) > 0): ?>
                    <div class="alert-modern alert-info border-info d-flex align-items-start">
                        <div class="alert-icon">
                            <i class="fas fa-door-open"></i>
                        </div>
                        <div class="alert-content">
                            <div class="alert-title"><?php echo $today_visitors - $today_checked_in; ?> Pending Check-ins</div>
                            <div class="alert-text"><?php echo number_format($checkin_rate, 0); ?>% check-in rate</div>
                            <a href="admin_attendees.php?status=booked" class="btn btn-sm btn-info">Check-in Now</a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (($art_stats['available_artworks'] ?? 0) < 10): ?>
                    <div class="alert-modern alert-primary border-primary d-flex align-items-start">
                        <div class="alert-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="alert-content">
                            <div class="alert-title">Low Artwork Inventory</div>
                            <div class="alert-text">Only <?php echo $art_stats['available_artworks'] ?? 0; ?> artworks available</div>
                            <a href="artworks.php" class="btn btn-sm btn-primary">Add Artwork</a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($pending_artist_payments['count']) && ($today_visitors == $today_checked_in) && ($art_stats['available_artworks'] ?? 0) >= 10 && $pending_uploads == 0): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-check-circle fa-3x mb-3 d-block text-success"></i>
                        All systems running smoothly!
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle for mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }

        // Chart Data
        const revenueTrend = <?php echo json_encode($revenue_trend); ?>;
        const dates = revenueTrend.map(item => {
            const date = new Date(item.date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        
        const totalRevenue = revenueTrend.map(item => item.revenue);
        const entranceRevenue = revenueTrend.map(item => item.entrance_revenue);
        const artRevenue = revenueTrend.map(item => item.art_revenue);
        const eventRevenue = revenueTrend.map(item => item.event_revenue);
        
        // Revenue Chart
        const ctx = document.getElementById('revenueChart').getContext('2d');
        let revenueChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [
                    {
                        label: 'Total Revenue',
                        data: totalRevenue,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#667eea',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    },
                    {
                        label: 'Entrance',
                        data: entranceRevenue,
                        borderColor: '#fa709a',
                        backgroundColor: 'rgba(250, 112, 154, 0.1)',
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4,
                        hidden: true
                    },
                    {
                        label: 'Art Sales',
                        data: artRevenue,
                        borderColor: '#4facfe',
                        backgroundColor: 'rgba(79, 172, 254, 0.1)',
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4,
                        hidden: true
                    },
                    {
                        label: 'Events',
                        data: eventRevenue,
                        borderColor: '#f093fb',
                        backgroundColor: 'rgba(240, 147, 251, 0.1)',
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4,
                        hidden: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': KSh ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            callback: function(value) {
                                return 'KSh ' + (value / 1000) + 'k';
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        
        function updateChart(type) {
            const buttons = document.querySelectorAll('.btn-group button');
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            revenueChart.data.datasets.forEach((dataset, index) => {
                if (type === 'all') {
                    dataset.hidden = index !== 0;
                } else if (type === 'entrance') {
                    dataset.hidden = index !== 1;
                } else if (type === 'art') {
                    dataset.hidden = index !== 2;
                } else if (type === 'events') {
                    dataset.hidden = index !== 3;
                }
            });
            revenueChart.update();
        }
    </script>
</body>
</html>