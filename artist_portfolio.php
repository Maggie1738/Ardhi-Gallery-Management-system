<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'artist') {
    header('Location: login.php');
    exit();
}

// Helper function to get artwork image URL
function getArtworkImageUrl($artwork) {
    // Check multiple possible image columns
    $image_path = '';
    
    if (!empty($artwork['image_path'])) {
        $image_path = $artwork['image_path'];
    } elseif (!empty($artwork['featured_image'])) {
        $image_path = $artwork['featured_image'];
    } elseif (!empty($artwork['image_url'])) {
        $image_path = $artwork['image_url'];
    }
    
    if (empty($image_path)) {
        return null;
    }
    
    // Check if file exists at the exact path
    if (file_exists($image_path)) {
        return $image_path;
    }
    
    // Check in uploads/artworks/ directory
    $uploads_path = 'uploads/artworks/' . basename($image_path);
    if (file_exists($uploads_path)) {
        return $uploads_path;
    }
    
    // Check in uploads/ directory
    $uploads_path2 = 'uploads/' . basename($image_path);
    if (file_exists($uploads_path2)) {
        return $uploads_path2;
    }
    
    // Check in assets/images/ directory
    $assets_path = 'assets/images/' . basename($image_path);
    if (file_exists($assets_path)) {
        return $assets_path;
    }
    
    return null;
}

$artist_id   = $_SESSION['user_id'];
$artist_name = $_SESSION['user_name'];

try {
    $artist_stmt = $db->prepare("SELECT first_name, last_name, email, bio, artist_statement, profile_image FROM artists WHERE id = ?");
    $artist_stmt->execute([$artist_id]);
    $artist = $artist_stmt->fetch();

    // Get artworks with all image columns
    $artworks_stmt = $db->prepare("SELECT id, title, price, status, image_path, featured_image, image_url, medium, year_created, description, created_at FROM artworks WHERE artist_id = ? ORDER BY created_at DESC");
    $artworks_stmt->execute([$artist_id]);
    $artworks = $artworks_stmt->fetchAll();
    
    // Debug: Log image paths
    error_log("Artist ID: $artist_id, Artworks found: " . count($artworks));
    foreach ($artworks as $art) {
        error_log("Artwork: {$art['title']} - image_path: {$art['image_path']} - featured_image: {$art['featured_image']}");
    }
    
} catch (PDOException $e) {
    $error    = "Database error: " . $e->getMessage();
    $artist   = [];
    $artworks = [];
}

// group counts
$total     = count($artworks);
$sold      = count(array_filter($artworks, fn($a) => ($a['status'] ?? '') === 'sold'));
$available = $total - $sold;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portfolio — Ardhi Gallery</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --ink:        #1a1410;
            --ink-mid:    #3d3028;
            --ink-soft:   #6b5d52;
            --cream:      #f7f3ee;
            --cream-2:    #ede7de;
            --cream-3:    #e2d9ce;
            --gold:       #c49a3c;
            --gold-light: #e8c06a;
            --terracotta: #b85c38;
            --sage:       #5a7a5e;
            --sage-light: #7fa885;
            --ff-display: 'Playfair Display', Georgia, serif;
            --ff-body:    'DM Sans', system-ui, sans-serif;
            --ease-expo:  cubic-bezier(0.16, 1, 0.3, 1);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background-color: var(--ink);
            background-image:
                radial-gradient(ellipse 120% 60% at 70% 10%, rgba(196,154,60,0.08) 0%, transparent 70%),
                radial-gradient(ellipse 80% 80% at 10% 90%, rgba(90,122,94,0.07) 0%, transparent 60%);
            font-family: var(--ff-body);
            color: var(--cream);
            min-height: 100vh;
            font-size: 15px;
            line-height: 1.6;
        }

        body::before {
            content: '';
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
            opacity: 0.4;
        }

        .studio {
            position: relative; z-index: 1;
            max-width: 1320px;
            margin: 0 auto;
            padding: 0 2rem 4rem;
        }

        .masthead {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            padding: 3rem 0 2.5rem;
            border-bottom: 1px solid rgba(196,154,60,0.2);
            gap: 2rem;
            flex-wrap: wrap;
        }

        .masthead-brand {
            font-family: var(--ff-display);
            font-size: 12px;
            letter-spacing: 0.35em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 0.75rem;
        }

        .masthead-title {
            font-family: var(--ff-display);
            font-size: clamp(2rem, 4vw, 3.2rem);
            font-weight: 400;
            line-height: 1.1;
            letter-spacing: -0.02em;
            color: var(--cream);
        }
        .masthead-title em { font-style: italic; color: var(--gold-light); }

        .masthead-sub {
            display: flex; align-items: center; gap: 1rem;
            margin-top: 0.6rem;
            color: var(--ink-soft);
            font-size: 13px;
            letter-spacing: 0.04em;
        }
        .masthead-sub::before {
            content: '';
            display: inline-block;
            width: 28px; height: 1px;
            background: var(--gold);
        }

        .masthead-actions { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }

        .btn-back {
            font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase;
            color: var(--ink-soft); text-decoration: none;
            display: flex; align-items: center; gap: 0.5rem;
            transition: color 0.2s;
        }
        .btn-back:hover { color: var(--cream-2); }

        .btn-add {
            font-family: var(--ff-body);
            font-size: 12px; font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase;
            color: var(--ink); background: var(--gold); border: none;
            padding: 0.6rem 1.4rem; border-radius: 2px; text-decoration: none;
            display: inline-flex; align-items: center; gap: 0.5rem;
            transition: background 0.2s, transform 0.15s var(--ease-expo);
        }
        .btn-add:hover { background: var(--gold-light); transform: translateY(-2px); }

        .btn-logout {
            font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase;
            color: var(--ink-soft); text-decoration: none;
            display: flex; align-items: center; gap: 0.4rem;
            transition: color 0.2s;
        }
        .btn-logout:hover { color: var(--terracotta); }

        .filter-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.5rem 0;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .count-pills { display: flex; gap: 0.5rem; flex-wrap: wrap; }

        .count-pill {
            font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase;
            padding: 0.3rem 0.9rem; border-radius: 2px; border: 1px solid transparent;
            cursor: pointer; transition: all 0.2s;
            background: transparent;
            color: var(--ink-soft);
            border-color: rgba(107,93,82,0.3);
        }
        .count-pill:hover       { border-color: rgba(196,154,60,0.4); color: var(--gold-light); }
        .count-pill.active      { border-color: var(--gold); color: var(--gold); background: rgba(196,154,60,0.08); }
        .count-pill .n          { font-family: var(--ff-display); font-size: 0.95rem; margin-right: 0.3rem; }

        .sort-select {
            font-family: var(--ff-body);
            font-size: 12px; letter-spacing: 0.06em;
            color: var(--ink-soft); background: transparent;
            border: 1px solid rgba(107,93,82,0.3);
            border-radius: 2px; padding: 0.35rem 2rem 0.35rem 0.75rem;
            appearance: none; -webkit-appearance: none; cursor: pointer; outline: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%236b5d52'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 0.6rem center;
            transition: border-color 0.2s;
        }
        .sort-select:focus { border-color: rgba(196,154,60,0.5); }
        .sort-select option { background: #2a2018; }

        .gallery-grid {
            columns: 3;
            column-gap: 1.25rem;
        }
        @media (max-width: 1100px) { .gallery-grid { columns: 2; } }
        @media (max-width: 600px)  { .gallery-grid { columns: 1; } }

        .gallery-item {
            break-inside: avoid;
            margin-bottom: 1.25rem;
            position: relative;
            overflow: hidden;
            border-radius: 2px;
            cursor: pointer;
            background: var(--ink-mid);
        }

        .gallery-item img {
            display: block;
            width: 100%;
            transition: transform 0.5s var(--ease-expo), opacity 0.3s;
        }
        .gallery-item:hover img { transform: scale(1.03); opacity: 0.88; }

        .gallery-placeholder {
            width: 100%;
            aspect-ratio: 4/3;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 0.5rem;
            color: var(--ink-soft);
            background: var(--ink-mid);
        }
        .gallery-placeholder i { font-size: 2.5rem; opacity: 0.25; }
        .gallery-placeholder span { font-size: 11px; opacity: 0.35; letter-spacing: 0.05em; }

        .gallery-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(26,20,16,0.96) 0%, rgba(26,20,16,0.2) 55%, transparent 100%);
            opacity: 0;
            transform: translateY(6px);
            transition: opacity 0.3s, transform 0.3s var(--ease-expo);
            display: flex; flex-direction: column; justify-content: flex-end;
            padding: 1.25rem;
        }
        .gallery-item:hover .gallery-overlay { opacity: 1; transform: translateY(0); }

        .overlay-title {
            font-family: var(--ff-display);
            font-style: italic;
            font-size: 1.05rem;
            color: var(--cream);
            margin-bottom: 0.35rem;
            line-height: 1.2;
        }

        .overlay-meta {
            display: flex; align-items: center; justify-content: space-between;
            gap: 0.75rem;
        }

        .overlay-price {
            font-family: var(--ff-display);
            font-size: 1rem;
            color: var(--gold);
        }

        .pill {
            font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase;
            padding: 0.2rem 0.55rem; border-radius: 1px;
        }
        .pill.available { background: rgba(90,122,94,0.25); color: var(--sage-light); }
        .pill.sold       { background: rgba(184,92,56,0.25); color: #d4856a; }

        .overlay-actions {
            display: flex; gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .overlay-btn {
            font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase;
            padding: 0.4rem 0.9rem; border-radius: 2px; text-decoration: none;
            border: 1px solid rgba(247,243,238,0.25); color: var(--cream);
            background: transparent;
            transition: border-color 0.2s, background 0.2s;
        }
        .overlay-btn:hover { border-color: var(--gold); color: var(--gold-light); }
        .overlay-btn.primary {
            background: var(--gold); border-color: var(--gold); color: var(--ink);
        }
        .overlay-btn.primary:hover { background: var(--gold-light); border-color: var(--gold-light); }

        .gallery-item.sold::before {
            content: 'Sold';
            position: absolute;
            top: 1rem; right: -2rem;
            background: var(--terracotta);
            color: var(--cream);
            font-size: 10px; letter-spacing: 0.15em; text-transform: uppercase;
            padding: 0.2rem 2.5rem;
            transform: rotate(45deg);
            z-index: 2;
            pointer-events: none;
        }

        .empty-state {
            text-align: center;
            padding: 5rem 2rem;
        }
        .empty-state i { font-size: 3rem; opacity: 0.2; display: block; margin-bottom: 1.5rem; }
        .empty-state h2 {
            font-family: var(--ff-display);
            font-style: italic;
            font-size: 1.8rem;
            color: var(--cream-2);
            margin-bottom: 0.75rem;
        }
        .empty-state p { font-size: 14px; color: var(--ink-soft); margin-bottom: 2rem; }

        .footer-rule {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(196,154,60,0.1);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 1rem;
        }
        .footer-wordmark {
            font-family: var(--ff-display);
            font-size: 1.1rem; font-style: italic;
            color: rgba(196,154,60,0.4);
        }
        .footer-note { font-size: 12px; color: var(--ink-soft); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .fade-up { animation: fadeUp 0.55s cubic-bezier(0.16,1,0.3,1) both; }
        .fade-up.d1 { animation-delay: 0.06s; }
        .fade-up.d2 { animation-delay: 0.12s; }

        .gallery-item { opacity: 0; animation: fadeUp 0.5s cubic-bezier(0.16,1,0.3,1) forwards; }

        .alert-strip {
            margin: 1.5rem 0;
            padding: 1rem 1.5rem;
            font-size: 13px; color: var(--cream-2);
            background: rgba(184,92,56,0.12);
            border-left: 3px solid var(--terracotta);
            border-radius: 0 2px 2px 0;
        }
        
        .warning-strip {
            background: rgba(196,154,60,0.12);
            border-left: 3px solid var(--gold);
            margin: 1rem 0;
            padding: 0.75rem 1rem;
            font-size: 12px;
            color: var(--gold-light);
        }
    </style>
</head>
<body>
<div class="studio">

    <header class="masthead fade-up">
        <div>
            <div class="masthead-brand">Ardhi Gallery — Artist Studio</div>
            <h1 class="masthead-title"><?php
                $parts = explode(' ', htmlspecialchars($artist_name), 2);
                echo $parts[0];
                if (isset($parts[1])) echo "'s <em>".$parts[1]."</em>";
                else echo "'s <em>Portfolio</em>";
            ?></h1>
            <p class="masthead-sub"><?php echo $total; ?> works in collection</p>
        </div>
        <div class="masthead-actions">
            <a href="upload_artwork.php" class="btn-add"><i class="fas fa-plus"></i> Add Work</a>
            <a href="artist_dashboard.php" class="btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
        </div>
    </header>

    <?php if (isset($error)): ?>
        <div class="alert-strip"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (empty($artworks)): ?>
        <div class="empty-state fade-up d1">
            <i class="fas fa-paint-brush"></i>
            <h2>Your studio is empty</h2>
            <p>Upload your first piece and let it find its home.</p>
            <a href="upload_artwork.php" class="btn-add"><i class="fas fa-plus"></i> Add First Work</a>
        </div>
    <?php else: ?>

        <div class="filter-bar fade-up d1">
            <div class="count-pills">
                <button class="count-pill active" data-filter="all">
                    <span class="n"><?php echo $total; ?></span> All
                </button>
                <button class="count-pill" data-filter="available">
                    <span class="n"><?php echo $available; ?></span> Available
                </button>
                <button class="count-pill" data-filter="sold">
                    <span class="n"><?php echo $sold; ?></span> Sold
                </button>
            </div>
            <select class="sort-select" id="sortSelect">
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="price-hi">Price: high to low</option>
                <option value="price-lo">Price: low to high</option>
            </select>
        </div>

        <!-- Debug Warning (remove after fixing) -->
        <?php
        $has_images = false;
        foreach ($artworks as $art) {
            if (getArtworkImageUrl($art)) {
                $has_images = true;
                break;
            }
        }
        if (!$has_images && $total > 0):
        ?>
        <div class="warning-strip">
            <i class="fas fa-info-circle me-2"></i> 
            <strong>Note:</strong> Your artworks are uploaded but images aren't displaying. 
            Please ensure images are uploaded to the correct folder (uploads/artworks/).
        </div>
        <?php endif; ?>

        <div class="gallery-grid" id="galleryGrid">
            <?php foreach ($artworks as $i => $art):
                $status = $art['status'] ?? 'available';
                $image_url = getArtworkImageUrl($art);
            ?>
            <div class="gallery-item <?php echo $status; ?>"
                 data-status="<?php echo $status; ?>"
                 data-price="<?php echo $art['price'] ?? 0; ?>"
                 data-date="<?php echo strtotime($art['created_at'] ?? '0'); ?>"
                 style="animation-delay: <?php echo min($i * 0.05, 0.5); ?>s">

                <?php if ($image_url && file_exists($image_url)): ?>
                    <img src="<?php echo htmlspecialchars($image_url); ?>"
                         alt="<?php echo htmlspecialchars($art['title']); ?>"
                         onerror="this.onerror=null; this.parentElement.querySelector('.gallery-placeholder').style.display='flex'; this.style.display='none';">
                    <div class="gallery-placeholder" style="display: none;">
                        <i class="fas fa-image"></i>
                        <span>Image not found</span>
                    </div>
                <?php else: ?>
                    <div class="gallery-placeholder">
                        <i class="fas fa-image"></i>
                        <span>No image</span>
                        <?php if (!empty($art['image_path']) || !empty($art['featured_image'])): ?>
                            <span style="font-size: 9px; opacity: 0.5;">File missing: <?php echo htmlspecialchars(basename($art['image_path'] ?? $art['featured_image'] ?? '')); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="gallery-overlay">
                    <div class="overlay-title"><?php echo htmlspecialchars($art['title']); ?></div>

                    <?php if (!empty($art['medium'])): ?>
                        <div style="font-size:11px;color:var(--ink-soft);margin-bottom:.4rem;letter-spacing:.04em">
                            <?php echo htmlspecialchars($art['medium']); ?>
                            <?php if (!empty($art['year_created'])): ?>&nbsp;·&nbsp;<?php echo $art['year_created']; ?><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="overlay-meta">
                        <span class="overlay-price">KSh <?php echo number_format($art['price'] ?? 0, 0); ?></span>
                        <span class="pill <?php echo $status; ?>"><?php echo ucfirst($status); ?></span>
                    </div>

                    <?php if ($status === 'sold'): ?>
                        <div style="font-size:11px;color:var(--sage-light);margin-top:.4rem">
                            Your 70%: KSh <?php echo number_format(($art['price'] ?? 0) * 0.7, 0); ?>
                        </div>
                    <?php endif; ?>

                    <div class="overlay-actions">
                        <a href="edit_artwork.php?id=<?php echo $art['id']; ?>" class="overlay-btn primary">
                            <i class="fas fa-pencil-alt" style="margin-right:.3rem"></i> Edit
                        </a>
                        <?php if (!empty($art['description'])): ?>
                        <span class="overlay-btn" title="<?php echo htmlspecialchars(substr($art['description'], 0, 80)); ?>">
                            Details
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <footer class="footer-rule">
        <span class="footer-wordmark">Ardhi Gallery</span>
        <span class="footer-note"><?php echo $available; ?> available · <?php echo $sold; ?> sold · <?php echo date('Y'); ?></span>
    </footer>
</div>

<script>
const grid  = document.getElementById('galleryGrid');
const pills = document.querySelectorAll('.count-pill');
const sort  = document.getElementById('sortSelect');

if (grid) {
    function getItems() { return [...grid.querySelectorAll('.gallery-item')]; }

    function filterAndSort() {
        const activeFilter = document.querySelector('.count-pill.active')?.dataset.filter ?? 'all';
        const sortVal      = sort?.value ?? 'newest';

        let items = getItems();

        items.forEach(el => {
            const match = activeFilter === 'all' || el.dataset.status === activeFilter;
            el.style.display = match ? '' : 'none';
        });

        const visible = items.filter(el => el.style.display !== 'none');
        visible.sort((a, b) => {
            if (sortVal === 'newest')   return b.dataset.date  - a.dataset.date;
            if (sortVal === 'oldest')   return a.dataset.date  - b.dataset.date;
            if (sortVal === 'price-hi') return b.dataset.price - a.dataset.price;
            if (sortVal === 'price-lo') return a.dataset.price - b.dataset.price;
            return 0;
        });
        visible.forEach((el, i) => {
            el.style.order = i;
            grid.appendChild(el);
        });
    }

    pills.forEach(pill => {
        pill.addEventListener('click', () => {
            pills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            filterAndSort();
        });
    });

    if (sort) sort.addEventListener('change', filterAndSort);
}
</script>
</body>
</html>