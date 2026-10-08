<?php
// Shared layout pieces for the Tichu tournament pages.

require_once __DIR__ . '/lib.php';

function nav_link($href, $label, $active) {
    return '<a href="' . $href . '"' . ($active === $href ? ' class="active" aria-current="page"' : '') . '>' . $label . '</a>';
}

function main_nav($active) {
    $teams = load_teams();
    $me = current_team_id();
    $out = '<nav class="topnav">';
    if ($me || is_admin()) {
        if ($me) $out .= nav_link('dashboard.php', '🏮 My Team', $active);
        $out .= nav_link('bracket.php', '🏆 Bracket', $active);
        $out .= nav_link('stats.php', '📊 Stats', $active);
        if (is_admin()) $out .= nav_link('admin.php', '⚙️ Admin', $active);
        $who = $me ? team_name($teams, $me) : 'Admin';
        $out .= '<form method="post" action="logout.php" class="inline">' . csrf_field()
              . '<button type="submit" class="navbtn">Log out <small>(' . e($who) . ')</small></button></form>';
    } else {
        $out .= nav_link('index.php', '🀄 Register', $active);
        $out .= nav_link('login.php', '🔑 Team Login', $active);
    }
    return $out . '</nav>';
}

// One match card. $canEdit shows a button to the score sheet; others get a "watch" link.
function render_match($m, $teams, $r, $i, $canEdit, $highlight = null) {
    $label = $m['a'] !== null ? team_name($teams, $m['a']) : '';
    if ($m['bye']) {
        echo '<div class="match bye" data-search="' . e($label) . '">🍵 <strong>' . e($label)
           . '</strong> goes straight through to the next round (bye).</div>';
        return;
    }
    $mine = $highlight && ($highlight === $m['a'] || $highlight === $m['b']);
    $hands = count($m['hands'] ?? []);
    $ready = $m['a'] !== null && $m['b'] !== null;
    $done = $m['winner'] !== null;
    $side = function ($id) use ($teams) {
        if ($id === null) return '<span class="tbd">To be decided</span>';
        $t = $teams[$id] ?? null;
        return e(team_name($teams, $id)) . ($t ? '<small>' . e($t['p1']) . ' &amp; ' . e($t['p2']) . '</small>' : '');
    };
    $nameA = $m['a'] !== null ? team_name($teams, $m['a']) : 'TBD';
    $nameB = $m['b'] !== null ? team_name($teams, $m['b']) : 'TBD';
    ?>
    <div class="match<?= $mine ? ' mine' : '' ?>" data-search="<?= e("$nameA $nameB") ?>">
      <div class="side<?= $done && $m['winner'] === $m['a'] ? ' won' : '' ?>"><?= $side($m['a']) ?></div>
      <div class="vs">
        <?php if ($hands): ?>
          <div class="score">
            <span class="<?= $done && $m['sa'] > $m['sb'] ? 'win' : '' ?>"><?= $m['sa'] ?></span> – <span class="<?= $done && $m['sb'] > $m['sa'] ? 'win' : '' ?>"><?= $m['sb'] ?></span>
          </div>
          <?php if (!$done): ?><div class="pending">in progress · hand <?= $hands ?></div><?php endif; ?>
        <?php else: ?>
          對<div class="pending"><?= $ready ? 'not played yet' : 'waiting for opponents' ?></div>
        <?php endif; ?>
      </div>
      <div class="side right<?= $done && $m['winner'] === $m['b'] ? ' won' : '' ?>"><?= $side($m['b']) ?></div>
      <?php if ($done): ?>
        <div class="meta">🏆 <?= e(team_name($teams, $m['winner'])) ?> wins after <?= $hands ?> hand<?= $hands === 1 ? '' : 's' ?></div>
      <?php endif; ?>
      <?php if ($ready): ?>
        <div class="match-open">
          <?php if ($canEdit && !$done): ?>
            <a class="btn small" href="match.php?r=<?= (int)$r ?>&amp;i=<?= (int)$i ?>">🀄 <?= $hands ? 'Continue score sheet' : 'Start score sheet' ?></a>
          <?php elseif ($hands || $canEdit): ?>
            <a class="link" href="match.php?r=<?= (int)$r ?>&amp;i=<?= (int)$i ?>">📜 See all hands</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

// One team row inside a bracket box.
function bracket_slot($teams, $m, $which, $me) {
    $id = $m[$which];
    $score = $m['s' . $which];
    if ($id === null) {
        $text = ($which === 'b' && $m['bye']) ? 'bye' : '—';
        return '<div class="slot empty"><span class="name">' . $text . '</span></div>';
    }
    $cls = 'slot';
    if ($m['winner'] !== null && !$m['bye']) $cls .= $m['winner'] === $id ? ' winner' : ' loser';
    if ($id === $me) $cls .= ' me';
    return '<div class="' . $cls . '" data-team="' . e(team_name($teams, $id)) . '" title="' . e(team_name($teams, $id)) . ($score !== null ? ' — ' . $score : '') . '"><span class="name">'
         . e(team_name($teams, $id)) . '</span>' . ($score !== null ? '<span class="pts">' . $score . '</span>' : '') . '</div>';
}

function bracket_box($teams, $m, $me) {
    return '<div class="bmatch">' . bracket_slot($teams, $m, 'a', $me) . bracket_slot($teams, $m, 'b', $me) . '</div>';
}

// Mirrored bracket: left half flows right, right half flows left, final in the middle.
function render_bracket($bracket, $teams, $me = null) {
    $rounds = $bracket['rounds'];
    $total = count($rounds);
    $champ = champion($bracket);
    $halves = ['left' => [], 'right' => []];
    for ($r = 0; $r < $total - 1; $r++) {
        $half = intdiv(count($rounds[$r]), 2);
        $halves['left'][$r] = array_slice($rounds[$r], 0, $half);
        $halves['right'][$r] = array_slice($rounds[$r], $half);
    }
    echo '<div class="bracket-scroll"><div class="bracket">';
    foreach (['left', 'right'] as $side) {
        if ($side === 'right') {
            $final = $rounds[$total - 1][0];
            // Trophy and champion badge hang above/below the final so it lines up with the semi-finals
            echo '<div class="bcol final"><div class="round-label"></div><div class="bcol-body"><div class="single"><div class="final-wrap">'
               . '<div class="trophy" aria-hidden="true">' . trophy_svg() . '<div class="round-label">Final</div></div>'
               . bracket_box($teams, $final, $me)
               . '<div class="champion' . ($champ ? ' crowned' : '') . '">' . ($champ ? '👑 ' . e(team_name($teams, $champ)) : 'CHAMPION') . '</div>'
               . '</div></div></div></div>';
        }
        echo '<div class="bhalf ' . $side . '">';
        foreach ($halves[$side] as $r => $matches) {
            echo '<div class="bcol"><div class="round-label">' . e(round_name($r, $total)) . '</div><div class="bcol-body">';
            if (count($matches) === 1) {
                echo '<div class="single">' . bracket_box($teams, $matches[0], $me) . '</div>';
            } else {
                foreach (array_chunk($matches, 2) as $pair) {
                    echo '<div class="pair">' . bracket_box($teams, $pair[0], $me) . bracket_box($teams, $pair[1], $me) . '</div>';
                }
            }
            echo '</div></div>';
        }
        echo '</div>';
    }
    echo '</div></div>';
}

function trophy_svg() {
    return '<svg viewBox="0 0 120 110" width="110" height="100">
      <defs><linearGradient id="tg" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#ffe6a3"/><stop offset="1" stop-color="#c98a1c"/></linearGradient></defs>
      <path d="M38 14h44v22c0 16-10 28-22 28S38 52 38 36z" fill="url(#tg)" stroke="#7a4a0c" stroke-width="2"/>
      <path d="M38 20H24c0 16 8 24 16 26M82 20h14c0 16-8 24-16 26" fill="none" stroke="url(#tg)" stroke-width="6" stroke-linecap="round"/>
      <rect x="54" y="64" width="12" height="16" fill="url(#tg)"/>
      <rect x="40" y="80" width="40" height="10" rx="2" fill="url(#tg)" stroke="#7a4a0c" stroke-width="2"/>
      <rect x="34" y="90" width="52" height="10" rx="2" fill="#8b0d14" stroke="#e8b64c" stroke-width="2"/>
      <text x="60" y="44" text-anchor="middle" font-family="Ma Shan Zheng, serif" font-size="22" fill="#8b0d14">龍</text>
      <g fill="#e8b64c"><path d="M14 60c-6-14 0-30 12-38-6 12-6 26 2 36z"/><path d="M106 60c6-14 0-30-12-38 6 12 6 26-2 36z"/></g>
    </svg>';
}

// Search box that filters elements with data-search inside $target.
function search_box($target, $placeholder) {
    echo '<div class="search"><input type="search" data-filter="' . e($target) . '" placeholder="' . e($placeholder) . '" aria-label="' . e($placeholder) . '"></div>';
}

function show_flashes() {
    foreach (take_flashes() as [$type, $msg]) {
        $big = $type === 'ok' ? '福' : '!';
        echo '<div class="alert ' . $type . '" role="status"><span class="big">' . $big . '</span><div>' . e($msg) . '</div></div>';
    }
}

function lantern($char) {
    return '<svg class="lantern" width="64" height="130" viewBox="0 0 64 130" aria-hidden="true">
      <line x1="32" y1="0" x2="32" y2="22" stroke="#e8b64c" stroke-width="2"/>
      <rect x="20" y="20" width="24" height="8" rx="2" fill="#e8b64c"/>
      <ellipse cx="32" cy="62" rx="28" ry="34" fill="#c4161c" stroke="#e8b64c" stroke-width="2"/>
      <path d="M32 28 Q12 62 32 96 M32 28 Q52 62 32 96" fill="none" stroke="#e8b64c" stroke-opacity=".6" stroke-width="1.5"/>
      <ellipse cx="32" cy="62" rx="26" ry="32" fill="url(#glow)" />
      <text x="32" y="72" text-anchor="middle" font-family="Ma Shan Zheng, serif" font-size="26" fill="#ffd98a">' . $char . '</text>
      <rect x="20" y="94" width="24" height="8" rx="2" fill="#e8b64c"/>
      <path d="M26 102 v20 M32 102 v26 M38 102 v20" stroke="#e8b64c" stroke-width="2"/>
      <defs><radialGradient id="glow"><stop offset="0" stop-color="#ffd98a" stop-opacity=".55"/><stop offset="1" stop-color="#ffd98a" stop-opacity="0"/></radialGradient></defs>
    </svg>';
}

// $compact = smaller hero for inner pages; $active = current page for the nav.
function page_head($title, $active = '', $compact = true, $wide = false) { ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · <?= e(TOURNAMENT_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700&family=Cinzel+Decorative:wght@700&family=Ma+Shan+Zheng&family=Noto+Sans:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐉</text></svg>">
</head>
<body class="<?= $compact ? 'compact' : '' ?><?= $wide ? ' wide' : '' ?>">
<div class="watermark" aria-hidden="true">龍</div>
<div class="wrap">
  <div class="lanterns" aria-hidden="true"><?= lantern('福') ?><?= lantern('龍') ?></div>
  <header class="hero">
    <svg class="dragon" viewBox="0 0 620 210" role="img" aria-label="Golden Chinese dragon chasing a pearl">
      <defs>
        <linearGradient id="gold" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#ffe6a3"/>
          <stop offset=".5" stop-color="#e8b64c"/>
          <stop offset="1" stop-color="#a8721a"/>
        </linearGradient>
        <radialGradient id="pearl">
          <stop offset="0" stop-color="#ffffff"/>
          <stop offset=".5" stop-color="#ffe9b0"/>
          <stop offset="1" stop-color="#e8b64c" stop-opacity="0"/>
        </radialGradient>
      </defs>
      <g class="body">
        <!-- clouds -->
        <g fill="none" stroke="#e8b64c" stroke-opacity=".45" stroke-width="2.5">
          <path d="M60 185c0-10 9-17 19-17 3-11 13-18 24-18 12 0 22 9 23 21 8 1 14 7 14 14"/>
          <path d="M300 30c0-8 7-13 15-13 2-9 10-14 19-14 10 0 17 7 18 16 6 1 11 6 11 11"/>
          <path d="M420 195c0-9 8-15 17-15 2-10 11-16 21-16 11 0 19 8 20 18"/>
        </g>
        <!-- tail flame -->
        <path d="M44 122 C20 110 10 90 18 70 C24 92 34 98 44 100 C30 80 34 60 46 48 C46 72 56 90 66 104 Z" fill="#c4161c" stroke="#e8b64c" stroke-width="2"/>
        <!-- dorsal spikes -->
        <path d="M50 112 C110 30 170 30 220 100 S330 175 380 105 S470 30 505 82" fill="none" stroke="#c4161c" stroke-width="40" stroke-dasharray="3 13" stroke-linecap="round"/>
        <!-- body outline + gold body -->
        <path d="M50 120 C110 40 170 40 220 108 S330 182 380 112 S470 40 505 90" fill="none" stroke="#4a0509" stroke-width="30" stroke-linecap="round"/>
        <path d="M50 120 C110 40 170 40 220 108 S330 182 380 112 S470 40 505 90" fill="none" stroke="url(#gold)" stroke-width="24" stroke-linecap="round"/>
        <!-- scales -->
        <path d="M50 120 C110 40 170 40 220 108 S330 182 380 112 S470 40 505 90" fill="none" stroke="#a8721a" stroke-width="16" stroke-dasharray="1 7" stroke-linecap="round" opacity=".8"/>
        <!-- belly line -->
        <path d="M56 128 C114 52 166 52 214 118 S334 192 386 120 S468 52 500 100" fill="none" stroke="#fff3cf" stroke-width="3" stroke-dasharray="6 5" opacity=".9"/>
        <!-- legs / claws -->
        <g stroke="#4a0509" stroke-width="2" fill="url(#gold)">
          <path d="M150 64 l-10 -28 l-8 4 l4 -10 l8 2 l2 -8 l6 8 l-2 30 z"/>
          <path d="M300 152 l6 30 l-8 2 l10 6 l4 -6 l6 4 l-2 -10 l-6 -28 z"/>
          <path d="M440 70 l-6 -28 l-8 2 l6 -9 l7 3 l3 -8 l5 9 l2 30 z"/>
        </g>
        <!-- head -->
        <g transform="translate(498 62)">
          <!-- mane -->
          <path d="M-6 30 C-24 10 -18 -10 -4 -16 C-10 0 0 6 6 8 C0 -8 8 -22 20 -26 C16 -10 22 0 30 4 Z" fill="#c4161c" stroke="#e8b64c" stroke-width="2"/>
          <!-- horns -->
          <path d="M18 6 C10 -14 0 -26 -14 -34 C2 -30 18 -18 28 2 Z" fill="url(#gold)" stroke="#4a0509" stroke-width="2"/>
          <!-- skull + snout -->
          <path d="M0 22 C2 2 26 -2 44 6 C58 10 70 12 82 14 C88 16 88 26 80 28 L56 30 C66 34 76 38 80 44 C66 48 44 46 30 40 C14 46 2 40 0 22 Z" fill="url(#gold)" stroke="#4a0509" stroke-width="2.5"/>
          <!-- teeth -->
          <path d="M56 30 l4 6 l3 -6 l4 6 l3 -6" fill="#fff" stroke="#4a0509" stroke-width="1"/>
          <!-- brow + eye -->
          <path d="M28 6 C36 2 46 4 52 10" fill="none" stroke="#c4161c" stroke-width="4" stroke-linecap="round"/>
          <ellipse cx="42" cy="15" rx="6" ry="5" fill="#fff"/>
          <circle cx="44" cy="15" r="3" fill="#c4161c"/>
          <circle cx="44" cy="15" r="1.4" fill="#000"/>
          <!-- nostril -->
          <circle cx="78" cy="19" r="2" fill="#4a0509"/>
          <!-- whiskers -->
          <path d="M80 18 C100 4 108 22 122 6" fill="none" stroke="#e8b64c" stroke-width="2.5" stroke-linecap="round"/>
          <path d="M76 42 C92 60 106 44 118 64" fill="none" stroke="#e8b64c" stroke-width="2.5" stroke-linecap="round"/>
          <!-- fire breath -->
          <path d="M84 26 C96 24 104 30 110 28 C104 34 98 34 92 34 Z" fill="#ff7a1a" opacity=".85"/>
        </g>
        <!-- pearl -->
        <circle cx="592" cy="58" r="22" fill="url(#pearl)"/>
        <circle cx="592" cy="58" r="10" fill="#fffaf0" stroke="#e8b64c" stroke-width="2"/>
      </g>
    </svg>
    <p class="cn" title="Tí chū — the Chinese origin of the name Tichu">提出</p>
    <h1><?= e(TOURNAMENT_NAME) ?></h1>
    <?php if (!$compact): ?>
    <p class="tag">Gather your partner, call your Grand Tichu, and play for the dragon's hoard.</p>
    <div class="info-row">
      <span class="chip">📅 <?= e(TOURNAMENT_DATE) ?></span>
      <span class="chip">🏮 <?= e(TOURNAMENT_PLACE) ?></span>
      <span class="chip">🀄 Teams of 2 · max <?= MAX_TEAMS ?> teams</span>
    </div>
    <?php endif; ?>
    <?= main_nav($active) ?>
  </header>
<?php }

function page_foot() { ?>
  <footer>
    <div class="cards" aria-hidden="true">龍 鳳 犬 雀</div>
    <p>Dragon · Phoenix · Dog · Mah Jong — good luck at the tables!</p>
  </footer>
</div>
<div id="confirm-modal" class="modal" hidden>
  <div class="modal-box" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title">
    <div class="modal-seal" aria-hidden="true">問</div>
    <h3 id="confirm-title">Are you sure?</h3>
    <p class="confirm-text"></p>
    <div class="modal-actions">
      <button type="button" class="btn ghost confirm-no">Cancel</button>
      <button type="button" class="btn small confirm-yes">Yes, go ahead</button>
    </div>
  </div>
</div>
<script>
// Live search: filters [data-search] items inside the target as you type
document.querySelectorAll('input[data-filter]').forEach(function (input) {
  input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    var box = document.querySelector(input.dataset.filter);
    var shown = 0;
    box.querySelectorAll('[data-search]').forEach(function (el) {
      var hit = el.dataset.search.toLowerCase().indexOf(q) !== -1;
      el.style.display = hit ? '' : 'none';
      if (hit) shown++;
    });
    var none = box.querySelector('.no-results');
    if (none) none.style.display = shown ? 'none' : 'block';
  });
});
// Bracket search: highlights matching teams instead of hiding them
document.querySelectorAll('input[data-highlight]').forEach(function (input) {
  input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    var box = document.querySelector(input.dataset.highlight);
    box.classList.toggle('searching', q !== '');
    box.querySelectorAll('[data-team]').forEach(function (el) {
      el.classList.toggle('hit', q !== '' && el.dataset.team.toLowerCase().indexOf(q) !== -1);
    });
  });
});
// Ask before dangerous admin actions. Uses an in-page box because some
// browsers (e.g. embedded app browsers) block window.confirm and treat it as "Cancel".
(function () {
  var modal = document.getElementById('confirm-modal');
  var pending = null;
  function close() { modal.hidden = true; pending = null; }
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.dataset.confirmed) return;
      ev.preventDefault();
      pending = f;
      modal.querySelector('.confirm-text').textContent = f.dataset.confirm;
      modal.hidden = false;
      modal.querySelector('.confirm-yes').focus();
    });
  });
  modal.querySelector('.confirm-yes').addEventListener('click', function () {
    var f = pending;
    close();
    if (f) { f.dataset.confirmed = '1'; f.submit(); }
  });
  modal.querySelector('.confirm-no').addEventListener('click', close);
  modal.addEventListener('click', function (ev) { if (ev.target === modal) close(); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !modal.hidden) close(); });
})();
</script>
</body>
</html>
<?php }
