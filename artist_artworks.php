<?php
session_start();
require_once 'config.php';

// Get artist ID from URL
$artist_id = $_GET['id'] ?? 0;

// Redirect if no ID
if (!$artist_id) {
    header("Location: artists.php");
    exit();
}

// Get artist details
try {
    $stmt = $db->prepare("SELECT * FROM artists WHERE id = ?");
    $stmt->execute([$artist_id]);
    $artist = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$artist) {
        $_SESSION['error'] = "Artist not found!";
        header("Location: artists.php");
        exit();
    }
} catch (PDOException $e) {
    die("Error loading artist: " . $e->getMessage());
}

// Get artworks for this artist - SIMPLE QUERY
try {
    $stmt = $db->prepare("
        SELECT * 
        FROM artworks 
        WHERE artist_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$artist_id]);
    $artworks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error loading artworks: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($artist['first_name'] . ' ' . $artist['last_name']); ?> - Portfolio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .artwork-card {
            transition: transform 0.2s;
            height: 100%;
        }
        .artwork-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .artwork-img {
            height: 200px;
            object-fit: cover;
            background-color: #f8f9fa;
        }
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            background-color: #f8f9fa;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <!-- Simple Navigation -->
    <nav class="navbar navbar-light bg-light">
        <div class="container">
            <a class="navbar-brand" href="artists.php">
                <i class="fas fa-arrow-left"></i> Back to Artists
            </a>
            <span class="navbar-text">
                <a href="add_artwork.php?artist_id=<?php echo $artist_id; ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-plus"></i> Add Artwork
                </a>
            </span>
        </div>
    </nav>

    <div class="container mt-4 mb-5">
        <!-- Artist Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="d-flex align-items-center mb-3">
                    <?php if (!empty($artist['image_path'])): ?>
                        <img src="<?php echo htmlspecialchars($artist['image_path']); ?>" 
                             class="rounded-circle me-3" 
                             style="width: 80px; height: 80px; object-fit: cover;"
                             alt="<?php echo htmlspecialchars($artist['first_name'] . ' ' . $artist['last_name']); ?>">
                    <?php else: ?>
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-3"
                             style="width: 80px; height: 80px;">
                            <i class="fas fa-user fa-2x"></i>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h1 class="mb-1"><?php echo htmlspecialchars($artist['first_name'] . ' ' . $artist['last_name']); ?></h1>
                        <p class="text-muted mb-0">
                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($artist['email']); ?>
                            <?php if (!empty($artist['phone'])): ?>
                                | <i class="fas fa-phone"></i> <?php echo htmlspecialchars($artist['phone']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <!-- Stats -->
                <div class="d-flex gap-3 mb-3">
                    <span class="badge bg-primary">
                        <i class="fas fa-palette"></i> <?php echo count($artworks); ?> Artworks
                    </span>
                    <span class="badge <?php echo ($artist['is_active'] == 1) ? 'bg-success' : 'bg-secondary'; ?>">
                        <i class="fas fa-circle"></i> <?php echo ($artist['is_active'] == 1) ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
                
                <!-- Bio -->
                <?php if (!empty($artist['bio'])): ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <h5 class="card-title">About the Artist</h5>
                            <p class="card-text"><?php echo nl2br(htmlspecialchars($artist['bio'])); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Artworks Section -->
        <h2 class="mb-4">Artwork Portfolio</h2>
        
        <?php if (empty($artworks)): ?>
            <div class="empty-state">
                <i class="fas fa-palette fa-4x text-muted mb-3"></i>
                <h3 class="text-muted">No Artworks Yet</h3>
                <p class="text-muted">This artist hasn't added any artworks to their portfolio yet.</p>
                <div class="mt-4">
                    <a href="add_artwork.php?artist_id=<?php echo $artist_id; ?>" class="btn btn-primary btn-lg">
                        <i class="fas fa-plus me-2"></i>Add First Artwork
                    </a>
                    <a href="debug_portfolio.php?id=<?php echo $artist_id; ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="fas fa-bug me-2"></i>Debug This Issue
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($artworks as $artwork): ?>
                <div class="col-md-4 mb-4">
                    <div class="card artwork-card h-100">
                        <!-- Artwork Image -->
                        <?php 
                        $image_url = '';
                        if (!empty(($artwork['image_url'] ?? null))) {
                            $image_url = ($artwork['image_url'] ?? null);
                        } elseif (!empty(($artwork['image_path'] ?? null))) {
                            $image_url = ($artwork['image_path'] ?? null);
                        } elseif (!empty(($artwork['image_filename'] ?? null))) {
                            $image_url = 'uploads/artworks/' . ($artwork['image_filename'] ?? null);
                        }
                        ?>
                        
                        <a href="artwork_details.php?id=<?php echo ($artwork['id'] ?? null); ?>">
                            <?php if (!empty($image_url) && file_exists($image_url)): ?>
                                <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                     class="card-img-top artwork-img" 
                                     alt="<?php echo htmlspecialchars(($artwork['title'] ?? null)); ?>">
                            <?php else: ?>
                                <div class="card-img-top artwork-img d-flex align-items-center justify-content-center">
                                    <i class="fas fa-image fa-3x text-muted"></i>
                                </div>
                            <?php endif; ?>
                        </a>
                        
                        <div class="card-body">
                            <h5 class="card-title">
                                <a href="artwork_details.php?id=<?php echo ($artwork['id'] ?? null); ?>" class="text-decoration-none text-dark">
                                    <?php echo htmlspecialchars(($artwork['title'] ?? null)); ?>
                                </a>
                            </h5>
                            
                            <!-- Artwork Info -->
                            <div class="mb-2">
                                <?php if (!empty(($artwork['category'] ?? null))): ?>
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars(($artwork['category'] ?? null)); ?>
                                    </span>
                                <?php endif; ?>
                                
                                <span class="badge 
                                    <?php 
                                    if (($artwork['status'] ?? null) == 'available') echo 'bg-success';
                                    elseif (($artwork['status'] ?? null) == 'sold') echo 'bg-danger';
                                    else echo 'bg-warning text-dark';
                                    ?>">
                                    <?php echo ucfirst(($artwork['status'] ?? null)); ?>
                                </span>
                            </div>
                            
                            <?php if (!empty(($artwork['price'] ?? null))): ?>
                                <p class="text-success fw-bold h5 mb-3">
                                    Ksh <?php echo number_format(($artwork['price'] ?? null), 2); ?>
                                </p>
                            <?php endif; ?>
                            
                            <!-- Quick Actions -->
                            <div class="d-grid gap-2">
                                <a href="artwork_details.php?id=<?php echo ($artwork['id'] ?? null); ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-eye me-1"></i> View Details
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Summary -->
            <div class="alert alert-info mt-4">
                <h5><i class="fas fa-chart-bar me-2"></i>Portfolio Summary</h5>
                <p class="mb-0">
                    Showing <?php echo count($artworks); ?> artwork(s) by <?php echo htmlspecialchars($artist['first_name'] . ' ' . $artist['last_name']); ?>
                </p>
            </div>
        <?php endif; ?>
        
        <!-- Debug Section -->
        <div class="card mt-4">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-bug me-2"></i>Debug Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Artist ID:</strong> <?php echo $artist_id; ?></p>
                <p><strong>Artworks Found:</strong> <?php echo count($artworks); ?></p>
                <p><strong>Artist Name:</strong> <?php echo htmlspecialchars($artist['first_name'] . ' ' . $artist['last_name']); ?></p>
                <p><strong>Artist Email:</strong> <?php echo htmlspecialchars($artist['email']); ?></p>
                <div class="mt-3">
                    <a href="debug_portfolio.php?id=<?php echo $artist_id; ?>" class="btn btn-sm btn-warning">
                        <i class="fas fa-bug me-1"></i>Run Detailed Debug
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>