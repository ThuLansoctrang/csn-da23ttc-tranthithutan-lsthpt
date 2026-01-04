<?php
// Bắt đầu session trước khi có output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

// Lấy lesson_id từ URL
$lesson_id = isset($_GET['lesson']) ? (int)$_GET['lesson'] : 1;

$conn = getDBConnection();

// Lấy thông tin lesson
$lesson_sql = "SELECT 
    l.lesson_id,
    l.lesson_title,
    l.page_start as lesson_page_start,
    l.page_end as lesson_page_end,
    t.topic_name,
    b.book_name,
    b.subject,
    b.grade
FROM lessons l
JOIN topics t ON l.topic_id = t.topic_id
JOIN books b ON t.book_id = b.book_id
WHERE l.lesson_id = ?";

$lesson_stmt = $conn->prepare($lesson_sql);
$lesson_stmt->bind_param("i", $lesson_id);
$lesson_stmt->execute();
$lesson_result = $lesson_stmt->get_result();
$lesson_info = $lesson_result->fetch_assoc();

if (!$lesson_info) {
    $conn->close();
    die("Không tìm thấy bài học!");
}

// Lấy danh sách items của lesson này
$items_sql = "SELECT 
    item_id,
    item_title,
    page_start,
    page_end
FROM items 
WHERE lesson_id = ?
ORDER BY item_id";

$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("i", $lesson_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = $items_result->fetch_all(MYSQLI_ASSOC);

// Lấy danh sách tất cả lessons của topic này để navigation
$nav_sql = "SELECT lesson_id, lesson_title
            FROM lessons
            WHERE topic_id = (SELECT topic_id FROM lessons WHERE lesson_id = ?)
            ORDER BY lesson_id";
$nav_stmt = $conn->prepare($nav_sql);
$nav_stmt->bind_param("i", $lesson_id);
$nav_stmt->execute();
$nav_result = $nav_stmt->get_result();
$nav_lessons = $nav_result->fetch_all(MYSQLI_ASSOC);

// ===== Prev / Next lesson (fix undefined variable) =====
$prev_lesson = null;
$next_lesson = null;

if (!empty($nav_lessons)) {
    $currentIndex = -1;

    foreach ($nav_lessons as $i => $nl) {
        if ((int)$nl['lesson_id'] === (int)$lesson_id) {
            $currentIndex = $i;
            break;
        }
    }

    if ($currentIndex > 0) {
        $prev_lesson = $nav_lessons[$currentIndex - 1];
    }

    if ($currentIndex !== -1 && $currentIndex < count($nav_lessons) - 1) {
        $next_lesson = $nav_lessons[$currentIndex + 1];
    }
}

$conn->close();

// Helper: lower title for search (safe)
function to_lower_safe($s) {
    $s = (string)$s;
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

// Helper: truncate title safely then escape
function truncate_and_escape($raw, $max = 120) {
    $raw = (string)$raw;

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        $short = (mb_strlen($raw, 'UTF-8') > $max) ? (mb_substr($raw, 0, $max, 'UTF-8') . '...') : $raw;
    } else {
        $short = (strlen($raw) > $max) ? (substr($raw, 0, $max) . '...') : $raw;
    }

    return htmlspecialchars($short);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Giải <?php echo htmlspecialchars($lesson_info['subject']); ?> - <?php echo htmlspecialchars($lesson_info['lesson_title']); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="index.css" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>

<body>
  <?php include_once 'includes/header.php'; ?>

  <section class="topics-section">
    <div class="container">
      <div class="section-header">
        <h2> <?php echo htmlspecialchars($lesson_info['lesson_title']); ?></h2>
        <p>
          <?php echo htmlspecialchars($lesson_info['book_name']); ?> · <?php echo htmlspecialchars($lesson_info['topic_name']); ?>
          <?php if (!empty($lesson_info['lesson_page_start']) && !empty($lesson_info['lesson_page_end'])): ?>
            · Trang <?php echo (int)$lesson_info['lesson_page_start']; ?> - <?php echo (int)$lesson_info['lesson_page_end']; ?>
          <?php endif; ?>
        </p>
      </div>

      <!-- Breadcrumb + Quick nav -->
      <div class="topics-filter">


        <div class="search-box">
          <i class="fas fa-search"></i>
          <input id="itemSearch" type="text" placeholder="Tìm câu hỏi trong bài..." autocomplete="off" />
        </div>
      </div>

      <!-- Lesson nav pills -->
      <?php if (!empty($nav_lessons)): ?>
        <div class="topic-pills" style="justify-content:flex-start; margin-top:12px;">
          <?php foreach ($nav_lessons as $nav_lesson): ?>
            <a
              class="pill <?php echo ((int)$nav_lesson['lesson_id'] === (int)$lesson_id) ? 'active' : ''; ?>"
              href="item.php?lesson=<?php echo (int)$nav_lesson['lesson_id']; ?>"
              title="<?php echo htmlspecialchars($nav_lesson['lesson_title']); ?>"
            >
              Bài <?php echo (int)$nav_lesson['lesson_id']; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Items grid -->
      <div class="lessons-grid" id="itemsGrid">
        <?php if (!empty($items)): ?>
          <?php foreach ($items as $item): ?>
            <?php
              $rawTitle = $item['item_title'] ?? '';
              $titleLower = to_lower_safe($rawTitle);
            ?>
            <div class="lesson-card" data-title="<?php echo htmlspecialchars($titleLower); ?>">
              <div class="lesson-icon"><i class="fas fa-circle-question"></i></div>

              <div class="lesson-content">
                <h3><?php echo truncate_and_escape($rawTitle, 120); ?></h3>

                <?php if (!empty($item['page_start']) && !empty($item['page_end'])): ?>
                  <div class="lesson-pages">
                    <i class="fas fa-book-open"></i>
                    Trang <?php echo (int)$item['page_start']; ?> - <?php echo (int)$item['page_end']; ?>
                  </div>
                <?php endif; ?>

                <div class="lesson-topic">
                  <i class="fas fa-hashtag"></i> Mã câu hỏi: <?php echo (int)$item['item_id']; ?>
                </div>
              </div>

              <div class="lesson-actions">
                <a href="subitem.php?item=<?php echo (int)$item['item_id']; ?>" class="btn btn-primary question-link">
                  <i class="fas fa-play"></i> Xem chi tiết
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="feature-card" style="grid-column:1/-1;">
            <div class="feature-icon"><i class="fas fa-circle-info"></i></div>
            <h3>Chưa có dữ liệu câu hỏi</h3>
            <p>Bài này hiện chưa có câu hỏi/đề mục để hiển thị.</p>
            <a class="feature-link" href="hoc-theo-chu-de.php">Quay lại danh sách bài học <i class="fas fa-arrow-right"></i></a>
          </div>
        <?php endif; ?>
      </div>
      
    </div>
  </section>

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
    // search filter
    document.addEventListener('DOMContentLoaded', function () {
      const input = document.getElementById('itemSearch');
      const cards = Array.from(document.querySelectorAll('#itemsGrid .lesson-card'));

      if (input) {
        input.addEventListener('input', function () {
          const q = (input.value || '').trim().toLowerCase();
          cards.forEach(card => {
            const title = (card.getAttribute('data-title') || '');
            card.style.display = title.includes(q) ? '' : 'none';
          });
        });
      }

      // click animation
      const links = document.querySelectorAll('.question-link');
      links.forEach(link => {
        link.addEventListener('click', function () {
          this.style.transform = 'scale(0.98)';
          setTimeout(() => { this.style.transform = 'scale(1)'; }, 150);
        });
      });
    });
  </script>
</body>
</html>
