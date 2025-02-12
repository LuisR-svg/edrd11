
<?php
// Database Connection - db.php
$host = '127.0.0.1:3306';
$dbname = 'u581562866_EDRD11_DB';
$username = 'u581562866_admin';
$password = 'Thinkfast@96';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}