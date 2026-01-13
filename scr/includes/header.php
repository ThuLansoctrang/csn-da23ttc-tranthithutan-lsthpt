<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? 'User',
            'email' => $_SESSION['email'] ?? '',
            'role' => $_SESSION['role'] ?? 'student'
        ];
    }
    return null;
}

function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

$currentUser = getCurrentUser();
?>
<header class="site-header">
  <nav class="nav">
    <div class="nav__container">
      <!-- Brand -->
      <a class="brand" href="index.php" aria-label="Trang chủ Lịch Sử 12">
        <span class="brand__icon" aria-hidden="true">
          <i class="fas fa-graduation-cap"></i>
        </span>
        <span class="brand__text">
          <span class="brand__title">Lịch Sử 12</span>
          <span class="brand__subtitle">Ôn Thi THPT Lịch Sử</span>
        </span>
      </a>

      <!-- Mobile toggle -->
      <div class="nav__actions">
        <button class="nav__toggle" id="navToggle" aria-label="Mở menu" aria-expanded="false" aria-controls="navMenu" type="button">
          <span></span><span></span><span></span>
        </button>
      </div>

      <!-- Menu -->
      <div class="nav__menu" id="navMenu">
        <a class="nav__link" href="index.php">
          <i class="fas fa-home"></i><span>Trang chủ</span>
        </a>

        <a class="nav__link" href="hoc-theo-chu-de.php">
          <i class="fas fa-book"></i><span>Học theo chủ đề</span>
        </a>

        <a class="nav__link" href="trac-nghiem.php">
          <i class="fas fa-pen-to-square"></i><span>Trắc nghiệm</span>
        </a>
  
        <?php if (isLoggedIn()): ?>
          <a class="nav__link" href="thong-ke.php">
            <i class="fas fa-chart-column"></i><span>Thống kê</span>
          </a>
        <?php endif; ?>

        <?php if (isAdmin()): ?>
          <a class="nav__link nav__link--pill" href="admin-simple.php" title="Khu vực quản trị">
            <i class="fas fa-shield-halved"></i><span>Admin</span>
          </a>
        <?php endif; ?>

        <?php if ($currentUser): ?>
          <div class="nav__divider" aria-hidden="true"></div>

          <div class="user" id="userMenu">
            <button class="user__btn" type="button" aria-haspopup="menu" aria-expanded="false">
              <span class="user__avatar" aria-hidden="true">
                <?php echo strtoupper(mb_substr($currentUser['username'], 0, 1, 'UTF-8')); ?>
              </span>

              <span class="user__meta">
                <span class="user__name"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                <span class="user__role"><?php echo isAdmin() ? 'Admin' : 'Thành viên'; ?></span>
              </span>

              <i class="fas fa-chevron-down user__chev" aria-hidden="true"></i>
            </button>

            <div class="user__dropdown" role="menu" aria-label="Tài khoản">
              <div class="user__dropdownHeader">
                <div class="user__dropdownAvatar" aria-hidden="true">
                  <?php echo strtoupper(mb_substr($currentUser['username'], 0, 1, 'UTF-8')); ?>
                </div>
                <div class="user__dropdownText">
                  <div class="user__dropdownName"><?php echo htmlspecialchars($currentUser['username']); ?></div>
                  <div class="user__dropdownBadge"><?php echo isAdmin() ? 'Admin' : 'Thành viên'; ?></div>
                </div>
              </div>

              <div class="user__dropdownList">
                <?php if (isAdmin()): ?>
                  <a class="dropdown__item dropdown__item--danger" role="menuitem" href="admin-simple.php">
                    <i class="fas fa-cogs"></i><span>Admin System</span>
                  </a>
                <?php endif; ?>

                <a class="dropdown__item dropdown__item--danger" role="menuitem" href="auth/logout.php">
                  <i class="fas fa-right-from-bracket"></i><span>Đăng xuất</span>
                </a>
              </div>
            </div>
          </div>

        <?php else: ?>
          <div class="nav__divider" aria-hidden="true"></div>
          <a class="nav__cta" href="login_page.php">
            <i class="fas fa-right-to-bracket"></i><span>Đăng nhập</span>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </nav>
</header>

<!-- Load main script -->
<script src="script.js"></script>
<link rel="stylesheet" href="index.css">

<style>
/* Override any conflicting CSS */
.user__dropdown {
  display: block !important;
  opacity: 0 !important;
  visibility: hidden !important;
  pointer-events: none !important;
  position: absolute !important;
  top: calc(100% + 8px) !important;
  right: 0 !important;
  z-index: 999999 !important;
  transform: translateY(-10px) !important;
  transition: all 0.2s ease !important;
  background: white !important;
  border: 1px solid rgba(29,53,87,.14) !important;
  border-radius: 12px !important;
  box-shadow: 0 10px 30px rgba(0,0,0,.15) !important;
  min-width: 220px !important;
  overflow: hidden !important;
}

.user.is-open .user__dropdown {
  opacity: 1 !important;
  visibility: visible !important;
  pointer-events: auto !important;
  transform: translateY(0) !important;
}

.dropdown__item {
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  padding: 12px 16px !important;
  text-decoration: none !important;
  color: #0f172a !important;
  cursor: pointer !important;
  border-radius: 8px !important;
  margin: 4px 8px !important;
  font-weight: 600 !important;
  font-size: 14px !important;
  transition: background 0.1s ease !important;
}

.dropdown__item:hover {
  background: rgba(29,53,87,.08) !important;
  color: #0f172a !important;
}

.dropdown__item--danger {
  color: #dc3545 !important;
}

.dropdown__item--danger:hover {
  background: rgba(220,53,69,.1) !important;
  color: #dc3545 !important;
}

.dropdown__item i {
  width: 16px !important;
  font-size: 14px !important;
  text-align: center !important;
}

.user__dropdownHeader {
  padding: 16px !important;
  background: rgba(29,53,87,.04) !important;
  border-bottom: 1px solid rgba(29,53,87,.08) !important;
  display: flex !important;
  align-items: center !important;
  gap: 12px !important;
}

.user__dropdownList {
  padding: 8px !important;
}
</style>