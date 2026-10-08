<?php
require __DIR__ . '/partials.php';

require_login();

$teams = load_teams();
$bracket = load_bracket();
$me = current_team_id();
[$rows, $totals, $hi] = tournament_stats($bracket, $teams);

$calls = $totals['tichu_ok'] + $totals['tichu_fail'] + $totals['grand_ok'] + $totals['grand_fail'];
$rate = $calls ? round(100 * ($totals['tichu_ok'] + $totals['grand_ok']) / $calls) : null;
$name = function ($id) use ($teams) { return e(team_name($teams, $id)); };

// [column label, key, tooltip]
$cols = [
    ['W', 'won', 'Matches won'], ['L', 'lost', 'Matches lost'], ['Hands', 'hands', 'Hands played'],
    ['Points', 'for', 'Points scored'], ['+/−', 'diff', 'Points scored minus points conceded'],
    ['T ✓', 'tichu_ok', 'Successful Tichu'], ['T ✗', 'tichu_fail', 'Failed Tichu'],
    ['GT ✓', 'grand_ok', 'Successful Grand Tichu'], ['GT ✗', 'grand_fail', 'Failed Grand Tichu'],
    ['1-2', 'double', 'Double wins'], ['Call %', 'call_rate', 'Share of Tichu + Grand Tichu calls that succeeded'],
];

page_head('Statistics', 'stats.php', true, true);
?>
  <main class="scroll">
    <h2><span class="seal">計</span> Tournament Statistics</h2>
    <p class="sub">Every hand of every match, added up for the whole tournament. Updates as soon as a hand is entered.</p>

    <?php if (!$totals['hands']): ?>
      <div class="empty"><span class="big">空</span>No hands have been played yet. Statistics appear once the first hand is entered.</div>
    <?php else: ?>

    <div class="tiles-stats">
      <div class="stat"><b><?= $totals['matches'] ?></b><span>matches played</span></div>
      <div class="stat"><b><?= $totals['hands'] ?></b><span>hands played</span></div>
      <div class="stat"><b><?= $totals['tichu_ok'] ?><small> / <?= $totals['tichu_ok'] + $totals['tichu_fail'] ?></small></b><span>Tichus made</span></div>
      <div class="stat"><b><?= $totals['grand_ok'] ?><small> / <?= $totals['grand_ok'] + $totals['grand_fail'] ?></small></b><span>Grand Tichus made</span></div>
      <div class="stat"><b><?= $totals['double'] ?></b><span>double wins (1-2)</span></div>
      <div class="stat"><b><?= $rate === null ? '—' : $rate . '%' ?></b><span>calls that succeeded</span></div>
    </div>

    <div class="section">
      <h3>🏅 Highlights</h3>
      <div class="highlights">
        <?php if ($hi['best_hand']): ?>
          <div class="hl"><i>龍</i><div><small>Best single hand</small><strong><?= $name($hi['best_hand']['team']) ?></strong>
            <span>+<?= $hi['best_hand']['pts'] ?> in hand <?= $hi['best_hand']['hand'] ?> · <?= e($hi['best_hand']['round']) ?></span></div></div>
        <?php endif; ?>
        <?php if ($hi['biggest_win']): ?>
          <div class="hl"><i>勝</i><div><small>Biggest win</small><strong><?= $name($hi['biggest_win']['team']) ?></strong>
            <span><?= $hi['biggest_win']['score'] ?> vs <?= $name($hi['biggest_win']['loser']) ?> · <?= e($hi['biggest_win']['round']) ?></span></div></div>
        <?php endif; ?>
        <?php if ($hi['top_score']): ?>
          <div class="hl"><i>高</i><div><small>Highest match score</small><strong><?= $name($hi['top_score']['team']) ?></strong>
            <span><?= $hi['top_score']['pts'] ?> points · <?= e($hi['top_score']['round']) ?></span></div></div>
        <?php endif; ?>
        <?php foreach ([['most_tichus', 'tichu_ok', '鳳', 'Most Tichus made'], ['most_grands', 'grand_ok', '王', 'Most Grand Tichus made'], ['most_doubles', 'double', '雙', 'Most double wins']] as [$k, $field, $cn, $label]): ?>
          <?php if ($hi[$k]): ?>
            <div class="hl"><i><?= $cn ?></i><div><small><?= $label ?></small><strong><?= e($hi[$k]['team']) ?></strong>
              <span><?= $hi[$k][$field] ?> in <?= $hi[$k]['hands'] ?> hands</span></div></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="section">
      <h3>👥 All Teams</h3>
      <?php search_box('#stats-table', 'Search a team…'); ?>
      <p class="hint" style="margin:-4px 0 10px">Tap a column title to sort by it.</p>
      <div class="table-scroll" id="stats-table">
        <table class="teams sortable">
          <thead><tr>
            <th>#</th><th data-sort="text">Team</th>
            <?php foreach ($cols as [$label, $key, $tip]): ?><th class="n" data-sort="num" title="<?= e($tip) ?>"><?= $label ?></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $n => $row): ?>
            <tr class="<?= $row['id'] === $me ? 'me' : '' ?>" data-search="<?= e($row['team']) ?>">
              <td class="rank"><span class="num"><?= $n + 1 ?></span></td>
              <td data-v="<?= e(mb_strtolower($row['team'])) ?>"><strong><?= e($row['team']) ?></strong>
                <?php if ($row['status'] === 'champion'): ?> <span class="badge">👑 Champion</span>
                <?php elseif ($row['status'] === 'out'): ?> <span class="badge out">Out</span><?php endif; ?></td>
              <?php foreach ($cols as [, $key]): $v = $row[$key]; ?>
                <td class="n<?= $key === 'diff' ? ($v > 0 ? ' pos' : ($v < 0 ? ' neg' : '')) : '' ?>" data-v="<?= $v ?? -1 ?>">
                  <?php if ($key === 'call_rate'): ?><?= $v === null ? '—' : $v . '%' ?>
                  <?php elseif ($key === 'diff'): ?><?= $v > 0 ? '+' : '' ?><?= $v ?>
                  <?php else: ?><?= $v ?><?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="no-results">No team matches your search.</div>
      </div>
    </div>
  </main>

<script>
// Click a column title to sort; click again to flip the order.
document.querySelectorAll('table.sortable th[data-sort]').forEach(function (th) {
  th.addEventListener('click', function () {
    var table = th.closest('table'), body = table.tBodies[0];
    var col = Array.prototype.indexOf.call(th.parentNode.children, th);
    var desc = th.dataset.sort === 'num' ? th.dataset.dir !== 'desc' : th.dataset.dir === 'asc';
    table.querySelectorAll('th').forEach(function (h) { delete h.dataset.dir; });
    th.dataset.dir = desc ? 'desc' : 'asc';
    var rows = Array.prototype.slice.call(body.rows);
    rows.sort(function (x, y) {
      var a = x.cells[col].dataset.v, b = y.cells[col].dataset.v;
      var c = th.dataset.sort === 'num' ? parseFloat(a) - parseFloat(b) : a.localeCompare(b);
      return desc ? -c : c;
    });
    rows.forEach(function (r, i) { body.appendChild(r); r.querySelector('.num').textContent = i + 1; });
  });
});
</script>
<?php page_foot();
