<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- <link rel="stylesheet" src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous"></link> -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="/public/css/main.css">
    <title>EDRD11</title>
</head>
<body class="home-page">
    <nav>
       <ol>
        <li> <a href="app/controllers/logout.php">Logout</a></li>
       </ol>
    </nav>
    <div class="home">
    <main class="form-container">
        <form method="POST" action="app/controllers/login.php" class="login-form">
            <h1>Login</h1>
            <div class="input-container">
                    <h5>User Name</h5>
                <div class="input-box">                 
                        <input type="text" name="username" required placeholder="Enter your username">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
            </div>
            <div class="input-container">
                    <h5>password</h5>
                    <div class="input-box">
                
                        <input type="password" name="password" required placeholder="Enter your password" minlength="6">
                        <i class="fa-solid fa-lock"></i>
                    </div>
                </div>  
                <button type="submit">Login</button>       
        </form>
    </main>
</div>


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