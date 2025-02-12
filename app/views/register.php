<?php
require '../modles/connection.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $role = $_POST['role'];
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
    if ($stmt->execute([$username, $password, $role])) {
        echo "Registration successful.";
    } else {
        echo "Registration failed.";
    }
}