<?php
// Quản lý session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kiểm tra đăng nhập
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Kiểm tra quyền admin
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Lấy thông tin user
function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'email' => $_SESSION['email'],
            'role' => $_SESSION['role'] ?? 'student'
        ];
    }
    return null;
}

// Yêu cầu quyền admin (redirect nếu không phải admin)
function requireAdmin() {
    if (!isAdmin()) {
        header('Location: login_page.php?error=access_denied');
        exit;
    }
}

// Đăng xuất
function logout() {
    session_unset();
    session_destroy();
}
?>
