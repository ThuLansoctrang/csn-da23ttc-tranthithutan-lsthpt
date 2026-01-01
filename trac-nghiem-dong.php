<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'config/database.php';

$conn = getDBConnection();

// Bạn có thể truyền ?lesson=1 hoặc ?item=12
$lesson_id = isset($_GET['lesson']) ? (int)$_GET['lesson'] : 0;
$item_id   = isset($_GET['item']) ? (int)$_GET['item'] : 0;

// Lấy title cho đẹp (optional)
$page_title = "Trắc nghiệm Lịch sử 12";
$meta_line  = "";

if ($lesson_id > 0) {
  $ls = $conn->prepare("SELECT lesson_id, lesson_title, page_start, page_end FROM lessons WHERE lesson_id=?");
  $ls->bind_param("i", $lesson_id);
  $ls->execute();
  $lesson = $ls->get_result()->fetch_assoc();
  if ($lesson) {
    $page_title = "Trắc nghiệm: Bài {$lesson['lesson_id']}. {$lesson['lesson_title']}";
    $meta_line  = " Trang {$lesson['page_start']} - {$lesson['page_end']}";
  }
}

// ====== Query lấy questions + options ======
$rows = [];
if ($item_id > 0) {
  $sql = "
    SELECT 
      q.question_id,
      q.subitem_id,
      q.question_content,
      q.explanation,
      GROUP_CONCAT(CONCAT(o.option_id,'||',o.option_content,'||',o.is_correct) ORDER BY o.option_id SEPARATOR '###') AS options_data
    FROM questions q
    JOIN subitems si ON q.subitem_id = si.subitem_id
    LEFT JOIN options o ON o.question_id = q.question_id
    WHERE si.item_id = ?
    GROUP BY q.question_id
    ORDER BY q.subitem_id, q.question_id
  ";
  $st = $conn->prepare($sql);
  $st->bind_param("i", $item_id);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

} elseif ($lesson_id > 0) {
  $sql = "
    SELECT 
      q.question_id,
      q.subitem_id,
      q.question_content,
      q.explanation,
      GROUP_CONCAT(CONCAT(o.option_id,'||',o.option_content,'||',o.is_correct) ORDER BY o.option_id SEPARATOR '###') AS options_data
    FROM questions q
    JOIN subitems si ON q.subitem_id = si.subitem_id
    JOIN items i ON si.item_id = i.item_id
    LEFT JOIN options o ON o.question_id = q.question_id
    WHERE i.lesson_id = ?
    GROUP BY q.question_id
    ORDER BY i.item_id, q.subitem_id, q.question_id
  ";
  $st = $conn->prepare($sql);
  $st->bind_param("i", $lesson_id);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

} else {
  $sql = "
    SELECT 
      q.question_id,
      q.subitem_id,
      q.question_content,
      q.explanation,
      GROUP_CONCAT(CONCAT(o.option_id,'||',o.option_content,'||',o.is_correct) ORDER BY o.option_id SEPARATOR '###') AS options_data
    FROM questions q
    LEFT JOIN options o ON o.question_id = q.question_id
    GROUP BY q.question_id
    ORDER BY q.question_id
  ";
  $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

$conn->close();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Parse data -> quizData JS
$quizData = [];
foreach ($rows as $r) {
  $opts = [];
  $options_data = $r['options_data'] ?? '';
  if ($options_data) {
    $parts = explode('###', $options_data);
    foreach ($parts as $p) {
      $pp = explode('||', $p);
      if (count($pp) >= 3) {
        $opts[] = [
          "t" => $pp[1],
          "correct" => ((int)$pp[2] === 1)
        ];
      }
    }
  }

  if (count($opts) < 2) continue;

  $letters = range('A', 'Z');
  $finalOpts = [];
  foreach ($opts as $i => $o) {
    $finalOpts[] = [
      "k" => $letters[$i] ?? chr(65+$i),
      "t" => $o["t"],
      "correct" => $o["correct"]
    ];
  }

  $quizData[] = [
    "id" => (int)$r["question_id"],
    "q" => $r["question_content"],
    "opts" => $finalOpts,
    "explain" => $r["explanation"] ?? ""
  ];
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?php echo h($page_title); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="index.css" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

  <style>
    :root{
      --bg:#f3f6fb; --card:#fff; --ink:#0f172a; --muted:#64748b;
      --line:rgba(29,53,87,.14); --soft:rgba(29,53,87,.05);
      --primary:#1d3557; --focus:#1e5eff;
      --shadow-soft:0 6px 18px rgba(0,0,0,.05);
      --ok:#28a745; --bad:#dc3545; --warn:#f59e0b;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:Inter,system-ui;background:var(--bg);color:var(--ink);font-size:16px;line-height:1.55}
    .wrap{max-width:1180px;margin:18px auto;padding:0 14px}
    .topbar{
      background:var(--card);border:1px solid var(--line);border-radius:18px;
      box-shadow:var(--shadow-soft);padding:14px 16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap
    }
    .brand{display:flex;gap:12px;align-items:center;min-width:240px}
    .badge{width:44px;height:44px;border-radius:14px;background:var(--primary);color:#fff;font-weight:900;display:flex;align-items:center;justify-content:center}
    .brand h1{margin:0;font-size:16px;font-weight:900}
    .brand p{margin:2px 0 0;font-size:15px;color:var(--muted);font-weight:700}
    .topActions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .grid2{margin-top:14px;display:grid;grid-template-columns:1.35fr .65fr;gap:14px;align-items:start}
    @media(max-width:980px){.grid2{grid-template-columns:1fr}.side{order:-1}}
    .card{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow-soft);overflow:hidden}
    .hd{padding:14px 16px;background:var(--soft);border-bottom:1px solid rgba(29,53,87,.10)}
    .title{margin:0;font-family:Merriweather,serif;font-size:19px;font-weight:900}
    .sub{margin:6px 0 0;font-size:16px;color:var(--muted);font-weight:700;line-height:1.5}
    .bd{padding:14px 16px 16px}

    .qhead{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}
    .qno{font-weight:900;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.3px}
    .question{margin:6px 0 0;font-weight:900;font-size:20px;line-height:1.45;word-break:break-word;overflow-wrap:anywhere}

    .opts{display:grid;gap:10px;margin-top:12px}
    .opt{
      border:1px solid var(--line);border-radius:14px;padding:12px;
      display:flex;gap:12px;align-items:flex-start;cursor:pointer;background:#fff;
      transition:.12s;outline:none
    }
    .opt:hover{transform:translateY(-1px);border-color:rgba(29,53,87,.26)}
    .key{width:32px;height:32px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:900;color:var(--primary);background:rgba(29,53,87,.08);border:1px solid rgba(29,53,87,.14);flex:0 0 auto}
    .txt{font-weight:700;font-size:16px;line-height:1.6;flex:1 1 auto;min-width:0;word-break:break-word;overflow-wrap:anywhere}
    .opt.selected{border-color:var(--focus);box-shadow:0 0 0 3px rgba(30,94,255,.12)}
    .opt.correct{border-color:rgba(40,167,69,.6);background:rgba(40,167,69,.08)}
    .opt.wrong{border-color:rgba(220,53,69,.6);background:rgba(220,53,69,.06)}

    .feedback{margin-top:12px;padding:12px;border-radius:14px;border:1px dashed rgba(29,53,87,.18);background:rgba(29,53,87,.03);font-weight:700;font-size:15px;color:var(--muted);line-height:1.6}
    .btnrow{margin-top:12px;display:flex;gap:10px;flex-wrap:wrap}
    .btn{padding:10px 14px;border-radius:999px;border:1px solid var(--line);background:#fff;font-weight:800;cursor:pointer;transition:.12s;white-space:nowrap}
    .btn.primary{background:var(--primary);border-color:rgba(29,53,87,.35);color:#fff}
    .btn.danger{ font-size:17px;background:#fff;border-color:rgba(220,53,69,.4);color:var(--bad)}
    .btn.soft{background:rgba(29,53,87,.04)}
    .btn:disabled{opacity:.55;cursor:not-allowed;transform:none !important}

    .legend{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
    .tag{font-size:15px;font-weight:800;padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:var(--soft);white-space:nowrap}
    .tag.todo{opacity:.75}.tag.chosen{border-color:var(--focus)}
    .tag.ok{border-color:rgba(40,167,69,.5)}.tag.bad{border-color:rgba(220,53,69,.5)}
    .navGrid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
    .navBtn{height:42px;border-radius:12px;border:1px solid var(--line);background:#fff;font-weight:900;cursor:pointer;transition:.12s}
    .navBtn.active{border-color:var(--focus);box-shadow:0 0 0 3px rgba(30,94,255,.12)}
    .navBtn.todo{opacity:.8}
    .navBtn.chosen{border-color:var(--focus);background:rgba(30,94,255,.05)}
    .navBtn.ok{border-color:rgba(40,167,69,.55);background:rgba(40,167,69,.08)}
    .navBtn.bad{border-color:rgba(220,53,69,.55);background:rgba(220,53,69,.08)}

    .hidden{display:none !important}
    .resultHeader{margin-top:14px;padding:18px 16px;border-radius:18px;border:1px solid var(--line);background:var(--card);box-shadow:var(--shadow-soft)}
    .resultHeader h2{margin:0;font-family:Merriweather,serif;font-weight:900;font-size:22px}
    .resultHeader p{margin:8px 0 0;color:var(--muted);font-weight:700;line-height:1.6}
    .scoreRow{margin-top:12px;display:flex;gap:10px;flex-wrap:wrap}
    .scoreBox{display:inline-flex;align-items:center;gap:10px;padding:10px 12px;border-radius:14px;border:1px solid var(--line);background:#fff;font-weight:900;color:var(--primary)}
    .scoreBox.ok{border-color:rgba(40,167,69,.4);color:var(--ok);background:rgba(40,167,69,.06)}
    .scoreBox.bad{border-color:rgba(220,53,69,.4);color:var(--bad);background:rgba(220,53,69,.06)}
    .scoreBox.warn{border-color:rgba(245,158,11,.5);color:#b45309;background:rgba(245,158,11,.08)}
    .resultList{margin-top:14px;display:flex;flex-direction:column;gap:12px}
    .rItem{border:1px solid var(--line);border-radius:18px;overflow:hidden;background:#fff;box-shadow:var(--shadow-soft)}
    .rTop{padding:12px 14px;background:rgba(29,53,87,.04);border-bottom:1px solid rgba(29,53,87,.10);display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .rQno{font-weight:900;color:var(--muted);font-size:16px;text-transform:uppercase;letter-spacing:.3px}
    .rStatus{font-weight:900;font-size:12px;padding:8px 10px;border-radius:999px;border:1px solid var(--line);background:#fff;white-space:nowrap}
    .rStatus.ok{border-color:rgba(40,167,69,.45);color:var(--ok);background:rgba(40,167,69,.06)}
    .rStatus.bad{border-color:rgba(220,53,69,.45);color:var(--bad);background:rgba(220,53,69,.06)}
    .rStatus.todo{border-color:rgba(245,158,11,.55);color:#b45309;background:rgba(245,158,11,.08)}
    .rBody{padding:14px}
    .rQuestion{margin:0;font-weight:900;font-size:17px;line-height:1.55;word-break:break-word;overflow-wrap:anywhere}
    .choiceGrid{margin-top:12px;display:grid;gap:10px}
    .choiceLine{border:1px solid var(--line);border-radius:14px;padding:10px 12px;display:flex;gap:10px;align-items:flex-start;background:#fff}
    .choiceLine .label{flex:0 0 auto;font-weight:900;color:var(--primary);background:rgba(29,53,87,.08);border:1px solid rgba(29,53,87,.14);border-radius:10px;padding:4px 8px;min-width:44px;text-align:center}
    .choiceLine .text{font-weight:700;font-size:15.5px;line-height:1.6;flex:1 1 auto;min-width:0;word-break:break-word;overflow-wrap:anywhere}
    .youTag{color:var(--muted);font-weight:800;margin-left:6px}
    .choiceLine.bad{border-color:rgba(220,53,69,.5);background:rgba(220,53,69,.06)}
    .choiceLine.ok{border-color:rgba(40,167,69,.5);background:rgba(40,167,69,.06)}
    .explain{margin-top:12px;border-top:1px dashed rgba(29,53,87,.18);padding-top:12px;color:var(--muted);font-weight:700;line-height:1.7}
    .explain strong{color:var(--ink)}
  </style>
</head>

<body>
  <?php include_once 'includes/header.php'; ?>

<div class="wrap">

  <header class="topbar">
    <div class="brand">
      <div class="badge">LS</div>
      <div>
        <h1><?php echo h($page_title); ?></h1>
        <?php if ($meta_line): ?><p><?php echo h($meta_line); ?></p><?php endif; ?>
      </div>
    </div>

    <div class="topActions">
      <button class="btn danger" id="submitBtn" disabled title="Làm đủ câu mới được nộp bài" type="button">Nộp bài</button>
      <button class="btn soft hidden" id="backBtn" type="button">← Quay lại làm bài</button>
    </div>
  </header>

  <?php if (count($quizData) === 0): ?>
    <div class="card" style="margin-top:14px;">
      <div class="hd">
        <h2 class="title">Không có dữ liệu câu hỏi</h2>
        <p class="sub">Kiểm tra lại lesson/item hoặc bảng questions/options.</p>
      </div>
      <div class="bd">
        <div class="feedback">Không tìm thấy câu hỏi hợp lệ (cần ít nhất 2 đáp án).</div>
      </div>
    </div>
  <?php else: ?>

  <section id="quizView">
    <main class="grid2">
      <section class="card main">
        <div class="hd">
          <h2 class="title">Làm bài</h2>
          <p class="sub">Chọn đáp án → chuyển câu thoải mái. Khi làm đủ thì nút <b>Nộp bài</b> mới mở.</p>
        </div>
        <div class="bd">
          <div class="qhead">
            <div>
              <div class="qno" id="qNo"></div>
              <p class="question" id="qText"></p>
            </div>
          </div>

          <div class="opts" id="opts"></div>

          <div class="feedback" id="feedback">👉 Chọn đáp án.</div>

          <div class="btnrow">
            <button class="btn primary" id="checkBtn" type="button">Kiểm tra</button>
            <button class="btn" id="resetBtn" type="button">Xoá chọn</button>
            <button class="btn" id="prevBtn" type="button">Trước</button>
            <button class="btn" id="nextBtn" type="button">Tiếp</button>
          </div>
        </div>
      </section>

      <aside class="card side">
        <div class="hd">
          <h2 class="title">Tiến độ</h2>
          <p class="sub"><span id="doneCount">0</span>/<span id="totalCount">0</span> câu đã chọn</p>
        </div>
        <div class="bd">
          <div class="legend">
            <span class="tag todo">Chưa làm</span>
            <span class="tag chosen">Đã chọn</span>
            <span class="tag ok">Đúng</span>
            <span class="tag bad">Sai</span>
          </div>
          <div class="navGrid" id="navGrid"></div>
        </div>
      </aside>
    </main>
  </section>

  <section id="resultView" class="hidden">
    <div class="resultHeader">
      <h2>Kết quả bài làm</h2>      
      <div class="scoreRow">
        <div class="scoreBox" id="scoreTotal">Tổng: 0/0</div>
        <div class="scoreBox ok" id="scoreOk">Đúng: 0</div>
        <div class="scoreBox bad" id="scoreBad">Sai: 0</div>
        <div class="scoreBox warn" id="scoreTodo">Bỏ trống: 0</div>
      </div>
    </div>
    <div class="resultList" id="resultList"></div>
  </section>

  <?php endif; ?>
</div>

<script>
  const quizData = <?php echo json_encode($quizData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const total = quizData.length;

  const state = Array.from({length: total}, () => ({
    selected: null,
    locked: false,
    status: "todo"
  }));
  let current = 0;

  const qNo = document.getElementById("qNo");
  const qText = document.getElementById("qText");
  const optsBox = document.getElementById("opts");
  const feedback = document.getElementById("feedback");
  const navGrid = document.getElementById("navGrid");
  const doneCount = document.getElementById("doneCount");
  const totalCount = document.getElementById("totalCount");

  const checkBtn = document.getElementById("checkBtn");
  const resetBtn = document.getElementById("resetBtn");
  const nextBtn  = document.getElementById("nextBtn");
  const prevBtn  = document.getElementById("prevBtn");
  const submitBtn = document.getElementById("submitBtn");
  const backBtn = document.getElementById("backBtn");

  const quizView = document.getElementById("quizView");
  const resultView = document.getElementById("resultView");

  const scoreTotal = document.getElementById("scoreTotal");
  const scoreOk = document.getElementById("scoreOk");
  const scoreBad = document.getElementById("scoreBad");
  const scoreTodo = document.getElementById("scoreTodo");
  const resultList = document.getElementById("resultList");

  totalCount.textContent = total;

  function escapeHtml(s){
    return String(s)
      .replaceAll("&","&amp;")
      .replaceAll("<","&lt;")
      .replaceAll(">","&gt;")
      .replaceAll('"',"&quot;")
      .replaceAll("'","&#039;");
  }

  function computeProgress(){
    const chosen = state.filter(s=>s.selected !== null).length;
    doneCount.textContent = chosen;

    const allDone = (chosen === total);
    submitBtn.disabled = !allDone;
    submitBtn.title = allDone
      ? "Nộp bài đi"
      : `Làm đủ ${total} câu mới được nộp bài  (còn thiếu ${total - chosen} câu)`;
  }

  function renderNav(){
    navGrid.innerHTML = "";
    state.forEach((s, idx)=>{
      const b = document.createElement("button");
      b.className = `navBtn ${s.status} ${idx===current ? "active":""}`;
      b.textContent = idx+1;
      b.type = "button";
      b.addEventListener("click", ()=>goTo(idx));
      navGrid.appendChild(b);
    });
    computeProgress();
  }

  function renderQuestion(){
    const item = quizData[current];
    const s = state[current];

    qNo.textContent = `Câu ${current+1} / ${total}`;
    qText.textContent = item.q;

    optsBox.innerHTML = "";
    item.opts.forEach((op, i)=>{
      const div = document.createElement("div");
      div.className = "opt";
      div.tabIndex = 0;

      if(s.selected === i) div.classList.add("selected");

      if(s.locked){
        if(op.correct) div.classList.add("correct");
        if(s.selected === i && !op.correct) div.classList.add("wrong");
      }

      div.innerHTML = `
        <div class="key">${escapeHtml(op.k)}</div>
        <div class="txt">${escapeHtml(op.t)}</div>
      `;

      div.addEventListener("click", ()=>selectOption(i));
      div.addEventListener("keydown",(e)=>{
        if(e.key==="Enter" || e.key===" "){ e.preventDefault(); selectOption(i); }
      });

      optsBox.appendChild(div);
    });

    if(s.selected === null){
      feedback.textContent = "Chọn đáp án.";
    } else {
      feedback.innerHTML = s.locked
        ? (s.status==="ok" ? "Đúng. " + escapeHtml(item.explain || "") : "Sai. " + escapeHtml(item.explain || ""))
        : "✅ Đã chọn. Làm tiếp hoặc nộp bài khi đủ câu.";
    }

    renderNav();
  }

  function selectOption(i){
    const s = state[current];
    s.selected = i;
    s.locked = false;
    s.status = "chosen";
    renderQuestion();
  }

  function checkCurrent(){
    const s = state[current];
    if(s.selected === null){
      feedback.innerHTML = "⚠️ Chưa chọn mà đòi kiểm tra? Chọn đi nào!!!";
      return;
    }
    s.locked = true;
    const correctIndex = quizData[current].opts.findIndex(o=>o.correct);
    s.status = (s.selected === correctIndex) ? "ok" : "bad";
    renderQuestion();
  }

  function resetChoice(){
    const s = state[current];
    s.selected = null;
    s.locked = false;
    s.status = "todo";
    renderQuestion();
  }

  function goTo(idx){
    current = idx;
    renderQuestion();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }
  function next(){
    if(current < total-1){ current++; renderQuestion(); window.scrollTo({top:0,behavior:"smooth"}); }
  }
  function prev(){
    if(current > 0){ current--; renderQuestion(); window.scrollTo({top:0,behavior:"smooth"}); }
  }

  function submit(){
    // Cấm nộp nếu chưa đủ câu
    const chosen = state.filter(s=>s.selected !== null).length;
    if (chosen !== total){
      alert(`Bạn cần làm đủ ${total} câu mới được nộp bài!\nHiện tại mới làm ${chosen} câu, còn thiếu ${total - chosen} câu.`);
      return;
    }

    let ok = 0, bad = 0, todo = 0;

    state.forEach((s, idx)=>{
      const correctIndex = quizData[idx].opts.findIndex(o=>o.correct);
      if(s.selected === null){
        s.status = "todo"; todo++;
      }else{
        s.status = (s.selected === correctIndex) ? "ok" : "bad";
        if(s.status==="ok") ok++; else bad++;
      }
      s.locked = true;
    });

    scoreTotal.textContent = `Tổng: ${ok}/${total}`;
    scoreOk.textContent = `Đúng: ${ok}`;
    scoreBad.textContent = `Sai: ${bad}`;
    scoreTodo.textContent = `Bỏ trống: ${todo}`;

    resultList.innerHTML = "";

    quizData.forEach((q, idx)=>{
      const s = state[idx];
      const correctIndex = q.opts.findIndex(o=>o.correct);

      const statusLabel = s.selected===null ? "Bỏ trống" : (s.selected===correctIndex ? "Đúng" : "Sai");
      const statusClass = s.selected===null ? "todo" : (s.selected===correctIndex ? "ok" : "bad");

      const optionsHtml = q.opts.map((op, oi) => {
        const isCorrect = (oi === correctIndex);
        const isChosenWrong = (s.selected === oi && s.selected !== correctIndex);
        const cls = isCorrect ? "ok" : (isChosenWrong ? "bad" : "");
        const mark = isCorrect ? "✅" : (isChosenWrong ? "❌" : "");
        const who = (s.selected === oi) ? '<span class="youTag">(Bạn chọn)</span>' : "";
        return `
          <div class="choiceLine ${cls}">
            <div class="label">${escapeHtml(op.k)}</div>
            <div class="text">${escapeHtml(op.t)} ${who} ${mark}</div>
          </div>
        `;
      }).join("");

      const explainHtml = `
        <div class="explain"><strong>Giải thích:</strong> ${escapeHtml(q.explain || "(Chưa có)")}</div>
      `;

      const item = document.createElement("div");
      item.className = "rItem";
      item.innerHTML = `
        <div class="rTop">
          <div class="rQno">Câu ${idx+1}</div>
          <div class="rStatus ${statusClass}">${statusLabel}</div>
        </div>
        <div class="rBody">
          <p class="rQuestion">${escapeHtml(q.q)}</p>
          <div class="choiceGrid">${optionsHtml}</div>
          ${explainHtml}
        </div>
      `;
      resultList.appendChild(item);
    });

    quizView.classList.add("hidden");
    resultView.classList.remove("hidden");
    submitBtn.classList.add("hidden");
    backBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });

    // 🔥 LƯU KẾT QUẢ LÊN SERVER (ĐÚNG CHỖ: ở trong submit(), có biến ok)
    try {
      const payload = {
        action: "save_quiz_result",
        score: ok,
        total_questions: total,
        quiz_name: document.title,
        lesson_id: <?php echo (int)$lesson_id; ?>,
        item_id: <?php echo (int)$item_id; ?>
      };

      fetch("thong-ke.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })
      .then(async (res) => {
        let data = null;
        try { data = await res.json(); } catch (e) {}
        if (!res.ok) {
          console.warn("Không lưu được kết quả:", data || res.status);
          return;
        }
        console.log("Đã lưu kết quả:", data);
      })
      .catch((e) => console.warn("Lỗi khi lưu kết quả:", e));
    } catch (e) {
      console.warn("Lỗi khi lưu kết quả:", e);
    }
  }

  function backToQuiz(){
    resultView.classList.add("hidden");
    quizView.classList.remove("hidden");
    submitBtn.classList.remove("hidden");
    backBtn.classList.add("hidden");
    renderQuestion();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  checkBtn.addEventListener("click", checkCurrent);
  resetBtn.addEventListener("click", resetChoice);
  nextBtn.addEventListener("click", next);
  prevBtn.addEventListener("click", prev);
  submitBtn.addEventListener("click", submit);
  backBtn.addEventListener("click", backToQuiz);

  renderNav();
  renderQuestion();
</script>
</body>
</html>
