<?php
session_start();
require_once 'config.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header("Location: login.php");
    exit();
}

// Helper function to get artwork image
function getArtworkImage($artwork) {
    // First check image_path (this is where upload_artwork.php saves)
    if (!empty($artwork['image_path']) && file_exists($artwork['image_path'])) {
        return $artwork['image_path'];
    }
    
    // Then check featured_image
    if (!empty($artwork['featured_image']) && file_exists($artwork['featured_image'])) {
        return $artwork['featured_image'];
    }
    
    // Then check image_url
    if (!empty($artwork['image_url']) && file_exists($artwork['image_url'])) {
        return $artwork['image_url'];
    }
    
    // Try to find in uploads folder based on ID
    $possible_paths = [
        'uploads/artworks/artwork_' . $artwork['id'] . '.jpg',
        'uploads/artworks/artwork_' . $artwork['id'] . '.png',
        'uploads/artworks/' . $artwork['id'] . '.jpg',
        'uploads/artworks/' . $artwork['id'] . '.png'
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    return null;
}

$error = '';
$success = '';

try {
    // Handle approval/rejection
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['approve'])) {
            $artwork_id = $_POST['artwork_id'];
            
            // Update artwork status to available
            $stmt = $db->prepare("UPDATE artworks SET status = 'available', reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$artwork_id]);
            
            // Get artwork and artist details for notification
            $info_stmt = $db->prepare("
                SELECT a.*, u.email, u.first_name, u.last_name 
                FROM artworks a
                JOIN users u ON a.artist_id = u.id
                WHERE a.id = ?
            ");
            $info_stmt->execute([$artwork_id]);
            $artwork = $info_stmt->fetch();
            
            // Send approval email if email function exists
            if (function_exists('sendApprovalEmail') && !empty($artwork['email'])) {
                sendApprovalEmail($artwork['email'], $artwork['first_name'] . ' ' . $artwork['last_name'], $artwork['title']);
            }
            
            $success = "Artwork approved successfully!";
            
        } elseif (isset($_POST['reject'])) {
            $artwork_id = $_POST['artwork_id'];
            $rejection_reason = $_POST['rejection_reason'] ?? '';
            $feedback = $_POST['feedback'] ?? '';
            
            // Update artwork status to rejected
            $stmt = $db->prepare("
                UPDATE artworks 
                SET status = 'rejected', 
                    rejection_reason = ?,
                    admin_notes = ?,
                    reviewed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$rejection_reason, $feedback, $artwork_id]);
            
            // Get artwork and artist details for notification
            $info_stmt = $db->prepare("
                SELECT a.*, u.email, u.first_name, u.last_name 
                FROM artworks a
                JOIN users u ON a.artist_id = u.id
                WHERE a.id = ?
            ");
            $info_stmt->execute([$artwork_id]);
            $artwork = $info_stmt->fetch();
            
            // Send rejection email if email function exists
            if (function_exists('sendRejectionEmail') && !empty($artwork['email'])) {
                sendRejectionEmail($artwork['email'], $artwork['first_name'] . ' ' . $artwork['last_name'], $artwork['title'], $rejection_reason, $feedback);
            }
            
            $success = "Artwork rejected. Artist has been notified.";
        }
    }
    
    // Get pending artworks
    $pending_query = "
        SELECT 
            a.*,
            u.first_name as artist_first_name,
            u.last_name as artist_last_name,
            u.email as artist_email,
            u.phone as artist_phone
        FROM artworks a
        JOIN users u ON a.artist_id = u.id
        WHERE a.status = 'pending'
        ORDER BY a.created_at DESC
    ";
    
    $pending_stmt = $db->prepare($pending_query);
    $pending_stmt->execute();
    $pending_artworks = $pending_stmt->fetchAll();
    
    // Get approved artworks (last 10)
    $approved_query = "
        SELECT 
            a.*,
            u.first_name as artist_first_name,
            u.last_name as artist_last_name
        FROM artworks a
        JOIN users u ON a.artist_id = u.id
        WHERE a.status = 'available'
        ORDER BY a.reviewed_at DESC
        LIMIT 10
    ";
    
    $approved_stmt = $db->prepare($approved_query);
    $approved_stmt->execute();
    $approved_artworks = $approved_stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Approval error: " . $e->getMessage());
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Artwork Approvals - Ardhi Gallery Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }
        
        .page-header {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 30px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .pending-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: all 0.3s ease;
            border-left: 4px solid #ffc107;
        }
        
        .pending-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .artwork-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 10px;
        }
        
        .image-placeholder {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            font-size: 3rem;
        }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .btn-approve {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-approve:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-reject {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-reject:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }
        
        .count-badge {
            background: #ffc107;
            color: #000;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .artist-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 0.75rem;
            font-size: 0.9rem;
        }
        
        /* Image error fallback */
        .image-error-fallback {
            display: none;
        }
    </style>
</head>
<body>
    <?php include 'admin_navigation.php'; ?>
    
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="fw-bold mb-2" style="color: #2c3e50;">
                        <i class="fas fa-check-double me-3" style="color: #667eea;"></i>Artwork Approvals
                    </h1>
                    <p class="text-muted mb-0">Review and approve artwork submissions from artists</p>
                </div>
                <div class="count-badge">
                    <i class="fas fa-clock me-1"></i> <?php echo count($pending_artworks); ?> Pending
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Pending Approvals -->
        <div class="glass-card">
            <h4 class="mb-4">
                <i class="fas fa-clock me-2 text-warning"></i>
                Pending Approvals
                <span class="badge bg-warning ms-2"><?php echo count($pending_artworks); ?></span>
            </h4>
            
            <?php if (empty($pending_artworks)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                    <h5 class="text-muted">No pending approvals</h5>
                    <p class="text-muted">All caught up! No artworks waiting for review.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pending_artworks as $artwork): 
                    $image_url = getArtworkImage($artwork);
                ?>
                <div class="pending-card">
                    <div class="row g-0">
                        <div class="col-md-4">
                            <?php if ($image_url): ?>
                                <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                     class="artwork-image" 
                                     alt="<?php echo htmlspecialchars($artwork['title']); ?>"
                                     onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML += '<div class=\'image-placeholder\'><i class=\'fas fa-image\'></i></div>';">
                            <?php else: ?>
                                <div class="image-placeholder">
                                    <i class="fas fa-image"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-8">
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($artwork['title']); ?></h4>
                                        <p class="text-muted mb-2">
                                            <i class="fas fa-user me-2"></i> by <?php echo htmlspecialchars($artwork['artist_first_name'] . ' ' . $artwork['artist_last_name']); ?>
                                        </p>
                                    </div>
                                    <span class="status-badge status-pending">
                                        <i class="fas fa-clock me-1"></i> Pending Review
                                    </span>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Price</small>
                                        <strong class="text-success">KSh <?php echo number_format($artwork['price'], 0); ?></strong>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Submitted</small>
                                        <strong><?php echo date('M j, Y \a\t g:i A', strtotime($artwork['created_at'])); ?></strong>
                                    </div>
                                </div>
                                
                                <div class="artist-info mb-3">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <small class="text-muted d-block">Artist Email</small>
                                            <span><?php echo htmlspecialchars($artwork['artist_email']); ?></span>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted d-block">Artist Phone</small>
                                            <span><?php echo htmlspecialchars($artwork['artist_phone'] ?? 'Not provided'); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($artwork['description'])): ?>
                                <div class="mb-3">
                                    <small class="text-muted d-block">Description</small>
                                    <p><?php echo nl2br(htmlspecialchars($artwork['description'])); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Medium</small>
                                        <span><?php echo htmlspecialchars($artwork['medium'] ?? 'Not specified'); ?></span>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Dimensions</small>
                                        <span><?php echo htmlspecialchars($artwork['dimensions'] ?? 'Not specified'); ?></span>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <!-- Action Buttons -->
                                <div class="d-flex gap-2 mt-3">
                                    <button class="btn-approve" onclick="approveArtwork(<?php echo $artwork['id']; ?>, '<?php echo htmlspecialchars($artwork['title']); ?>')">
                                        <i class="fas fa-check-circle me-2"></i>Approve
                                    </button>
                                    <button class="btn-reject" onclick="showRejectModal(<?php echo $artwork['id']; ?>, '<?php echo htmlspecialchars($artwork['title']); ?>')">
                                        <i class="fas fa-times-circle me-2"></i>Reject
                                    </button>
                                    <a href="view_artwork.php?id=<?php echo $artwork['id']; ?>" class="btn btn-outline-secondary ms-auto">
                                        <i class="fas fa-external-link-alt me-2"></i>View Full Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Recently Approved -->
        <?php if (!empty($approved_artworks)): ?>
        <div class="glass-card">
            <h4 class="mb-4">
                <i class="fas fa-check-circle me-2 text-success"></i>
                Recently Approved
            </h4>
            
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Artwork</th>
                            <th>Artist</th>
                            <th>Approved Date</th>
                            <th>Price</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approved_artworks as $art): 
                            $approved_image = getArtworkImage($art);
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if ($approved_image): ?>
                                        <img src="<?php echo htmlspecialchars($approved_image); ?>" 
                                             style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; margin-right: 10px;"
                                             onerror="this.onerror=null; this.style.display='none';">
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($art['title']); ?></strong>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($art['artist_first_name'] . ' ' . $art['artist_last_name']); ?></td>
                            <td><?php echo date('M j, Y', strtotime($art['reviewed_at'])); ?></td>
                            <td class="text-success fw-bold">KSh <?php echo number_format($art['price'], 0); ?></td>
                            <td><span class="status-badge status-approved">Approved</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Artwork</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="artwork_id" id="reject_artwork_id">
                        
                        <p>You are about to reject: <strong id="reject_artwork_title"></strong></p>
                        
                        <div class="mb-3">
                            <label class="form-label">Rejection Reason *</label>
                            <select name="rejection_reason" class="form-control" required>
                                <option value="">Select a reason</option>
                                <option value="image_quality">Image Quality Issues</option>
                                <option value="image_format">Wrong Image Format</option>
                                <option value="image_count">Insufficient Images</option>
                                <option value="missing_info">Missing Artwork Information</option>
                                <option value="copyright">Copyright Concerns</option>
                                <option value="gallery_fit">Does Not Match Gallery Theme</option>
                                <option value="pricing">Pricing Issues</option>
                                <option value="inappropriate">Inappropriate Content</option>
                                <option value="duplicate">Duplicate Submission</option>
                                <option value="other">Other Reason</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Feedback to Artist</label>
                            <textarea name="feedback" class="form-control" rows="4" 
                                      placeholder="Provide specific feedback to help the artist improve their submission..."></textarea>
                            <small class="text-muted">This will be included in the rejection email.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reject" class="btn btn-danger">
                            <i class="fas fa-times-circle me-2"></i>Reject Artwork
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Approve Modal -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Approve Artwork</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="artwork_id" id="approve_artwork_id">
                        
                        <p>You are about to approve: <strong id="approve_artwork_title"></strong></p>
                        
                        <p>This artwork will be published to the gallery and visible to all visitors.</p>
                        
                        <div class="alert alert-success">
                            <i class="fas fa-info-circle me-2"></i>
                            The artist will receive an email notification about this approval.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="approve" class="btn btn-success">
                            <i class="fas fa-check-circle me-2"></i>Approve Artwork
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showRejectModal(id, title) {
            document.getElementById('reject_artwork_id').value = id;
            document.getElementById('reject_artwork_title').textContent = title;
            new bootstrap.Modal(document.getElementById('rejectModal')).show();
        }
        
        function approveArtwork(id, title) {
            document.getElementById('approve_artwork_id').value = id;
            document.getElementById('approve_artwork_title').textContent = title;
            new bootstrap.Modal(document.getElementById('approveModal')).show();
        }
    </script>
</body>
</html>