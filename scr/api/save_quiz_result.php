<?php
require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ']);
    exit;
}

$userId = $_SESSION['user_id'];
$lessonId = intval($_POST['lesson_id'] ?? 0);
$quizName = trim($_POST['quiz_name'] ?? '');
$score = intval($_POST['score'] ?? 0);
$totalQuestions = intval($_POST['total_questions'] ?? 0);
$timeTaken = intval($_POST['time_taken'] ?? 0);

if (empty($quizName) || $totalQuestions <= 0 || $lessonId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ']);
    exit;
}

$percentage = round(($score / $totalQuestions) * 100, 2);

$conn = getDBConnection();

// Lưu kết quả
$stmt = $conn->prepare("
    INSERT INTO quiz_results (user_id, lesson_id, quiz_name, score, total_questions, percentage, time_taken, completed_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->bind_param("iisiidi", $userId, $lessonId, $quizName, $score, $totalQuestions, $percentage, $timeTaken);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Đã lưu kết quả',
        'percentage' => $percentage
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Có lỗi xảy ra: ' . $conn->error
    ]);
}

$stmt->close();
$conn->close();
?>
