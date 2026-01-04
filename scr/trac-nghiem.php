<?php
// Bắt đầu session trước khi có output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
$conn = getDBConnection();

// Lấy dữ liệu từ database
$sql = "SELECT 
    t.topic_id,
    t.topic_name,
    t.description as topic_description,
    l.lesson_id,
    l.lesson_title,
    l.page_start,
    l.page_end
FROM topics t
LEFT JOIN lessons l ON t.topic_id = l.topic_id
ORDER BY t.topic_id, l.lesson_id";

$result = $conn->query($sql);
$data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$topics = [];
$lessons_flat = [];

foreach ($data as $row) {
    $topic_id = (int)$row['topic_id'];

    if (!isset($topics[$topic_id])) {
        $topics[$topic_id] = [
            'topic_id' => $topic_id,
            'topic_name' => $row['topic_name'],
            'topic_description' => $row['topic_description'],
            'lessons' => []
        ];
    }

    if (!empty($row['lesson_id'])) {
        $lesson = [
            'topic_id' => $topic_id,
            'topic_name' => $row['topic_name'],
            'lesson_id' => (int)$row['lesson_id'],
            'lesson_title' => $row['lesson_title'],
            'page_start' => $row['page_start'],
            'page_end' => $row['page_end']
        ];
        $topics[$topic_id]['lessons'][] = $lesson;
        $lessons_flat[] = $lesson;
    }
}

$conn->close();

$total_topics  = count($topics);
$total_lessons = count($lessons_flat);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Trắc nghiệm theo bài học - Học Lịch Sử Lớp 12</title>

  <!-- Fonts giống index.php -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">

  <!-- Theme index -->
  <link rel="stylesheet" href="index.css" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

  <!-- CSS phụ trợ nhỏ: badge & icon cho card trắc nghiệm -->
  <style>
    .lesson-card 
    .lesson-icon { 
        background: rgba(29,53,87,0.08); 
        border: 1px solid rgba(29,53,87,0.14); 
        color: var(--primary); 
    }
    .quiz-badge{
      display:inline-flex;
      align-items:center;
      gap:8px;
      font-size: 12px;
      font-weight: 800;
      color: var(--primary);
      background: rgba(29,53,87,0.08);
      border: 1px solid rgba(29,53,87,0.14);
      padding: 6px 10px;
      border-radius: 999px;
      margin-top: 10px;
    }
    .lesson-content h3{ line-height: 1.35; }
  </style>
</head>

<body>
  <?php include_once 'includes/header.php'; ?>

  <section class="topics-section">
    <div class="container">

      <div class="section-header">
        <h2>Trắc nghiệm theo bài học</h2>
        <p>
          Luyện tập theo từng bài học — <strong><?php echo (int)$total_topics; ?></strong> chủ đề và
          <strong><?php echo (int)$total_lessons; ?></strong> bài.
        </p>
      </div>

      <!-- Filter -->
      <div class="topics-filter">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input id="quizSearch" type="text" placeholder="Tìm bài để làm trắc nghiệm..." autocomplete="off">
        </div>

        <div class="topic-pills" id="topicPills">
          <button class="pill active" type="button" data-topic="all">
            <i class="fas fa-layer-group"></i> Tất cả
          </button>

          <?php foreach ($topics as $t): ?>
            <button class="pill" type="button" data-topic="<?php echo (int)$t['topic_id']; ?>">
              <i class="fas fa-bookmark"></i>
              Chủ đề <?php echo (int)$t['topic_id']; ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Grid -->
      <?php if (empty($lessons_flat)): ?>
        <div class="lessons-grid">
          <div class="lesson-card">
            <div class="lesson-icon"><i class="fas fa-circle-info"></i></div>
            <div class="lesson-content">
              <h3>Chưa có bài học</h3>
              <p class="lesson-pages">Database chưa có dữ liệu bài học để làm trắc nghiệm.</p>
              <div class="lesson-topic">Đang cập nhật</div>
            </div>
            <div class="lesson-actions">
              <a href="index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Về trang chủ</a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="lessons-grid" id="lessonsGrid">
          <?php foreach ($lessons_flat as $lesson): ?>
            <?php
              $topic_id  = (int)$lesson['topic_id'];
              $lesson_id = (int)$lesson['lesson_id'];
              $titleLower = function_exists('mb_strtolower')
                ? mb_strtolower((string)$lesson['lesson_title'], 'UTF-8')
                : strtolower((string)$lesson['lesson_title']);
            ?>
            <div class="lesson-card"
                 data-topic="<?php echo $topic_id; ?>"
                 data-title="<?php echo h($titleLower); ?>">
              <div class="lesson-icon"><i class="fas fa-clipboard-list"></i></div>

              <div class="lesson-content">
                <h3><?php echo h($lesson['lesson_title']); ?></h3>

                <div class="lesson-pages">
                  <i class="fas fa-book"></i>
                  Trang <?php echo h($lesson['page_start']); ?> - <?php echo h($lesson['page_end']); ?>
                </div>

                <div class="quiz-badge">
                  <i class="fas fa-layer-group"></i> Chủ đề <?php echo $topic_id; ?> · Bài <?php echo $lesson_id; ?>
                </div>
              </div>

              <div class="lesson-actions">
                <a class="btn btn-primary" href="trac-nghiem-dong.php?lesson=<?php echo $lesson_id; ?>">
                  <i class="fas fa-play"></i> Làm trắc nghiệm
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </section>

  <!-- Footer giống index.php -->
  <footer class="footer-simple">
    <div class="container">
      <div class="footer-content-simple">
        <div class="footer-brand-simple">
          <div class="brand-logo-simple">
            <i class="fas fa-graduation-cap"></i>
            <span>Lịch Sử 12</span>
          </div>
          <p>Ôn thi THPT Quốc gia môn Lịch sử</p>
        </div>

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

  <script>
    (function(){
      const pills = document.querySelectorAll('#topicPills .pill');
      const search = document.getElementById('quizSearch');
      const cards  = document.querySelectorAll('#lessonsGrid .lesson-card');

      if (!cards.length) return;

      let activeTopic = 'all';

      function applyFilter(){
        const q = (search.value || '').trim().toLowerCase();

        cards.forEach(card => {
          const topic = card.getAttribute('data-topic');
          const title = card.getAttribute('data-title') || '';
          const okTopic = (activeTopic === 'all') || (topic === activeTopic);
          const okText  = (!q) || title.includes(q);

          card.style.display = (okTopic && okText) ? '' : 'none';
        });
      }

      pills.forEach(btn => {
        btn.addEventListener('click', () => {
          pills.forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          activeTopic = btn.getAttribute('data-topic');
          applyFilter();
        });
      });

      search.addEventListener('input', applyFilter);
    })();
  </script>

  <script src="script.js"></script>
</body>
</html>
