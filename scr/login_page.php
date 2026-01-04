<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
require_once 'config/session.php';

// Nếu đã đăng nhập, chuyển về trang chủ
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Kiểm tra thông báo access denied
if (isset($_GET['error']) && $_GET['error'] === 'access_denied') {
    $error = 'Bạn cần quyền admin để truy cập trang này. Vui lòng đăng nhập với tài khoản admin.';
}

// Xử lý đăng nhập
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $loginUsername = trim($_POST['loginUsername'] ?? '');
    $loginPassword = $_POST['loginPassword'] ?? '';
    
    if (empty($loginUsername) || empty($loginPassword)) {
        $error = 'Vui lòng nhập đầy đủ thông tin!';
    } else {
        $conn = getDBConnection();
        
        $stmt = $conn->prepare("SELECT user_id, username, email, password, COALESCE(role, 'student') as role FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $loginUsername, $loginUsername);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $error = 'Tên đăng nhập hoặc mật khẩu không đúng!';
        } else {
            $user = $result->fetch_assoc();
            
            if (!password_verify($loginPassword, $user['password'])) {
                $error = 'Tên đăng nhập hoặc mật khẩu không đúng!';
            } else {
                // Đăng nhập thành công
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                
                // Debug log
                error_log("Login successful - User: " . $user['username'] . ", Role: " . $user['role']);
                
                // Redirect dựa trên role
                if ($user['role'] === 'admin') {
                    error_log("Redirecting admin to admin-simple.php");
                    header('Location: admin-simple.php');
                } else {
                    error_log("Redirecting student to index.php");
                    header('Location: index.php');
                }
                exit;
            }
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Xử lý đăng ký
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    
    // Validate
    if (empty($username) || empty($email) || empty($password) || empty($confirmPassword)) {
        $error = 'Vui lòng nhập đầy đủ thông tin!';
    } elseif (strlen($username) < 3) {
        $error = 'Tên đăng nhập phải có ít nhất 3 ký tự!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email không hợp lệ!';
    } elseif (strlen($password) < 6) {
        $error = 'Mật khẩu phải có ít nhất 6 ký tự!';
    } elseif ($password !== $confirmPassword) {
        $error = 'Mật khẩu nhập lại không khớp!';
    } else {
        $conn = getDBConnection();
        
        // Kiểm tra username
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'Tên đăng nhập đã tồn tại!';
        } else {
            $stmt->close();
            
            // Kiểm tra email
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Email đã được sử dụng!';
            } else {
                $stmt->close();
                
                // Tạo tài khoản
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param("sss", $username, $email, $hashedPassword);
                
                if ($stmt->execute()) {
                    $success = 'Đăng ký thành công! Vui lòng đăng nhập.';
                } else {
                    $error = 'Có lỗi xảy ra, vui lòng thử lại!';
                }
            }
        }
        
        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Học Lịch Sử Lớp 12</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #EFF6FF;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            max-width: 900px;
            width: 100%;
            display: flex;
            min-height: 600px;
        }

        .login-left {
            flex: 1;
            background: #1D3557;
            padding: 60px 40px;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-left h1 {
            font-size: 32px;
            margin-bottom: 20px;
        }

        .login-left p {
            font-size: 16px;
            line-height: 1.6;
            opacity: 0.9;
        }

        .login-left .features {
            margin-top: 40px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .feature-item i {
            font-size: 24px;
            margin-right: 15px;
        }

        .login-right {
            flex: 1;
            padding: 60px 40px;
        }

        .form-container {
            max-width: 400px;
            margin: 0 auto;
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo i {
            font-size: 48px;
            color: #1D3557;
        }

        .logo h2 {
            margin-top: 10px;
            color: #333;
            font-size: 24px;
        }

        .tabs {
            display: flex;
            margin-bottom: 30px;
            border-bottom: 2px solid #e0e0e0;
        }

        .tab-btn {
            flex: 1;
            padding: 15px;
            background: none;
            border: none;
            font-size: 16px;
            font-weight: 600;
            color: #999;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .tab-btn.active {
            color: #000000ff;
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #1D3557;
        }

        .form-content {
            display: none;
        }

        .form-content.active {
            display: block;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        .form-group label i {
            margin-right: 5px;
            color: #1D3557;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #1D3557;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #666;
        }

        .remember-me input {
            margin-right: 8px;
        }

        .forgot-password {
            font-size: 14px;
            color: #1D3557;
            text-decoration: none;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #1D3557;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(29, 53, 87, 0.4);
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .alert-success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }

        .back-home {
            text-align: center;
            margin-top: 20px;
        }

        .back-home a {
            color: #1D3557;
            text-decoration: none;
            font-size: 14px;
        }

        .back-home a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
            }

            .login-left {
                padding: 40px 30px;
            }

            .login-right {
                padding: 40px 30px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-left">
            <h1>🎓 Học Lịch Sử Lớp 12</h1>
            <p>Hệ thống ôn thi THPT Quốc gia môn Lịch sử với trắc nghiệm từng bài học và tài liệu chất lượng cao.</p>
            
            <div class="features">
                <div class="feature-item">
                    <i class="fas fa-book-open"></i>
                    <span>Học theo từng chủ đề có hệ thống</span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Trắc nghiệm theo từng bài học</span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-chart-line"></i>
                    <span>Theo dõi tiến độ học tập</span>
                </div>
            </div>
        </div>

        <div class="login-right">
            <div class="form-container">
                <div class="logo">
                    <i class="fas fa-graduation-cap"></i>
                    <h2>Chào mừng trở lại!</h2>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <div class="tabs">
                    <button class="tab-btn active" onclick="switchTab('login')">
                        <i class="fas fa-sign-in-alt"></i> Đăng nhập
                    </button>
                    <button class="tab-btn" onclick="switchTab('register')">
                        <i class="fas fa-user-plus"></i> Đăng ký
                    </button>
                </div>

                <!-- Form Đăng nhập -->
                <div id="loginForm" class="form-content active">
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="loginUsername">
                                <i class="fas fa-user"></i> Tên đăng nhập hoặc Email
                            </label>
                            <input type="text" id="loginUsername" name="loginUsername" 
                                   placeholder="Nhập tên đăng nhập hoặc email" required>
                        </div>

                        <div class="form-group">
                            <label for="loginPassword">
                                <i class="fas fa-lock"></i> Mật khẩu
                            </label>
                            <input type="password" id="loginPassword" name="loginPassword" 
                                   placeholder="Nhập mật khẩu" required>
                        </div>



                        <button type="submit" name="login" class="btn-submit">
                            <i class="fas fa-sign-in-alt"></i> Đăng nhập
                        </button>
                    </form>
                </div>

                <!-- Form Đăng ký -->
                <div id="registerForm" class="form-content">
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="username">
                                <i class="fas fa-user"></i> Tên đăng nhập
                            </label>
                            <input type="text" id="username" name="username" 
                                   placeholder="Nhập tên đăng nhập" required minlength="3">
                        </div>

                        <div class="form-group">
                            <label for="email">
                                <i class="fas fa-envelope"></i> Email
                            </label>
                            <input type="email" id="email" name="email" 
                                   placeholder="example@email.com" required>
                        </div>

                        <div class="form-group">
                            <label for="password">
                                <i class="fas fa-lock"></i> Mật khẩu
                            </label>
                            <input type="password" id="password" name="password" 
                                   placeholder="Tối thiểu 6 ký tự" required minlength="6">
                        </div>

                        <div class="form-group">
                            <label for="confirmPassword">
                                <i class="fas fa-lock"></i> Nhập lại mật khẩu
                            </label>
                            <input type="password" id="confirmPassword" name="confirmPassword" 
                                   placeholder="Nhập lại mật khẩu" required>
                        </div>

                        <button type="submit" name="register" class="btn-submit">
                            <i class="fas fa-user-plus"></i> Đăng ký
                        </button>
                    </form>
                </div>

                <div class="back-home">
                    <a href="index.php">
                        <i class="fas fa-arrow-left"></i> Quay lại trang chủ
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            // Remove active class from all tabs and forms
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.form-content').forEach(form => form.classList.remove('active'));

            // Add active class to selected tab
            if (tab === 'login') {
                document.querySelector('.tab-btn:first-child').classList.add('active');
                document.getElementById('loginForm').classList.add('active');
            } else {
                document.querySelector('.tab-btn:last-child').classList.add('active');
                document.getElementById('registerForm').classList.add('active');
            }
        }
    </script>
</body>
</html>
