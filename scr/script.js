/* =========================
   NAV + USER DROPDOWN (NEW HEADER)
   ========================= */
(function () {
  const navToggle = document.getElementById('navToggle');
  const navMenu = document.getElementById('navMenu');

  const userMenu = document.getElementById('userMenu');
  const userBtn = userMenu ? userMenu.querySelector('.user__btn') : null;

  function closeNav() {
    if (navMenu) navMenu.classList.remove('is-open');
    if (navToggle) navToggle.setAttribute('aria-expanded', 'false');
  }

  function closeUser() {
    if (userMenu) userMenu.classList.remove('is-open');
    if (userBtn) userBtn.setAttribute('aria-expanded', 'false');
  }
    window.closeNav = closeNav;
    window.closeUser = closeUser;
  // Mobile menu
  if (navToggle && navMenu) {
    navToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = navMenu.classList.toggle('is-open');
      navToggle.setAttribute('aria-expanded', String(open));
      // đóng dropdown user nếu đang mở
      closeUser();
    });
  }

  // User dropdown
  if (userMenu && userBtn) {
    userBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = userMenu.classList.toggle('is-open');
      userBtn.setAttribute('aria-expanded', String(open));
      // đóng nav mobile nếu đang mở
      closeNav();
    });
  }

  // Click outside to close (FIX: chỉ đóng khi click RA NGOÀI)
  document.addEventListener('click', (e) => {
    // đóng nav nếu click ngoài navMenu và ngoài nút toggle
    if (navMenu && navToggle && !navMenu.contains(e.target) && !navToggle.contains(e.target)) {
      closeNav();
    }

    // đóng user dropdown nếu click ngoài userMenu
    if (userMenu && !userMenu.contains(e.target)) {
      closeUser();
    }
  });

  // ESC to close
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeNav();
      closeUser();
    }
  });

  // Close on resize (avoid stuck)
  window.addEventListener('resize', () => {
    closeNav();
  });
})();

/* =========================
   KEEP YOUR EXISTING LOGIC (SAFE GUARDS)
   (nếu trang khác cần, vẫn chạy)
   ========================= */
document.addEventListener('DOMContentLoaded', function () {
  // Smooth scroll (nếu có anchor)
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      const href = this.getAttribute('href');
      if (!href || href === '#') return;
      const target = document.querySelector(href);
      if (!target) return;
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  // ===== LƯU KẾT QUẢ TRẮC NGHIỆM =====
  // Chỉ lưu kết quả trắc nghiệm, KHÔNG lưu tiến độ học bài
  window.saveQuizResult = function (quizName, score, totalQuestions) {
    try {
      const formData = new FormData();
      formData.append('quiz_name', quizName);
      formData.append('score', score);
      formData.append('total_questions', totalQuestions);

      fetch('save_quiz_result.php', {
        method: 'POST',
        body: formData
      })
        .then(response => response.json())
        .then(data => {
          if (data && data.success) {
            console.log('✅ Đã lưu kết quả:', quizName, '-', data.percentage + '%');
          }
        })
        .catch(error => console.error('Lỗi lưu kết quả:', error));
    } catch (err) {
      console.error('Lỗi saveQuizResult:', err);
    }
  };
});

