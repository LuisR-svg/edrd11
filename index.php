<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>Document</title>
</head>
<body>
    
<a href="app/controllers/logout.php">Logout</a>

<form method="POST">
        <input type="text" name="username" required placeholder="Username">
        <input type="password" name="password" required placeholder="Password">
        <button type="submit">Login</button>
</form>



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