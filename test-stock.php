
<?php

require_once __DIR__ . '/src/services.php';

$products = [
    'TEE-ORG-WHT-M',
    'HOOD-FLC-GRY-M',
    'BTL-STL-750'
];

echo "Testing stock API...\n\n";

foreach ($products as $sku) {

    $quantity = stock_quantity($sku);

    echo $sku . ': ';

    if ($quantity === null) {
        echo "API connection failed\n";
    } else {
        echo "Available quantity: " . $quantity . "\n";
    }
}
