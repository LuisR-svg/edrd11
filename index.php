<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/public/css/main.css">
    <title>Document</title>
</head>
<body class="home">
    <nav>
       <ol>
        <li> <a href="app/controllers/logout.php">Logout</a></li>
       </ol>
    </nav>


<main class="form-container">
    <form method="POST" action="app/controllers/login.php" class="login-form">
            <input type="text" name="username" required placeholder="Username">
            <input type="password" name="password" required placeholder="Password" minlength="6">
            <button type="submit">Login</button>
    </form>
</main>


<form method="POST" action="app/views/register.php">
    <input type="text" name="username" required placeholder="Username">
    <input type="password" name="password" required placeholder="Password">
    <select name="role">
        <option value="user">User</option>
        <option value="editor">Editor</option>
        <option value="admin">Admin</option>
    </select>
    <button type="submit">Register</button>
</form>


</body>
</html>