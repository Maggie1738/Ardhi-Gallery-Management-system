<?php
$artwork_report_query = "
    SELECT 
        a.id,
        a.title,
        a.artist_name,
        a.price,
        COUNT(p.id) as units_sold,
        SUM(p.amount) as total_revenue,
        AVG(p.amount) as avg_sale_price,
        MIN(p.created_at) as first_sale,
        MAX(p.created_at) as last_sale
    FROM artworks a
    LEFT JOIN payments p ON a.id = p.artwork_id AND p.status = 'completed'
    GROUP BY a.id
    ORDER BY total_revenue DESC
";