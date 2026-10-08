<?php
require __DIR__ . '/partials.php';

require_login();

$r = (int)($_GET['r'] ?? $_POST['r'] ?? -1);
$i = (int)($_GET['i'] ?? $_POST['i'] ?? -1);
$self = "match.php?r=$r&i=$i";

$teams = load_teams();
$bracket = load_bracket();
$m = $bracket['rounds'][$r][$i] ?? null;
if (!$m || $m['bye'] || $m['a'] === null || $m['b'] === null) {
    flash('bad', 'That match is not ready to be played yet.');
    redirect(is_admin() ? 'admin.php' : 'dashboard.php');
}

$me = current_team_id();
$canEdit = is_admin() || $me === $m['a'] || $me === $m['b'];
$teamFilter = is_admin() ? null : $me;
$byLabel = is_admin() ? 'Admin' : team_name($teams, $me);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        [$hand, $err] = hand_from_post($_POST);
        if (!$err) $err = add_hand($r, $i, $hand, $byLabel, $teamFilter, (int)($_POST['expected'] ?? -1));
        if ($err) {
            flash('bad', $err);
        } else {
            [$pa, $pb] = hand_points($hand);
            flash('ok', sprintf('Hand saved: %s %+d, %s %+d.', team_name($teams, $m['a']), $pa, team_name($teams, $m['b']), $pb));
        }
    } elseif ($action === 'undo') {
        $err = undo_hand($r, $i, $teamFilter);
        flash($err ? 'bad' : 'ok', $err ?: 'Last hand removed.');
    }
    redirect($self);
}

$total = count($bracket['rounds']);
$hands = $m['hands'];
$nameA = team_name($teams, $m['a']);
$nameB = team_name($teams, $m['b']);
$sa = $m['sa'] ?? 0;
$sb = $m['sb'] ?? 0;
$finished = $m['winner'] !== null;

function call_tags($side) {
    $tags = [];
    if ($side['double']) $tags[] = '<span class="tag dbl">1-2</span>';
    if ($side['grand'] === 1) $tags[] = '<span class="tag ok">GT ✓</span>';
    if ($side['grand'] === -1) $tags[] = '<span class="tag bad">GT ✗</span>';
    if ($side['tichu'] === 1) $tags[] = '<span class="tag ok">T ✓</span>';
    if ($side['tichu'] === -1) $tags[] = '<span class="tag bad">T ✗</span>';
    return implode('', $tags);
}

page_head("$nameA vs $nameB", '');
?>
  <main class="scroll sheet">
    <p class="hint" style="margin:0 0 4px"><a class="link" href="<?= is_admin() && !$me ? 'admin.php' : 'dashboard.php' ?>">← Back</a> · <?= e(round_name($r, $total)) ?> · first to <?= TARGET_SCORE ?></p>
    <?php show_flashes(); ?>

    <div class="scoreboard">
      <?php foreach ([['a', $nameA, $sa], ['b', $nameB, $sb]] as [$t, $name, $score]): ?>
        <div class="team-total <?= $m['winner'] === $m[$t] ? 'won' : '' ?>">
          <div class="tname"><?= e($name) ?></div>
          <div class="big-score"><?= $score ?></div>
          <div class="bar"><span style="width:<?= max(0, min(100, $score / TARGET_SCORE * 100)) ?>%"></span></div>
        </div>
        <?php if ($t === 'a'): ?><div class="sb-vs">對</div><?php endif; ?>
      <?php endforeach; ?>
    </div>

    <?php if ($finished): ?>
      <div class="alert ok"><span class="big">勝</span><div><strong><?= e(team_name($teams, $m['winner'])) ?> wins <?= max($sa, $sb) ?>–<?= min($sa, $sb) ?>!</strong>
        <?= $r < $total - 1 ? 'They move on to the ' . e(round_name($r + 1, $total)) . '.' : 'They are the tournament champions! 👑' ?></div></div>
    <?php elseif (max($sa, $sb) >= TARGET_SCORE): ?>
      <div class="alert bad"><span class="big">平</span><div>Both teams are level at <?= $sa ?>. Play another hand to decide the winner.</div></div>
    <?php endif; ?>

    <?php if ($canEdit && !$finished): ?>
    <form method="post" action="<?= e($self) ?>" class="hand-form" id="hand-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="expected" value="<?= count($hands) ?>">
      <h3>Hand <?= count($hands) + 1 ?></h3>

      <div class="cards-row" id="cards-row">
        <div class="cards-side">
          <div class="clabel"><?= e($nameA) ?></div>
          <div class="stepper">
            <button type="button" class="step" data-step="-5" aria-label="5 fewer points">−</button>
            <input type="number" name="card" id="card" value="50" min="-25" max="125" step="5" inputmode="numeric" aria-label="Card points for <?= e($nameA) ?>">
            <button type="button" class="step" data-step="5" aria-label="5 more points">+</button>
          </div>
        </div>
        <div class="cards-mid">card points</div>
        <div class="cards-side">
          <div class="clabel"><?= e($nameB) ?></div>
          <div class="auto" id="card-b">50</div>
        </div>
        <input type="range" id="card-range" min="-25" max="125" step="5" value="50" aria-label="Card points slider">
        <div class="hint" style="grid-column:1/-1;text-align:center;margin:0">Card points always add up to 100. Enter one team, the other is filled in for you.</div>
      </div>

      <div class="toggles">
        <?php foreach (['tichu_ok' => 'Successful Tichu', 'tichu_fail' => 'Failed Tichu', 'grand_ok' => 'Successful Grand Tichu', 'grand_fail' => 'Failed Grand Tichu', 'double' => 'Double win (1-2)'] as $key => $label): ?>
          <div class="toggle-row">
            <label class="switch"><input type="checkbox" name="a_<?= $key ?>" value="1" data-team="a" data-key="<?= $key ?>" aria-label="<?= e("$nameA: $label") ?>"><span></span><em class="sr"><?= e("$nameA: $label") ?></em></label>
            <div class="tlabel"><?= $label ?></div>
            <label class="switch"><input type="checkbox" name="b_<?= $key ?>" value="1" data-team="b" data-key="<?= $key ?>" aria-label="<?= e("$nameB: $label") ?>"><span></span><em class="sr"><?= e("$nameB: $label") ?></em></label>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="preview" id="preview" aria-live="polite">
        <span><?= e($nameA) ?> <b id="pv-a">+50</b></span>
        <span><?= e($nameB) ?> <b id="pv-b">+50</b></span>
      </div>
      <div class="actions" style="justify-content:center">
        <button type="submit" class="btn">➕ Add hand</button>
      </div>
    </form>
    <?php elseif (!$canEdit): ?>
      <p class="hint" style="text-align:center">You're watching this match. Only the two teams playing (or the organizer) can enter hands.</p>
    <?php endif; ?>

    <div class="section">
      <div class="round-title">
        <h3 style="flex:1">📜 Detailed score</h3>
        <?php if ($canEdit && $hands): ?>
          <form method="post" action="<?= e($self) ?>" data-confirm="Remove the last hand (hand <?= count($hands) ?>)?">
            <?= csrf_field() ?><input type="hidden" name="action" value="undo">
            <button type="submit" class="btn ghost">↩ Undo last hand</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if (!$hands): ?>
        <div class="empty"><span class="big">始</span>No hands played yet.</div>
      <?php else: ?>
        <div class="table-scroll">
          <table class="teams hands">
            <thead><tr><th>#</th><th class="n"><?= e($nameA) ?></th><th class="n"><?= e($nameB) ?></th></tr></thead>
            <tbody>
            <?php
              $runA = $runB = 0;
              $rows = [];
              foreach ($hands as $n => $h) {
                  [$pa, $pb] = hand_points($h);
                  $runA += $pa; $runB += $pb;
                  $rows[] = [$n + 1, $h, $pa, $pb, $runA, $runB];
              }
              foreach (array_reverse($rows) as [$n, $h, $pa, $pb, $ta, $tb]):
            ?>
              <tr>
                <td><span class="num"><?= $n ?></span><div class="hint"><?= e($h['at']) ?></div></td>
                <td class="n"><span class="<?= $pa < 0 ? 'neg' : 'pos' ?>"><?= sprintf('%+d', $pa) ?></span><div class="run"><?= $ta ?></div><?= call_tags($h['a']) ?></td>
                <td class="n"><span class="<?= $pb < 0 ? 'neg' : 'pos' ?>"><?= sprintf('%+d', $pb) ?></span><div class="run"><?= $tb ?></div><?= call_tags($h['b']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <p class="hint" style="text-align:center;margin-top:20px"><a class="link" href="stats.php">📊 See statistics for the whole tournament →</a></p>
  </main>

<?php if ($canEdit && !$finished): ?>
<script>
// Live score-sheet helpers: card points mirror, exclusive switches and a preview of the hand.
(function () {
  var form = document.getElementById('hand-form');
  var card = document.getElementById('card');
  var range = document.getElementById('card-range');
  var cardB = document.getElementById('card-b');
  var row = document.getElementById('cards-row');
  var box = function (t, k) { return form.querySelector('input[name="' + t + '_' + k + '"]'); };

  function clamp(v) { v = Math.round((parseInt(v, 10) || 0) / 5) * 5; return Math.max(-25, Math.min(125, v)); }
  function setCard(v) { v = clamp(v); card.value = v; range.value = v; update(); }

  function update() {
    var dbl = box('a', 'double').checked ? 'a' : (box('b', 'double').checked ? 'b' : null);
    row.classList.toggle('disabled', !!dbl);
    card.disabled = range.disabled = !!dbl;
    var c = clamp(card.value);
    cardB.textContent = dbl ? '—' : (100 - c);
    var pts = { a: dbl ? (dbl === 'a' ? 200 : 0) : c, b: dbl ? (dbl === 'b' ? 200 : 0) : 100 - c };
    ['a', 'b'].forEach(function (t) {
      if (box(t, 'tichu_ok').checked) pts[t] += 100;
      if (box(t, 'tichu_fail').checked) pts[t] -= 100;
      if (box(t, 'grand_ok').checked) pts[t] += 200;
      if (box(t, 'grand_fail').checked) pts[t] -= 200;
    });
    document.getElementById('pv-a').textContent = (pts.a >= 0 ? '+' : '') + pts.a;
    document.getElementById('pv-b').textContent = (pts.b >= 0 ? '+' : '') + pts.b;
  }

  form.querySelectorAll('.step').forEach(function (b) {
    b.addEventListener('click', function () { setCard(clamp(card.value) + parseInt(b.dataset.step, 10)); });
  });
  card.addEventListener('input', function () { range.value = clamp(card.value); update(); });
  card.addEventListener('change', function () { setCard(card.value); });
  range.addEventListener('input', function () { card.value = range.value; update(); });

  // Switch rules: made/failed are exclusive, only one success per hand, one double win
  form.querySelectorAll('.switch input').forEach(function (cb) {
    cb.addEventListener('change', function () {
      if (cb.checked) {
        var t = cb.dataset.team, o = t === 'a' ? 'b' : 'a', k = cb.dataset.key;
        var pairs = { tichu_ok: 'tichu_fail', tichu_fail: 'tichu_ok', grand_ok: 'grand_fail', grand_fail: 'grand_ok' };
        if (pairs[k]) box(t, pairs[k]).checked = false;
        if (k === 'tichu_ok' || k === 'grand_ok') {
          ['tichu_ok', 'grand_ok'].forEach(function (s) { if (s !== k) box(t, s).checked = false; box(o, s).checked = false; });
          box(o, 'double').checked = false;
        }
        if (k === 'double') {
          box(o, 'double').checked = false;
          box(o, 'tichu_ok').checked = false;
          box(o, 'grand_ok').checked = false;
        }
      }
      update();
    });
  });
  update();
})();
</script>
<?php endif; ?>
<?php page_foot();
