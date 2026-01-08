<?php
require_once 'config/database.php';

// Khởi tạo session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    // Nếu là API lưu kết quả (POST) thì trả JSON 401 thay vì redirect
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Bạn cần đăng nhập để lưu kết quả.']);
        exit;
    }
    header('Location: login_page.php');
    exit;
}

// Lấy thông tin user
$currentUser = [
    'id' => $_SESSION['user_id'],
    'username' => $_SESSION['username'],
    'email' => $_SESSION['email']
];

$conn = getDBConnection();

// =========================
// API lưu kết quả trắc nghiệm (AJAX/Fetch)
// Gửi POST tới thong-ke.php với JSON hoặc form-data:
// action=save_quiz_result, score, total_questions, quiz_name (optional), lesson_id (optional), item_id (optional)
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Nhận dữ liệu JSON hoặc form-data
    $raw = file_get_contents('php://input');
    $data = [];
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (is_array($json)) $data = $json;
    }
    if (empty($data)) $data = $_POST;

    $action = isset($data['action']) ? $data['action'] : '';
    if ($action !== 'save_quiz_result') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Thiếu hoặc sai action.']);
        exit;
    }

    $user_id = (int)$_SESSION['user_id'];
    $score = isset($data['score']) ? (int)$data['score'] : -1;
    $total_questions = isset($data['total_questions']) ? (int)$data['total_questions'] : -1;

    // Optional
    $quiz_name = isset($data['quiz_name']) ? trim((string)$data['quiz_name']) : '';
    $lesson_id = isset($data['lesson_id']) ? (int)$data['lesson_id'] : 0;
    $item_id   = isset($data['item_id']) ? (int)$data['item_id'] : 0;

    if ($score < 0 || $total_questions <= 0 || $score > $total_questions) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Dữ liệu điểm không hợp lệ.']);
        exit;
    }

    if ($quiz_name === '') {
        // Tự đặt tên cho dễ thống kê
        if ($item_id > 0) $quiz_name = "Trắc nghiệm mục #{$item_id}";
        elseif ($lesson_id > 0) $quiz_name = "Trắc nghiệm bài #{$lesson_id}";
        else $quiz_name = "Trắc nghiệm";
    }
    // Giới hạn độ dài để tránh lỗi DB
    if (mb_strlen($quiz_name, 'UTF-8') > 255) $quiz_name = mb_substr($quiz_name, 0, 255, 'UTF-8');

    // Dò cột để INSERT an toàn (tùy schema)
    $cols = [];
    $rs = $conn->query("SHOW COLUMNS FROM quiz_results");
    if ($rs) {
        while ($row = $rs->fetch_assoc()) $cols[] = $row['Field'];
        $rs->free();
    }

    // Bắt buộc tối thiểu phải có user_id, score, total_questions
    $fields = [];
    $placeholders = [];
    $types = "";
    $values = [];

    $addField = function($name, $type, $value) use (&$fields, &$placeholders, &$types, &$values, $cols) {
        if (!in_array($name, $cols, true)) return;
        $fields[] = $name;
        if ($value === null) {
            $placeholders[] = "NULL";
        } else {
            $placeholders[] = "?";
            $types .= $type;
            $values[] = $value;
        }
    };

    // Nếu bảng không có các cột này thì sẽ tự bỏ qua
    $addField('user_id', 'i', $user_id);
    $addField('quiz_name', 's', $quiz_name);
    $addField('score', 'i', $score);
    $addField('total_questions', 'i', $total_questions);

    if ($lesson_id > 0) $addField('lesson_id', 'i', $lesson_id);
    if ($item_id > 0) $addField('item_id', 'i', $item_id);

    // Thời gian hoàn thành: dùng NOW() nếu có cột completed_at
    if (in_array('completed_at', $cols, true)) {
        $fields[] = 'completed_at';
        $placeholders[] = 'NOW()';
    }

    if (count($fields) < 3) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Schema quiz_results không đúng (thiếu cột cần thiết).']);
        exit;
    }

    $sql = "INSERT INTO quiz_results (" . implode(",", $fields) . ") VALUES (" . implode(",", $placeholders) . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Không prepare được câu lệnh lưu kết quả.']);
        exit;
    }

    if (!empty($values)) {
        // bind_param cần tham chiếu
        $bind = [];
        $bind[] = $types;
        for ($i = 0; $i < count($values); $i++) $bind[] = &$values[$i];
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    $ok = $stmt->execute();
    $new_id = $stmt->insert_id;
    $err = $stmt->error;
    $stmt->close();

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Lưu kết quả thất bại: ' . $err]);
        exit;
    }

    echo json_encode(['success' => true, 'result_id' => $new_id]);
    exit;
}

// =========================
// API BIỂU ĐỒ THEO TỪNG BÀI HỌC (AJAX)
// GET: thong-ke.php?action=get_histogram
// Trả về: labels (Bài ...), avg (điểm TB %), attempts (số lần làm)
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_histogram') {
    header('Content-Type: application/json; charset=utf-8');

    $user_id = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare("
      SELECT
        l.lesson_id,
        l.lesson_title,
        COUNT(qr.result_id) AS attempts,
        ROUND(AVG(qr.score / qr.total_questions * 100), 1) AS avg_pct
      FROM lessons l
      LEFT JOIN quiz_results qr
        ON qr.lesson_id = l.lesson_id
       AND qr.user_id = ?
      GROUP BY l.lesson_id, l.lesson_title
      ORDER BY l.lesson_id
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rs = $stmt->get_result();

    $labels = [];
    $avg = [];
    $attempts = [];

    while ($row = $rs->fetch_assoc()) {
        $labels[] = "Bài " . (int)$row['lesson_id'];
        $attempts[] = (int)$row['attempts'];
        $avg[] = ((int)$row['attempts'] > 0 && $row['avg_pct'] !== null) ? (float)$row['avg_pct'] : 0;
    }

    $stmt->close();

    echo json_encode([
      'success' => true,
      'labels' => $labels,
      'avg' => $avg,
      'attempts' => $attempts
    ]);
    exit;
}


// Lấy thống kê kết quả trắc nghiệm (danh sách lần làm)
$stmt = $conn->prepare("
    SELECT 
        quiz_name,
        score,
        total_questions,
        ROUND((score / total_questions) * 100, 1) as percentage,
        completed_at
    FROM quiz_results 
    WHERE user_id = ? 
    ORDER BY completed_at DESC
");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();

$stmt->bind_result($quiz_name, $score, $total_questions, $percentage, $completed_at);
$quizResults = [];
while ($stmt->fetch()) {
    $quizResults[] = [
        'quiz_name' => $quiz_name,
        'score' => $score,
        'total_questions' => $total_questions,
        'percentage' => $percentage,
        'completed_at' => $completed_at
    ];
}
$stmt->close();

// Lấy danh sách bài học (không cần bảng learning_progress)
$stmt = $conn->prepare("
    SELECT 
        l.lesson_id,
        l.lesson_title,
        COUNT(qr.result_id) as quiz_attempts,
        MAX(qr.completed_at) as last_quiz_date
    FROM lessons l
    LEFT JOIN quiz_results qr ON l.lesson_id = qr.lesson_id AND qr.user_id = ?
    GROUP BY l.lesson_id, l.lesson_title
    ORDER BY l.lesson_id
");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();

$stmt->bind_result($lesson_id_r, $lesson_title, $quiz_attempts, $last_quiz_date);
$learningProgress = [];
while ($stmt->fetch()) {
    $learningProgress[] = [
        'lesson_id' => $lesson_id_r,
        'lesson_title' => $lesson_title,
        'quiz_attempts' => $quiz_attempts,
        'last_quiz_date' => $last_quiz_date
    ];
}
$stmt->close();

// Tính toán thống kê tổng quan
$totalQuizzes = count($quizResults);
$totalScore = 0;
$totalQuestions = 0;

foreach ($quizResults as $result) {
    $totalScore += $result['score'];
    $totalQuestions += $result['total_questions'];
}

$averagePercentage = $totalQuestions > 0 ? round(($totalScore / $totalQuestions) * 100, 1) : 0;

// Tính toán tiến độ học bài
$totalLessons = count($learningProgress);
$completedLessons = 0;
$accessedLessons = 0;

foreach ($learningProgress as $progress) {
    if ($progress['quiz_attempts'] > 0) {
        $completedLessons++;
        $accessedLessons++;
    }
}

$learningPercentage = $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100, 1) : 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thống Kê Học Tập - Học Lịch Sử Lớp 12</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">

<link rel="stylesheet" href="index.css" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
  /* =========================
     THỐNG KÊ — MATCH INDEX THEME
     (dùng biến màu / radius / shadow từ index.css)
     ========================= */

  main.stats-page{
    padding: 36px 0 46px;
  }

  /* Header nội dung */
  .stats-header{
    text-align: center;
    margin: 6px auto 18px;
  }
  .stats-header h1{
    margin: 0;
    font-family: "Merriweather", serif;
    font-weight: 900;
    color: #132844;
    font-size: clamp(26px, 3.2vw, 40px);
    letter-spacing: .2px;
  }
  .stats-header p{
    margin: 10px auto 0;
    max-width: 72ch;
    color: var(--muted);
    font-weight: 600;
  }

  /* Tổng quan 4 thẻ */
  .overview-cards{
    display: grid;
    grid-template-columns: repeat(4, minmax(220px, 1fr));
    gap: 16px;
    margin-top: 18px;
  }

  .stat-card{
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    box-shadow: var(--shadow-soft);
    padding: 16px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: .12s ease;
  }
  .stat-card:hover{
    transform: translateY(-2px);
    box-shadow: var(--shadow-strong);
  }

  .stat-card > i{
    width: 48px;
    height: 48px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    background: rgba(29,53,87,.10);
    border: 1px solid rgba(29,53,87,.14);
    color: var(--primary);
    font-size: 18px;
    flex: 0 0 auto;
  }

  .stat-info h3{
    margin: 0;
    font-size: 28px;
    font-weight: 900;
    color: var(--ink);
    line-height: 1.1;
  }
  .stat-info p{
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 13px;
    font-weight: 800;
  }

  /* Tiêu đề section */
  .section-title{
    font-family: "Merriweather", serif;
    font-weight: 900;
    color: var(--primary);
    font-size: 22px;
    margin: 26px 0 14px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .section-title i{
    width: 42px;
    height: 42px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    background: rgba(29,53,87,.10);
    border: 1px solid rgba(29,53,87,.14);
    color: var(--primary);
    font-size: 16px;
  }

  /* Empty state */
  .empty-state{
    background: var(--card);
    border: 1px dashed rgba(29,53,87,.25);
    border-radius: var(--radius);
    box-shadow: var(--shadow-soft);
    padding: 22px;
    text-align: center;
  }
  .empty-state i{
    font-size: 44px;
    color: var(--primary);
    opacity: .9;
  }
  .empty-state h3{
    margin: 12px 0 0;
    font-family: "Merriweather", serif;
    font-weight: 900;
    color: rgba(30,36,48,.92);
  }
  .empty-state p{
    margin: 8px 0 0;
    color: var(--muted);
    font-weight: 600;
  }

  /* Table */
  .quiz-results-table{
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    box-shadow: var(--shadow-soft);
    overflow: hidden;
  }
  .quiz-results-table table{
    width: 100%;
    border-collapse: collapse;
  }
  .quiz-results-table thead th{
    background: rgba(243,246,251,.85);
    color: var(--primary);
    font-weight: 900;
    font-size: 13px;
    border-bottom: 1px solid var(--line);
    padding: 12px 14px;
    text-align: left;
    white-space: nowrap;
  }
  .quiz-results-table tbody td{
    padding: 12px 14px;
    border-bottom: 1px solid var(--line);
    color: rgba(30,36,48,.90);
    font-weight: 700;
  }
  .quiz-results-table tbody tr:hover{
    background: var(--hover);
  }
  .quiz-results-table tbody tr:last-child td{
    border-bottom: none;
  }

  /* Badge điểm */
  .score-badge{
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-radius: 999px;
    border: 1px solid var(--line);
    font-weight: 900;
    font-size: 12px;
  }

  .score-excellent{ background: rgba(27,154,170,.10); border-color: rgba(27,154,170,.22); color: #1B9AAA; }
  .score-good{      background: rgba(46,64,87,.10);  border-color: rgba(46,64,87,.22);  color: var(--primary); }
  .score-average{   background: rgba(255,193,7,.14); border-color: rgba(255,193,7,.26); color: #a06700; }
  .score-poor{      background: rgba(178,58,72,.10); border-color: rgba(178,58,72,.20); color: #B23A48; }

  /* Nút: dùng style của index.css */
  .empty-state .btn{
    margin-top: 14px;
  }

  /* Layout responsive */
  @media (max-width: 980px){
    .overview-cards{ grid-template-columns: repeat(2, minmax(220px, 1fr)); }
    .quiz-results-table{ overflow-x: auto; }
  }
  @media (max-width: 560px){
    .overview-cards{ grid-template-columns: 1fr; }
    .stat-info h3{ font-size: 26px; }
  }
</style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>

    <main class="stats-page">
        <div class="container">
            <!-- Header -->
            <div class="stats-header">
                <h1><i class="fas fa-chart-line"></i> Thống Kê Học Tập</h1>
                <p>Xin chào <strong><?php echo htmlspecialchars($currentUser['username']); ?></strong>! Đây là kết quả học tập của bạn.</p>
            </div>

            <!-- Tổng quan -->
            <div class="overview-cards">
                <div class="stat-card">
                    <i class="fas fa-book-open"></i>
                    <div class="stat-info">
                        <h3><?php echo $learningPercentage; ?>%</h3>
                        <p>Tiến độ học bài</p>
                    </div>
                </div>

                <div class="stat-card">
                    <i class="fas fa-trophy"></i>
                    <div class="stat-info">
                        <h3><?php echo $averagePercentage; ?>%</h3>
                        <p>Điểm trung bình</p>
                    </div>
                </div>

                <div class="stat-card">
                    <i class="fas fa-clipboard-check"></i>
                    <div class="stat-info">
                        <h3><?php echo $totalQuizzes; ?></h3>
                        <p>Bài trắc nghiệm đã làm</p>
                    </div>
                </div>

                <div class="stat-card">
                    <i class="fas fa-check-circle"></i>
                    <div class="stat-info">
                        <h3><?php echo $completedLessons; ?>/<?php echo $totalLessons; ?></h3>
                        <p>Bài học đã hoàn thành</p>
                    </div>
                </div>
            </div>

            <!-- Kết quả trắc nghiệm -->
            <h2 class="section-title">
                <i class="fas fa-list-alt"></i> Kết Quả Trắc Nghiệm
            </h2>

            <!-- CHỈ BIỂU ĐỒ (không còn bảng điểm theo bài) -->
            <div class="quiz-results-table" style="padding:14px; margin-bottom:14px;">
                <canvas id="scoreHistogram" height="110"></canvas>
            </div>

            <?php if (empty($quizResults)): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>Chưa có kết quả trắc nghiệm</h3>
                    <p>Bạn chưa làm bài trắc nghiệm nào. Hãy bắt đầu ngay!</p>
                    <a href="trac-nghiem.php" class="btn btn-primary">
                        <i class="fas fa-play"></i> Bắt đầu học
                    </a>
                </div>
            <?php else: ?>
                <div class="quiz-results-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Bài học</th>
                                <th>Điểm</th>
                                <th>Tỷ lệ</th>
                                <th>Thời gian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quizResults as $result): 
                                $percentage = $result['percentage'];
                                if ($percentage >= 80) {
                                    $badgeClass = 'score-excellent';
                                    $icon = 'fa-star';
                                } elseif ($percentage >= 60) {
                                    $badgeClass = 'score-good';
                                    $icon = 'fa-thumbs-up';
                                } elseif ($percentage >= 40) {
                                    $badgeClass = 'score-average';
                                    $icon = 'fa-meh';
                                } else {
                                    $badgeClass = 'score-poor';
                                    $icon = 'fa-frown';
                                }
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($result['quiz_name']); ?></strong></td>
                                    <td><?php echo $result['score']; ?>/<?php echo $result['total_questions']; ?></td>
                                    <td>
                                        <span class="score-badge <?php echo $badgeClass; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo $percentage; ?>%
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($result['completed_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>


        </div>
    </main>

    <!-- Footer -->
    <footer class="footer-simple">
        <div class="container">
            <div class="footer-content-simple">
                <!-- Logo và mô tả -->
                <div class="footer-brand-simple">
                    <div class="brand-logo-simple">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Lịch Sử 12</span>
                    </div>
                    <p>Ôn thi THPT Quốc gia môn Lịch sử</p>
                </div>

                <!-- Liên hệ -->
                <div class="footer-contact-simple">
                    <h4>Liên hệ</h4>
                    <div class="contact-info-simple">
                        <p><i class="fas fa-envelope"></i> lichsu12@education.vn</p>
                        <p><i class="fas fa-phone"></i> 1900 1234</p>
                        <p><i class="fas fa-map-marker-alt"></i> Trà Vinh, Vĩnh Long</p>
                    </div>
                </div>


            </div>

        </div>
    </footer>

    <script src="script.js"></script>

    <!-- CHỈ THÊM: Chart.js + vẽ biểu đồ theo từng bài + tự cập nhật -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
      let histogramChart = null;

      async function refreshHistogram() {
        try {
          const res = await fetch('thong-ke.php?action=get_histogram', { cache: 'no-store' });
          const data = await res.json();
          if (!data || !data.success) return;

          const canvas = document.getElementById('scoreHistogram');
          if (!canvas) return;

          const ctx = canvas.getContext('2d');

          const chartData = {
            labels: data.labels,
            datasets: [
              {
                type: 'bar',
                label: 'Điểm trung bình (%)',
                data: data.avg,
                yAxisID: 'y'
              },
              {
                type: 'line',
                label: 'Số lần làm',
                data: data.attempts,
                yAxisID: 'y1',
                tension: 0.25
              }
            ]
          };

          const options = {
            responsive: true,
            animation: true,
            scales: {
              y: {
                beginAtZero: true,
                max: 100,
                title: { display: true, text: 'Điểm (%)' }
              },
              y1: {
                beginAtZero: true,
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: { precision: 0 },
                title: { display: true, text: 'Số lần làm' }
              }
            }
          };

          if (!histogramChart) {
            histogramChart = new Chart(ctx, { data: chartData, options });
          } else {
            histogramChart.data.labels = chartData.labels;
            histogramChart.data.datasets[0].data = chartData.datasets[0].data;
            histogramChart.data.datasets[1].data = chartData.datasets[1].data;
            histogramChart.update();
          }
        } catch (e) {
          console.warn('Chart error:', e);
        }
      }

      refreshHistogram();
      setInterval(refreshHistogram, 10000);
    </script>
</body>
</html>
