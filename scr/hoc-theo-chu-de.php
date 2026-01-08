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
$data = $result->fetch_all(MYSQLI_ASSOC);

// Tổ chức dữ liệu theo chủ đề + danh sách bài học phẳng (để render dạng card)
$topics = [];
$lessons_flat = [];

foreach ($data as $row) {
    $topic_id = $row['topic_id'];

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

// Tính tổng số bài học
$total_lessons = count($lessons_flat);
$total_topics  = count($topics);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Học Theo Chủ Đề - Học Lịch Sử Lớp 12</title>

  <!-- Fonts giống index.php -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">

  <!-- CSS dùng chung theme -->
  <link rel="stylesheet" href="index.css" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>

<body>
  <?php include_once 'includes/header.php'; ?>

  <section class="topics-section">
    <div class="container">

      <div class="section-header">
        <h2>Học theo chủ đề</h2>
        <p>Chương trình Lịch sử lớp 12 gồm <strong><?php echo $total_topics; ?></strong> chủ đề với <strong><?php echo $total_lessons; ?></strong> bài học.</p>
      </div>

      <div class="topics-filter">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input id="topicSearch" type="text" placeholder="Tìm bài học theo tiêu đề..." autocomplete="off">
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

      <?php if (empty($lessons_flat)): ?>
        <div class="lessons-grid">
          <div class="lesson-card">
            <div class="lesson-icon"><i class="fas fa-circle-info"></i></div>
            <div class="lesson-content">
              <h3>Chưa có bài học</h3>
              <p class="lesson-pages">Database hiện chưa có dữ liệu bài học.</p>
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
              $lesson_id = (int)$lesson['lesson_id'];
              $topic_id  = (int)$lesson['topic_id'];

              // giữ logic link cũ
              $href = "item.php?lesson={$lesson_id}";

            ?>
            <div class="lesson-card"
                 data-topic="<?php echo $topic_id; ?>"
                 data-title="<?php echo htmlspecialchars(function_exists('mb_strtolower') ? mb_strtolower($lesson['lesson_title'], 'UTF-8') : strtolower($lesson['lesson_title'])); ?>">
              <div class="lesson-icon"><i class="fas fa-book-open"></i></div>

              <div class="lesson-content">
                <h3><?php echo htmlspecialchars($lesson['lesson_title']); ?></h3>
                <div class="lesson-pages">
                  <i class="fas fa-book"></i>
                  Trang <?php echo htmlspecialchars($lesson['page_start']); ?> - <?php echo htmlspecialchars($lesson['page_end']); ?>
                </div>
                <div class="lesson-topic">
                  Chủ đề <?php echo $topic_id; ?>
                </div>
              </div>

              <div class="lesson-actions">
                <a class="btn btn-primary" href="<?php echo $href; ?>">
                  <i class="fas fa-play"></i> Học bài
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      

    </div>
  </section>

  <!-- FOOTER giống index.php để đồng bộ giao diện -->
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
    // Filter UI: pill + search (nhẹ, chạy thuần JS)
    (function(){
      const pills = document.querySelectorAll('#topicPills .pill');
      const search = document.getElementById('topicSearch');
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
