<?php

# probe_config.php

    $pdo = new PDO(
        'mysql:host=localhost;dbname=[DATABASE NAME];charset=utf8mb4',
        '[USER NAME]',
        '[PASSWORD]',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );
