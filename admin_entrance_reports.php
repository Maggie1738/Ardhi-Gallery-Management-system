<?php
// admin_entrance_reports.php
session_start();
require_once 'config.php';

if ($_SESSION['user_role'] != 'admin') {
    header("Location: unauthorized.php");
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'daily';

// Get entrance statistics
$stats_stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_entrances,
        SUM(quantity) as total_tickets,
        SUM(total_amount) as total_revenue,
        AVG(total_amount) as avg_ticket_value,
        MIN(visit_date) as first_date,
        MAX(visit_date) as last_date
    FROM entrance_payments 
    WHERE payment_status = 'paid'
    AND visit_date BETWEEN ? AND ?
");
$stats_stmt->execute([$start_date, $end_date]);
$entrance_stats = $stats_stmt->fetch();

// Get daily revenue
$daily_stmt = $db->prepare("
    SELECT 
        DATE(visit_date) as date,
        COUNT(*) as visitors,
        SUM(quantity) as tickets,
        SUM(total_amount) as revenue,
        GROUP_CONCAT(DISTINCT ticket_type) as ticket_types
    FROM entrance_payments 
    WHERE payment_status = 'paid'
    AND visit_date BETWEEN ? AND ?
    GROUP BY DATE(visit_date)
    ORDER BY date DESC
");
$daily_stmt->execute([$start_date, $end_date]);
$daily_reports = $daily_stmt->fetchAll();

// Get ticket type breakdown
$ticket_stmt = $db->prepare("
    SELECT 
        ticket_type,
        COUNT(*) as count,
        SUM(quantity) as total_tickets,
        SUM(total_amount) as revenue,
        AVG(unit_price) as avg_price
    FROM entrance_payments 
    WHERE payment_status = 'paid'
    AND visit_date BETWEEN ? AND ?
    GROUP BY ticket_type
    ORDER BY revenue DESC
");
$ticket_stmt->execute([$start_date, $end_date]);
$ticket_breakdown = $ticket_stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Entrance Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container-fluid mt-4">
        <h2>Gallery Entrance Reports</h2>
        
        <!-- Date Filter -->
        <form method="GET" class="row g-3 mb-4">
            <div class="col-md-3">
                <label>Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
            </div>
            <div class="col-md-3">
                <label>End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
            </div>
            <div class="col-md-3">
                <label>Report Type</label>
                <select name="report_type" class="form-select">
                    <option value="daily" <?php echo $report_type == 'daily' ? 'selected' : ''; ?>>Daily Summary</option>
                    <option value="detailed" <?php echo $report_type == 'detailed' ? 'selected' : ''; ?>>Detailed Report</option>
                </select>
            </div>
            <div class="col-md-3">
                <label>&nbsp;</label><br>
                <button type="submit" class="btn btn-primary">Generate Report</button>
                <button type="button" onclick="window.print()" class="btn btn-secondary">Print Report</button>
            </div>
        </form>
        
        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h3><?php echo number_format($entrance_stats['total_revenue'] ?? 0, 2); ?></h3>
                        <p class="mb-0">Total Revenue (KSh)</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h3><?php echo $entrance_stats['total_entrances'] ?? 0; ?></h3>
                        <p class="mb-0">Total Entrances</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h3><?php echo $entrance_stats['total_tickets'] ?? 0; ?></h3>
                        <p class="mb-0">Tickets Sold</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body text-center">
                        <h3><?php echo number_format($entrance_stats['avg_ticket_value'] ?? 0, 2); ?></h3>
                        <p class="mb-0">Avg. Ticket Value</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Chart -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5>Entrance Revenue Chart</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueChart" height="100"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Daily Report -->
        <div class="card">
            <div class="card-header">
                <h5>Daily Entrance Report (<?php echo $start_date; ?> to <?php echo $end_date; ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Visitors</th>
                                <th>Tickets</th>
                                <th>Revenue</th>
                                <th>Avg. per Visitor</th>
                                <th>Ticket Types</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daily_reports as $day): ?>
                            <tr>
                                <td><?php echo date('D, M d, Y', strtotime($day['date'])); ?></td>
                                <td><?php echo $day['visitors']; ?></td>
                                <td><?php echo $day['tickets']; ?></td>
                                <td><strong>KSh <?php echo number_format($day['revenue'], 2); ?></strong></td>
                                <td>KSh <?php echo number_format($day['revenue'] / $day['visitors'], 2); ?></td>
                                <td>
                                    <?php 
                                    $types = explode(',', $day['ticket_types']);
                                    foreach ($types as $type):
                                    ?>
                                        <span class="badge bg-secondary"><?php echo $type; ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <a href="entrance_daily_detail.php?date=<?php echo $day['date']; ?>" 
                                       class="btn btn-sm btn-info">View Details</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <th>TOTAL</th>
                                <th><?php echo $entrance_stats['total_entrances']; ?></th>
                                <th><?php echo $entrance_stats['total_tickets']; ?></th>
                                <th>KSh <?php echo number_format($entrance_stats['total_revenue'], 2); ?></th>
                                <th>KSh <?php echo number_format($entrance_stats['avg_ticket_value'], 2); ?></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Ticket Breakdown -->
        <div class="card mt-4">
            <div class="card-header">
                <h5>Ticket Type Breakdown</h5>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ticket Type</th>
                            <th>Sales Count</th>
                            <th>Tickets Sold</th>
                            <th>Revenue</th>
                            <th>Avg. Price</th>
                            <th>% of Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ticket_breakdown as $ticket): 
                            $percentage = ($ticket['revenue'] / $entrance_stats['total_revenue']) * 100;
                        ?>
                        <tr>
                            <td><strong><?php echo ucfirst($ticket['ticket_type']); ?></strong></td>
                            <td><?php echo $ticket['count']; ?></td>
                            <td><?php echo $ticket['total_tickets']; ?></td>
                            <td>KSh <?php echo number_format($ticket['revenue'], 2); ?></td>
                            <td>KSh <?php echo number_format($ticket['avg_price'], 2); ?></td>
                            <td>
                                <div class="progress">
                                    <div class="progress-bar" role="progressbar" 
                                         style="width: <?php echo $percentage; ?>%">
                                        <?php echo number_format($percentage, 1); ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        // Revenue Chart
        const ctx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [<?php 
                    foreach ($daily_reports as $day) {
                        echo "'" . date('M d', strtotime($day['date'])) . "',";
                    }
                ?>],
                datasets: [{
                    label: 'Daily Revenue (KSh)',
                    data: [<?php 
                        foreach ($daily_reports as $day) {
                            echo $day['revenue'] . ",";
                        }
                    ?>],
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1,
                    fill: true,
                    backgroundColor: 'rgba(75, 192, 192, 0.1)'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KSh ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
