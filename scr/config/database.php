<?php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_history');



// 2. Hàm kết nối database
function connectDatabase() {    
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        // Kiểm tra lỗi kết nối
        if ($conn->connect_error) {
            throw new Exception("Kết nối thất bại: " . $conn->connect_error);
        }
        
        // Thiết lập charset UTF-8
        $conn->set_charset("utf8mb4");
        return $conn;
        
    } catch (Exception $e) {
        die("Lỗi kết nối CSDL: " . $e->getMessage());
    }
}

// 3. Hàm kết nối cũ (để tương thích)
function getDBConnection() {
    return connectDatabase();
}

?>