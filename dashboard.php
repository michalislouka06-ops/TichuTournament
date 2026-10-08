<?php
require __DIR__ . '/partials.php';

require_login();
if (is_admin() && !current_team_id()) redirect('admin.php');

$me = current_team_id();
$teams = load_teams();
if (!isset($teams[$me])) {
    // Team was removed by the admin
    $_SESSION = [];
    flash('bad', 'Your team is no longer registered. Please contact the organizer.');
    redirect('login.php');
}

$bracket = load_bracket();
$myTeam = $teams[$me];
$total = $bracket ? count($bracket['rounds']) : 0;

// My path through the bracket, latest round first
$myMatches = [];
$status = null;
if ($bracket) {
    for ($r = $total - 1; $r >= 0; $r--) {
        foreach ($bracket['rounds'][$r] as $i => $m) {
            if ($m['a'] === $me || $m['b'] === $me) $myMatches[] = [$r, $i, $m];
        }
    }
    if (!$myMatches) {
        $status = 'Registered after the draw — not in this bracket';
    } else {
        [$lastR, , $last] = $myMatches[0];
        if (champion($bracket) === $me) $status = '👑 Tournament Champion!';
        elseif ($last['winner'] !== null && $last['winner'] !== $me) $status = 'Knocked out in the ' . round_name($lastR, $total);
        else $status = 'Still in — playing the ' . round_name($lastR, $total);
    }
}

page_head('My Team', 'dashboard.php');
?>
  <main class="scroll">
    <?php show_flashes(); ?>

    <div class="welcome">
      <span class="big">龍</span>
      <div>
        <h3><?= e($myTeam['team']) ?></h3>
        <p><?= e($myTeam['p1']) ?> &amp; <?= e($myTeam['p2']) ?></p>
        <?php if ($status): ?><span class="status-tag"><?= e($status) ?></span><?php endif; ?>
      </div>
    </div>

    <div class="section">
      <h3>🀄 Your Matches</h3>
      <?php if (!$bracket): ?>
        <div class="empty"><span class="big">待</span>The organizer hasn't drawn the bracket yet. Check back soon!</div>
      <?php elseif (!$myMatches): ?>
        <div class="empty"><span class="big">晚</span>Your team joined after the bracket was drawn. Talk to the organizer.</div>
      <?php else: ?>
        <?php foreach ($myMatches as [$r, $i, $m]): ?>
          <p class="hint" style="margin:14px 0 6px"><strong><?= e(round_name($r, $total)) ?></strong></p>
          <?php render_match($m, $teams, $r, $i, true, $me); ?>
        <?php endforeach; ?>
        <p class="hint">Open the score sheet and add each hand as you play it, just like iTichu. The first team to <?= TARGET_SCORE ?> wins the match and moves on in the bracket automatically. Either player of either team can enter hands.</p>
      <?php endif; ?>
      <?php if ($bracket): ?>
        <div class="actions" style="margin-top:16px"><a class="btn" href="bracket.php">🏆 View the Bracket</a></div>
      <?php endif; ?>
    </div>

    <div class="section">
      <h3>👥 All Teams (<?= count($teams) ?>)</h3>
      <?php search_box('#team-list', 'Search teams or players…'); ?>
      <div class="table-scroll" id="team-list">
        <table class="teams">
          <thead><tr><th>Team</th><th>Players</th></tr></thead>
          <tbody>
          <?php foreach ($teams as $id => $t): ?>
            <tr class="<?= $id === $me ? 'me' : '' ?>" data-search="<?= e($t['team'] . ' ' . $t['p1'] . ' ' . $t['p2']) ?>">
              <td><strong><?= e($t['team']) ?></strong><?= $id === $me ? ' <span class="badge">You</span>' : '' ?></td>
              <td><?= e($t['p1']) ?> &amp; <?= e($t['p2']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="no-results">No team matches your search.</div>
      </div>
    </div>
  </main>
<?php page_foot();
