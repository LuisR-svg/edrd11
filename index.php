<!-- <!DOCTYPE html>
<html lang="en">
//
</head>
<body class="home-page">  
    <div class="home">
    <main class="form-container">
        <form method="POST" action="app/controllers/login.php" class="login-form left-side">
          <div class="form-bg">
            <div class="form-header">
                <h1>M<i class="fa-solid fa-compass-drafting"></i>sonic Treasury</h1>
                <h2>Estrell<i class="fa-sharp-duotone fa-solid fa-star-of-david"></i> Del Rey David #11</h2>
          </div>
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
                
                        <input type="password" name="password" required placeholder="Enter your password" minlength="3">
                        <i class="fa-solid fa-lock"></i>
                    </div>
                  <button type="submit">Login <span></span> </button>     
                </div>    
        </form>
        <div class="right-side"></div>
    </main>
   
</div>
</body>
</html> -->

<!DOCTYPE html>
<html lang="en">
<?php include 'app/helpers/head.php'; ?>
</head>
<body class="home-page">
<main>
        <div class="left-side">
            <div class="form-header">
                        <!-- <h1>M<i class="fa-solid fa-compass-drafting"></i>sonic Treasury</h1>
                        <h2>Estrell<i class="fa-sharp-duotone fa-solid fa-star-of-david"></i> Del Rey David #11</h2> -->
                        <h1>Masonic Treasury</h1>
                        <h2>Estrella Del Rey David #11</h2>
                    </div>
            <form method="POST" action="app/controllers/login.php" class="login-form">
                    <h2>Welcome</h2>
                    <div class="input-container">
                            <!-- <h5>User Name</h5> -->
                        <div class="input-box">                 
                                <input type="text" name="username" required placeholder="Enter your username">
                                <!-- <i class="fa-solid fa-user-tie"></i> -->
                        </div>  
                    </div>
                    <div class="input-container">
                           <!-- <h5>password</h5> -->
                            <div class="input-box">                        
                                <input type="password" name="password" required placeholder="Enter your password" minlength="3">
                                <!-- <i class="fa-solid fa-lock"></i> -->
                            </div>
                        <button type="submit">Login <span></span> </button>     
                    </div>    
            </form>
        </div>
    <div class="right-side"></div>
</main>
    
</body>
</html>