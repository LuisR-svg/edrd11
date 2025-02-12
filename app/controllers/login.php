/* User Login - login.php */
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
            $_SESSION['loggedin'] = true;
            
            $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE username = ?")
                ->execute([$username]);
            
            $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 1)");
            if (!$stmt->execute([$username, $ip])) {
                die("Error logging successful attempt.");
            }
            
            // Redirect users based on their role
            if ($user['role'] === 'admin') {
                $_SESSION['admin'] = true;
                header("Location: ../views/admin_dashboard.php");
                exit();
            } elseif ($user['role'] === 'editor') {
                $_SESSION['editor'] = true;
                header("Location: ../views/editor_dashboard.php");
                exit();
            } else {
                $_SESSION['user'] = true;
                header("Location: ../views/user_dashboard.php");
                exit();
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 0)");
            if (!$stmt->execute([$username, $ip])) {
                die("Error logging failed attempt.");
            }

            $failed_attempts = $user['failed_attempts'] + 1;
            $lock_time = $failed_attempts >= 3 ? date('Y-m-d H:i:s', strtotime('+5 minutes')) : NULL;
            
            $stmt = $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE username = ?");
            if (!$stmt->execute([$failed_attempts, $lock_time, $username])) {
                die("Error updating failed attempts.");
            }

            die("Invalid credentials.");
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 0)");
        if (!$stmt->execute([$username, $ip])) {
            die("Error logging unknown user attempt.");
        }
        die("User not found.");
    }
}