<?php
require '../models/connection.php';
session_start();
$ip = $_SERVER['REMOTE_ADDR'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            die("Account is locked. Try again later.");
        }

        if (password_verify($password, $user['password_hash'])) {
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $user['role'];
            
            $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE username = ?")
                ->execute([$username]);
            $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 1)")
                ->execute([$username, $ip]);

            echo "Login successful. Role: " . $user['role'];
        } else {
            $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 0)")
                ->execute([$username, $ip]);

            $failed_attempts = $user['failed_attempts'] + 1;
            $lock_time = $failed_attempts >= 3 ? date('Y-m-d H:i:s', strtotime('+5 minutes')) : NULL;
            $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE username = ?")
                ->execute([$failed_attempts, $lock_time, $username]);

            die("Invalid credentials.");
        }
    } else {
        $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 0)")
            ->execute([$username, $ip]);
        die("User not found.");
    }
}
?>