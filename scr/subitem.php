<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

$item_id = isset($_GET['item']) ? (int)$_GET['item'] : 1;
$conn = getDBConnection();

/* Item info */
$item_sql = "SELECT 
    i.item_id,
    i.item_title,
    i.page_start as item_page_start,
    i.page_end as item_page_end,
    l.lesson_id,
    l.lesson_title,
    l.page_start as lesson_page_start,
    l.page_end as lesson_page_end,
    t.topic_name
FROM items i
JOIN lessons l ON i.lesson_id = l.lesson_id
JOIN topics t ON l.topic_id = t.topic_id
WHERE i.item_id = ?";

$item_stmt = $conn->prepare($item_sql);
$item_stmt->bind_param("i", $item_id);
$item_stmt->execute();
$item_info = $item_stmt->get_result()->fetch_assoc();

if (!$item_info) {
    $conn->close();
    die("Không tìm thấy mục nội dung!");
}

/* Subitems */
$subitems_sql = "SELECT 
    si.subitem_id,
    si.subitem_label,
    si.subitem_content,
    si.page_number,
    si.image_url,
    si.image_url_1
FROM subitems si
WHERE si.item_id = ?
ORDER BY si.subitem_id";

$subitems_stmt = $conn->prepare($subitems_sql);
$subitems_stmt->bind_param("i", $item_id);
$subitems_stmt->execute();
$subitems = $subitems_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$conn->close();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$total_subitems = count($subitems);

// Title cắt gọn cho đẹp (không phá chữ Việt)
function truncate_vi($raw, $max = 110) {
    $raw = (string)$raw;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return (mb_strlen($raw, 'UTF-8') > $max) ? (mb_substr($raw, 0, $max, 'UTF-8') . '...') : $raw;
    }
    return (strlen($raw) > $max) ? (substr($raw, 0, $max) . '...') : $raw;
}

/* CHỈ THÊM: link làm bài trắc nghiệm theo lesson hiện tại */
$quiz_url = 'trac-nghiem-dong.php?lesson=' . (int)$item_info['lesson_id'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo h($item_info['item_title']); ?> - Bài <?php echo (int)$item_info['lesson_id']; ?>: <?php echo h($item_info['lesson_title']); ?></title>

  <!-- Fonts giống index -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">

  <!-- Theme index -->
  <link rel="stylesheet" href="index.css" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

  <!-- CSS phụ trợ: chỉ để trình bày dễ đọc (không đổi cấu trúc hệ theme) -->
  <style>
    /* Giữ nội dung dễ đọc, không quá rộng */
    .reading-wrap{
      max-width: 980px;
      margin: 0 auto;
    }

    /* Header “dễ đọc” kiểu bạn thích */
    .reader-hero{
      border-radius: 16px;
      border: 1px solid rgba(29,53,87,0.14);
      box-shadow: var(--shadow-soft);
      overflow: hidden;
      background: #fff;
    }
    .reader-hero__top{
      background: var(--primary);
      color: #fff;
      padding: 20px 18px;
      min-height: auto;
      height: auto;
    }
    .reader-hero__meta{
      display:flex;
      flex-wrap: wrap;
      gap: 12px 16px;
      font-weight: 700;
      opacity: .95;
      font-size: 14px;
      align-items: center;
    }
    .reader-hero__meta span{
      display:inline-flex;
      gap: 8px;
      align-items:center;
    }
    .reader-hero__title{
      margin-top: 10px;
      font-family: "Merriweather", serif;
      font-weight: 900;
      font-size: 20px;
      line-height: 1.5;
      
      /* Đảm bảo chữ hiển thị đầy đủ */
      white-space: normal;
      overflow: visible;
      text-overflow: unset;
      height: auto;
      max-height: none;
      
      /* Tránh bị cắt chân chữ */
      padding-bottom: 8px;
      
      /* Bẻ từ nếu quá dài */
      word-break: break-word;
      overflow-wrap: anywhere;
      
      /* Đảm bảo không bị ẩn */
      display: block;
      visibility: visible;
    }

    .reader-hero__bottom{
      padding: 14px 18px;
      color: var(--muted);
      font-weight: 600;
      display:flex;
      flex-wrap: wrap;
      gap: 10px 14px;
      align-items:center;
      background: rgba(29,53,87,0.03);
      border-top: 1px solid rgba(29,53,87,0.08);
    }

    /* Subitem card: dễ đọc, rõ khối */
    .subitem-block{
      margin-top: 14px;
      border-radius: 16px;
      border: 1px solid rgba(29,53,87,0.14);
      box-shadow: var(--shadow-soft);
      background: #fff;
      overflow: hidden;
    }
    .subitem-block__bar{
      background: rgba(29,53,87,0.06);
      border-bottom: 1px solid rgba(29,53,87,0.10);
      padding: 12px 16px;
      display:flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
    }
    .subitem-title{
      display:flex;
      gap: 10px;
      align-items: center;
      font-weight: 900;
      color: var(--ink);
    }
    .subitem-title i{
      color: var(--primary);
    }
    .subitem-pill{
      display:inline-flex;
      gap: 8px;
      align-items:center;
      font-weight: 800;
      font-size: 12px;
      color: var(--primary);
      background: rgba(29,53,87,0.08);
      border: 1px solid rgba(29,53,87,0.14);
      padding: 6px 10px;
      border-radius: 999px;
      white-space: nowrap;
    }

    .subitem-body{
      padding: 14px 16px 16px;
    }

    /* Gallery */
    .image-gallery{
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 12px;
      margin-top: 10px;
    }
    .image-gallery img{
      width: 100%;
      height: auto;
      border-radius: 14px;
      border: 1px solid rgba(29,53,87,0.14);
      box-shadow: var(--shadow-soft);
      background: #fff;
    }

    /* Answer */
    .answer-head{
      margin-top: 14px;
      display:flex;
      gap: 10px;
      align-items: center;
      font-weight: 900;
      color: #c62828;
      font-size: 19px;
    }
    .answer-box{
      margin-top: 10px;
      padding: 14px 14px;
      border-radius: 14px;
      border: 1px solid rgba(29,53,87,0.14);
      background: rgba(29,53,87,0.04);
      color: var(--ink);
      font-weight: 600;
      line-height: 1.8;
      font-size: 15px;
    }
    .answer-box ul, .answer-box ol { padding-left: 20px; }
    .answer-box li { margin: 6px 0; }

    /* Breadcrumb pills: bỏ gạch chân */
    .topic-pills a, .topic-pills a:visited { text-decoration: none; }

    /* Mobile responsive */
    @media (max-width: 768px){
      .reader-hero__title{ 
        font-size: 18px; 
        line-height: 1.4;
        padding-bottom: 6px;
      }
      .answer-box{ font-size: 14px; }
      .reader-hero__top{
        padding: 16px 14px;
      }
    }
  </style>
</head>

<body>
  <?php include_once 'includes/header.php'; ?>

  <section class="topics-section">
    <div class="container">
      <div class="reading-wrap">

        <!-- Hero: dễ đọc như ảnh -->
        <div class="reader-hero">
          <div class="reader-hero__top">
            <div class="reader-hero__meta">
              <span><i class="fas fa-layer-group"></i> <?php echo h($item_info['topic_name']); ?></span>
              <span> <?php echo h($item_info['lesson_title']); ?></span>
              <span><i class="fas fa-file-alt"></i> Trang <?php echo (int)$item_info['item_page_start']; ?> - <?php echo (int)$item_info['item_page_end']; ?></span>
            </div>

            <div class="reader-hero__title">
              <?php echo h($item_info['item_title']); ?>
            </div>
          </div>

        <?php if (empty($subitems)): ?>
          <div class="subitem-block" style="margin-top:14px;">
            <div class="subitem-body">
              <div class="answer-head"><i class="fas fa-circle-info"></i> Chưa có dữ liệu</div>
              <div class="answer-box">Item này hiện chưa có phần nội dung (subitem).</div>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($subitems as $subitem): ?>
            <?php
              $label = trim((string)($subitem['subitem_label'] ?? ''));
              $page  = $subitem['page_number'] ?? '';
              $img1  = trim((string)($subitem['image_url'] ?? ''));
              $img2  = trim((string)($subitem['image_url_1'] ?? ''));
              $content = trim((string)($subitem['subitem_content'] ?? ''));
            ?>

              <div class="subitem-body">
                <?php if ($img1 !== '' || $img2 !== ''): ?>
                  <div class="image-gallery">
                    <?php if ($img1 !== ''): ?>
                      <img src="<?php echo h($img1); ?>" alt="Hình minh họa 1">
                    <?php endif; ?>
                    <?php if ($img2 !== ''): ?>
                      <img src="<?php echo h($img2); ?>" alt="Hình minh họa 2">
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <div class="answer-head">Nội dung</div>

                <?php if ($content !== ''): ?>
                  <div class="answer-box">
                    <?php echo nl2br(h($content)); ?>
                  </div>
                <?php else: ?>
                  <div class="answer-box">Chưa có đáp án/nội dung cho phần này.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; width:100%;">
  <a class="btn btn-outline" href="item.php?lesson=<?php echo (int)$item_info['lesson_id']; ?>">
    Quay lại bài
  </a>

  <a class="btn btn-outline" href="<?php echo $quiz_url; ?>">
    Làm bài trắc nghiệm
  </a>
</div>


      </div>
    </div>
  </section>

  <!-- Footer giống index -->
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
</body>
</html>
